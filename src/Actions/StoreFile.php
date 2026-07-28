<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Actions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Data\FileMetadata;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\DisallowedMimeType;
use Mattmy\FileMagic\Exceptions\FileRecordFailed;
use Mattmy\FileMagic\Exceptions\FileRecoveryFailed;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\PendingFile;
use Mattmy\FileMagic\Sources\RemoteFileSource;
use Mattmy\FileMagic\Support\ExtensionResolver;
use Mattmy\FileMagic\Support\FileInspector;
use Mattmy\FileMagic\Support\ImageProcessor;
use Mattmy\FileMagic\Support\OverwriteBackup;
use Mattmy\FileMagic\Support\OverwriteBackupFactory;
use Mattmy\FileMagic\Support\PathNormalizer;
use Throwable;

final readonly class StoreFile
{
    private const string LOCATION_HASH_ALGORITHM = 'sha256';

    /**
     * Create the file storage action.
     */
    public function __construct(
        private Config $config,
        private FilesystemFactory $filesystems,
        private FileInspector $inspector,
        private ExtensionResolver $extensions,
        private PathNormalizer $paths,
        private ImageProcessor $images,
        private OverwriteBackupFactory $overwriteBackups,
    ) {}

    /**
     * Validate, store and persist a pending file.
     */
    public function execute(PendingFile $pending): StoredFile
    {
        $source = $pending->source();
        $metadata = $this->inspector->inspect($source, $this->checksumAlgorithm());

        if ($pending->imageOptions() !== null) {
            $source = $this->images->process($source, $metadata->mimeType, $pending->imageOptions());
            $metadata = $this->inspector->inspect($source, $this->checksumAlgorithm());
        }

        $this->validate($pending, $metadata);

        $diskName = $pending->disk() ?? (string) $this->config->get('file-magic.disk', 'local');
        $directory = $this->paths->directory(
            $pending->directory() ?? (string) $this->config->get('file-magic.directory', 'files'),
        );
        $filename = $this->paths->filename($pending->filename() ?? Str::ulid()->toString());
        $extension = $this->extensions->resolve($metadata->mimeType);
        $visibility = $pending->fileVisibility() ?? FileVisibility::from(
            (string) $this->config->get('file-magic.visibility', FileVisibility::Private->value),
        );
        $policy = $pending->collisionPolicy() ?? CollisionPolicy::from(
            (string) $this->config->get('file-magic.collision', CollisionPolicy::Unique->value),
        );
        $disk = $this->filesystems->disk($diskName);
        $path = "{$directory}/{$filename}.{$extension}";
        $path = $this->resolveCollision($diskName, $path, $policy);
        $filename = \pathinfo($path, PATHINFO_FILENAME);
        $pathExisted = $disk->exists($path);
        $backup = $this->createOverwriteBackup($disk, $path, $policy, $pathExisted);

        try {
            try {
                $this->writeFile($disk, $diskName, $path, $source, $visibility);
            } catch (Throwable $exception) {
                $this->recoverOrDelete($disk, $path, $pathExisted, $backup, $exception);

                throw $exception instanceof FileWriteFailed
                    ? $exception
                    : new FileWriteFailed(
                        "The file could not be written to disk [{$diskName}].",
                        previous: $exception,
                    );
            }

            try {
                return $this->createRecord(
                    $pending,
                    $metadata,
                    $diskName,
                    $path,
                    $filename,
                    $extension,
                    $visibility,
                    $policy,
                );
            } catch (Throwable $exception) {
                $this->recoverOrDelete($disk, $path, $pathExisted, $backup, $exception);

                throw new FileRecordFailed(
                    'The database record could not be persisted after writing the file.',
                    previous: $exception,
                );
            }
        } finally {
            $backup?->close();
        }
    }

    /**
     * Create a restorable backup only when overwriting an existing object.
     */
    private function createOverwriteBackup(
        Filesystem $filesystem,
        string $path,
        CollisionPolicy $policy,
        bool $pathExisted,
    ): ?OverwriteBackup {
        if ($policy !== CollisionPolicy::Overwrite || $pathExisted === false) {
            return null;
        }

        return $this->overwriteBackups->create($filesystem, $path);
    }

    /**
     * Write a source stream to its resolved storage path.
     */
    private function writeFile(
        Filesystem $filesystem,
        string $disk,
        string $path,
        FileSource $source,
        FileVisibility $visibility,
    ): void {
        $stream = $source->openStream();

        try {
            $written = $filesystem->put($path, $stream, ['visibility' => $visibility->value]);
        } finally {
            \fclose($stream);
        }

        if ($written === false) {
            throw new FileWriteFailed("The file could not be written to disk [{$disk}].");
        }
    }

    /**
     * Restore an overwritten object or remove a newly written object.
     */
    private function recoverOrDelete(
        Filesystem $filesystem,
        string $path,
        bool $pathExisted,
        ?OverwriteBackup $backup,
        Throwable $operationFailure,
    ): void {
        if ($backup instanceof OverwriteBackup) {
            $this->restoreOverwrite($filesystem, $path, $backup, $operationFailure);

            return;
        }

        if ($pathExisted === false) {
            $filesystem->delete($path);
        }
    }

    /**
     * Restore an overwrite backup and preserve both failures when recovery fails.
     */
    private function restoreOverwrite(
        Filesystem $filesystem,
        string $path,
        OverwriteBackup $backup,
        Throwable $operationFailure,
    ): void {
        try {
            $backup->restore($filesystem, $path);
        } catch (Throwable $recoveryFailure) {
            throw new FileRecoveryFailed(
                "The original file could not be restored after an overwrite failure at [{$path}].",
                $operationFailure,
                $recoveryFailure,
            );
        }
    }

    /**
     * Validate file size and trusted MIME type.
     */
    private function validate(PendingFile $pending, FileMetadata $metadata): void
    {
        $maximumSize = $pending->maximumSize() ?? (int) $this->config->get('file-magic.max_size', 104857600);

        if ($metadata->size > $maximumSize) {
            throw new FileTooLarge("The file exceeds the {$maximumSize} byte limit.");
        }

        if (
            $pending->source() instanceof RemoteFileSource &&
            $pending->source()->allowsHtml() === false &&
            \in_array($metadata->mimeType, ['text/html', 'application/xhtml+xml'], true)
        ) {
            throw new DisallowedMimeType("The remote MIME type [{$metadata->mimeType}] is not allowed.");
        }

        $allowed = $pending->allowedMimeTypes() ?? $this->stringList('file-magic.allowed_mime_types');
        $blocked = $pending->blockedMimeTypes() ?? $this->stringList('file-magic.blocked_mime_types');

        if (
            ($allowed !== [] && \in_array($metadata->mimeType, $allowed, true) === false) ||
            \in_array($metadata->mimeType, $blocked, true)
        ) {
            throw new DisallowedMimeType("The MIME type [{$metadata->mimeType}] is not allowed.");
        }
    }

    /**
     * Resolve a safe path according to the selected collision policy.
     */
    private function resolveCollision(string $disk, string $path, CollisionPolicy $policy): string
    {
        $filesystem = $this->filesystems->disk($disk);

        if ($filesystem->exists($path) === false || $policy === CollisionPolicy::Overwrite) {
            return $path;
        }

        if ($policy === CollisionPolicy::Error) {
            throw new FileWriteFailed("A file already exists at [{$path}].");
        }

        $extension = \pathinfo($path, PATHINFO_EXTENSION);
        $basename = \pathinfo($path, PATHINFO_FILENAME);
        $directory = \pathinfo($path, PATHINFO_DIRNAME);

        do {
            $uniquePath = "{$directory}/{$basename}-" . Str::lower(Str::random(12)) . ".{$extension}";
        } while ($filesystem->exists($uniquePath));

        return $uniquePath;
    }

    /**
     * Create the configured Eloquent file record.
     */
    private function createRecord(
        PendingFile $pending,
        FileMetadata $metadata,
        string $disk,
        string $path,
        string $filename,
        string $extension,
        FileVisibility $visibility,
        CollisionPolicy $policy,
    ): StoredFile {
        $modelClass = $this->config->get('file-magic.model', StoredFile::class);
        $locationHash = $this->locationHash($disk, $path);

        if (\is_string($modelClass) === false || \is_a($modelClass, StoredFile::class, true) === false) {
            throw new FileRecordFailed('The configured model must extend StoredFile.');
        }

        /** @var StoredFile|null $file */
        $file = $policy === CollisionPolicy::Overwrite
            ? $modelClass::query()->where('location_hash', $locationHash)->first()
            : null;
        $file ??= new $modelClass();
        $file->fill([
            'disk' => $disk,
            'path' => $path,
            'location_hash' => $locationHash,
            'filename' => $filename,
            'original_filename' => $this->originalFilename($pending->source()),
            'extension' => $extension,
            'mime_type' => $metadata->mimeType,
            'size' => $metadata->size,
            'checksum' => $metadata->checksum,
            'visibility' => $visibility,
            'metadata' => $pending->metadata(),
        ]);

        if ($file->exists === false) {
            $file->uuid = (string) Str::uuid();
        }

        if ($pending->owner() instanceof Model) {
            $file->owner()->associate($pending->owner());
        }

        $file->saveOrFail();

        return $file;
    }

    /**
     * Create the stable identity for a filesystem disk and path pair.
     */
    private function locationHash(string $disk, string $path): string
    {
        return \hash(self::LOCATION_HASH_ALGORITHM, $disk . "\0" . $path);
    }

    /**
     * Return a safe display-only original filename.
     */
    private function originalFilename(FileSource $source): ?string
    {
        $originalFilename = $source->originalFilename();

        if ($originalFilename === null) {
            return null;
        }

        $basename = \basename(\str_replace('\\', '/', $originalFilename));
        $basename = \preg_replace('/[\x00-\x1F\x7F]/u', '', $basename);

        return $basename === null || $basename === '' ? null : \mb_substr($basename, 0, 255);
    }

    /**
     * Return a validated list of string configuration values.
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = $this->config->get($key, []);

        if (\is_array($values) === false) {
            return [];
        }

        return \array_values(\array_filter($values, '\is_string'));
    }

    /**
     * Return a supported checksum algorithm.
     */
    private function checksumAlgorithm(): string
    {
        $algorithm = (string) $this->config->get('file-magic.checksum_algorithm', 'sha256');

        return \in_array($algorithm, \hash_algos(), true) ? $algorithm : 'sha256';
    }
}
