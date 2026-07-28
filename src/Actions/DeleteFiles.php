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

        $confirmed = $this->emptyFileCollection();
        $failed = $this->emptyFileCollection();
        $firstFailure = null;

        foreach ($files->groupBy('disk') as $disk => $diskFiles) {
            try {
                $filesystem = $this->filesystems->disk((string) $disk);
            } catch (Throwable $exception) {
                $failed->push(...$diskFiles->all());
                $firstFailure ??= $exception;

                continue;
            }

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
                ->map(static fn (StoredFile $file): int|string => $file->getKey())
                ->all(),
        );
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
