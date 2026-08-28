<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Actions;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Mattmy\FileMagic\Exceptions\FileRecordFailed;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Exceptions\PartialFileDeletion;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\CollisionLock;
use Mattmy\FileMagic\Support\StoredFileModelResolver;
use Throwable;

final readonly class DeleteFiles
{
    /**
     * Create the consistent batch deletion action.
     */
    public function __construct(
        private FilesystemFactory $filesystems,
        private StoredFileModelResolver $models,
        private CollisionLock $locks,
    ) {}

    /**
     * Delete physical objects in disk batches, then delete confirmed records.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    public function execute(Collection $files): int
    {
        if ($files->isEmpty()) {
            return 0;
        }

        $snapshots = $this->snapshots($files);
        [$filesystems, $filesystemFailures] = $this->resolveFilesystems($snapshots);

        try {
            return $this->locks->runMany(
                \array_map(
                    static fn (array $snapshot): array => [
                        'disk' => $snapshot['disk'],
                        'path' => $snapshot['path'],
                    ],
                    $snapshots,
                ),
                fn (): int => $this->executeLocked(
                    $snapshots,
                    $filesystems,
                    $filesystemFailures,
                ),
            );
        } catch (FileWriteFailed $exception) {
            throw new PartialFileDeletion(
                'The files could not be locked for deletion.',
                0,
                $this->modelKeys($files),
                $exception,
            );
        }
    }

    /**
     * Revalidate locked records and run the existing disk-batched deletion algorithm.
     *
     * @param  list<array{key: int|string, disk: string, path: string, location_hash: string, file: StoredFile}>  $snapshots
     * @param  array<string, Filesystem>  $filesystems
     * @param  array<string, Throwable>  $filesystemFailures
     */
    private function executeLocked(
        array $snapshots,
        array $filesystems,
        array $filesystemFailures,
    ): int {
        [$files, $changed, $identityFailure] = $this->revalidate($snapshots);
        $confirmed = $this->emptyFileCollection();
        $failed = $changed;
        $firstFailure = $identityFailure;

        foreach ($files->groupBy('disk') as $disk => $diskFiles) {
            $disk = (string) $disk;

            if (isset($filesystemFailures[$disk])) {
                $failed->push(...$diskFiles->all());
                $firstFailure ??= $filesystemFailures[$disk];

                continue;
            }

            $filesystem = $filesystems[$disk];
            $failure = $this->deleteDiskFiles($filesystem, $diskFiles);

            if ($failure === null) {
                $confirmed->push(...$diskFiles->all());

                continue;
            }

            $firstFailure ??= $failure;
            $this->reconcileDiskFiles($filesystem, $diskFiles, $confirmed, $failed, $firstFailure);
        }

        $deletedCount = $this->deleteRecords($confirmed);

        if ($failed->isNotEmpty()) {
            throw new PartialFileDeletion(
                'One or more files could not be deleted.',
                $deletedCount,
                $this->modelKeys($failed),
                $firstFailure,
            );
        }

        return $deletedCount;
    }

    /**
     * Capture and validate immutable deletion identities before resolving external services.
     *
     * @param  Collection<int, StoredFile>  $files
     * @return list<array{key: int|string, disk: string, path: string, location_hash: string, file: StoredFile}>
     */
    private function snapshots(Collection $files): array
    {
        return \array_values($files->map(function (StoredFile $file): array {
            $key = $this->modelKey($file);
            $disk = $file->getAttribute('disk');
            $path = $file->getAttribute('path');
            $locationHash = $file->getAttribute('location_hash');

            if (
                \is_string($disk) === false ||
                $disk === '' ||
                \is_string($path) === false ||
                $path === '' ||
                \is_string($locationHash) === false ||
                $locationHash !== \hash('sha256', $disk . "\0" . $path)
            ) {
                throw new FileRecordFailed('A stored-file record has an invalid deletion identity.');
            }

            return [
                'key' => $key,
                'disk' => $disk,
                'path' => $path,
                'location_hash' => $locationHash,
                'file' => $file,
            ];
        })->all());
    }

    /**
     * Resolve every referenced disk before acquiring mutation locks.
     *
     * @param  list<array{key: int|string, disk: string, path: string, location_hash: string, file: StoredFile}>  $snapshots
     * @return array{array<string, Filesystem>, array<string, Throwable>}
     */
    private function resolveFilesystems(array $snapshots): array
    {
        $filesystems = [];
        $failures = [];

        foreach ($snapshots as $snapshot) {
            $disk = $snapshot['disk'];

            if (isset($filesystems[$disk]) || isset($failures[$disk])) {
                continue;
            }

            try {
                $filesystems[$disk] = $this->filesystems->disk($disk);
            } catch (Throwable $exception) {
                $failures[$disk] = $exception;
            }
        }

        return [$filesystems, $failures];
    }

    /**
     * Reload locked identities and separate current, missing and changed records.
     *
     * @param  list<array{key: int|string, disk: string, path: string, location_hash: string, file: StoredFile}>  $snapshots
     * @return array{Collection<int, StoredFile>, Collection<int, StoredFile>, ?Throwable}
     */
    private function revalidate(array $snapshots): array
    {
        $modelClass = $this->models->resolve();
        $model = new $modelClass();
        $keys = \array_column($snapshots, 'key');

        try {
            $current = $model->newQueryWithoutScopes()
                ->whereKey($keys)
                ->get([$model->getKeyName(), 'disk', 'path', 'location_hash']);
        } catch (Throwable $exception) {
            throw new FileRecordFailed(
                'Stored-file records could not be revalidated before deletion.',
                previous: $exception,
            );
        }

        $byKey = [];

        foreach ($current as $file) {
            $byKey[$this->keyIdentity($file->getKey())] = $file;
        }

        $valid = $this->emptyFileCollection();
        $changed = $this->emptyFileCollection();
        $identityFailure = null;

        foreach ($snapshots as $snapshot) {
            $file = $byKey[$this->keyIdentity($snapshot['key'])] ?? null;

            if ($file === null) {
                continue;
            }

            if (
                $file->getAttribute('disk') !== $snapshot['disk'] ||
                $file->getAttribute('path') !== $snapshot['path'] ||
                $file->getAttribute('location_hash') !== $snapshot['location_hash']
            ) {
                $changed->push($snapshot['file']);
                $identityFailure ??= new FileRecordFailed(
                    'A stored-file record changed while waiting for its deletion lock.',
                );

                continue;
            }

            $valid->push($file);
        }

        return [$valid, $changed, $identityFailure];
    }

    /**
     * Preserve integer and string primary keys as distinct map identities.
     */
    private function keyIdentity(mixed $key): string
    {
        if (\is_int($key)) {
            return "integer:{$key}";
        }

        if (\is_string($key) && $key !== '') {
            return "string:{$key}";
        }

        throw new FileRecordFailed('A stored-file record has an unsupported primary key.');
    }

    /**
     * Delete one disk group and return its failure when completion is uncertain.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    private function deleteDiskFiles(Filesystem $filesystem, Collection $files): ?Throwable
    {
        try {
            return $filesystem->delete($this->paths($files))
                ? null
                : new FileWriteFailed('One or more files could not be deleted from a disk.');
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    /**
     * Classify every object after a disk-level deletion failure.
     *
     * @param  Collection<int, StoredFile>  $files
     * @param  Collection<int, StoredFile>  $confirmed
     * @param  Collection<int, StoredFile>  $failed
     */
    private function reconcileDiskFiles(
        Filesystem $filesystem,
        Collection $files,
        Collection $confirmed,
        Collection $failed,
        ?Throwable &$firstFailure,
    ): void {
        foreach ($files as $file) {
            try {
                $exists = $filesystem->exists($file->path);
            } catch (Throwable $exception) {
                $failed->push($file);
                $firstFailure ??= $exception;

                continue;
            }

            ($exists ? $failed : $confirmed)->push($file);
        }
    }

    /**
     * Delete all records whose physical objects are confirmed missing.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    private function deleteRecords(Collection $files): int
    {
        if ($files->isEmpty()) {
            return 0;
        }

        $keys = $this->modelKeys($files);
        $modelClass = $this->models->resolve();
        $model = new $modelClass();

        try {
            $deleted = $model->newQueryWithoutScopes()
                ->whereKey($keys)
                ->delete();
        } catch (Throwable $exception) {
            throw new FileRecordFailed(
                'Confirmed deleted files could not be removed from the database.',
                previous: $exception,
            );
        }

        if ($deleted !== \count($keys)) {
            throw new FileRecordFailed(
                'The number of deleted file records did not match the confirmed objects.',
            );
        }

        return $deleted;
    }

    /**
     * Return every storage path in a disk group.
     *
     * @param  Collection<int, StoredFile>  $files
     * @return list<string>
     */
    private function paths(Collection $files): array
    {
        return \array_values(
            $files
                ->map(static fn (StoredFile $file): string => $file->path)
                ->all(),
        );
    }

    /**
     * Return every model key in collection order.
     *
     * @param  Collection<int, StoredFile>  $files
     * @return list<int|string>
     */
    private function modelKeys(Collection $files): array
    {
        return \array_values(
            $files
                ->map(fn (StoredFile $file): int|string => $this->modelKey($file))
                ->all(),
        );
    }

    /**
     * Return a supported non-empty model key.
     */
    private function modelKey(StoredFile $file): int|string
    {
        $key = $file->getKey();

        if (\is_int($key) || (\is_string($key) && $key !== '')) {
            return $key;
        }

        throw new FileRecordFailed('A stored-file record has an unsupported primary key.');
    }

    /**
     * Create an empty collection that accepts stored file models.
     *
     * @return Collection<int, StoredFile>
     */
    private function emptyFileCollection(): Collection
    {
        return new Collection();
    }
}
