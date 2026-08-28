<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Actions;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\SizeLimitedFileSource;
use Mattmy\FileMagic\Data\FileMetadata;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\DisallowedMimeType;
use Mattmy\FileMagic\Exceptions\FileRecordFailed;
use Mattmy\FileMagic\Exceptions\FileRecoveryFailed;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\PendingFile;
use Mattmy\FileMagic\Sources\RemoteFileSource;
use Mattmy\FileMagic\Support\CollisionLock;
use Mattmy\FileMagic\Support\ExtensionResolver;
use Mattmy\FileMagic\Support\FileInspector;
use Mattmy\FileMagic\Support\FileMagicConfig;
use Mattmy\FileMagic\Support\ImageProcessor;
use Mattmy\FileMagic\Support\OverwriteBackup;
use Mattmy\FileMagic\Support\OverwriteBackupFactory;
use Mattmy\FileMagic\Support\PathNormalizer;
use Mattmy\FileMagic\Support\StoredFileModelResolver;
use Throwable;

final readonly class StoreFile
{
    private const string LOCATION_HASH_ALGORITHM = 'sha256';

    /**
     * Create the file storage action.
     */
    public function __construct(
        private FilesystemFactory $filesystems,
        private FileInspector $inspector,
        private ExtensionResolver $extensions,
        private PathNormalizer $paths,
        private ImageProcessor $images,
        private OverwriteBackupFactory $overwriteBackups,
        private CollisionLock $collisionLock,
        private StoredFileModelResolver $models,
        private FileMagicConfig $fileMagicConfig,
    ) {}

    /**
     * Validate, store and persist a pending file.
     */
    public function execute(PendingFile $pending): StoredFile
    {
        $configuredDisk = $pending->disk() === null;
        $diskName = $pending->disk() ?? $this->fileMagicConfig->disk();
        $directory = $this->paths->directory(
            $pending->directory() ?? $this->fileMagicConfig->directory(),
        );
        $filename = $this->paths->filename($pending->filename() ?? Str::ulid()->toString());
        $visibility = $pending->fileVisibility() ?? $this->fileMagicConfig->visibility();
        $policy = $pending->collisionPolicy() ?? $this->fileMagicConfig->collisionPolicy();
        $maximumSize = $pending->maximumSize() ?? $this->fileMagicConfig->maximumSize();
        $allowedMimeTypes = $pending->allowedMimeTypes() ?? $this->fileMagicConfig->allowedMimeTypes();
        $blockedMimeTypes = $pending->blockedMimeTypes() ?? $this->fileMagicConfig->blockedMimeTypes();
        $checksumAlgorithm = $this->fileMagicConfig->checksumAlgorithm();
        $modelClass = $this->models->resolve();
        $model = new $modelClass;
        $source = $pending->source();
        $disk = $this->resolveDisk($diskName, $configuredDisk ? 'file-magic.disk' : 'onDisk');

        if ($source instanceof SizeLimitedFileSource) {
            $source->limitSize($maximumSize);
        }

        $originalSnapshot = null;
        $processedSnapshot = null;

        try {
            $originalSnapshot = $this->inspector->capture(
                $source,
                $checksumAlgorithm,
                $maximumSize,
            );
            $source = $originalSnapshot;
            $metadata = $source->metadata();
            $this->validate($pending, $metadata, $maximumSize, $allowedMimeTypes, $blockedMimeTypes);

            if ($pending->imageOptions() !== null) {
                $processedSource = $this->images->process(
                    $source,
                    $metadata->mimeType,
                    $pending->imageOptions(),
                );

                if ($processedSource !== $source) {
                    $processedSnapshot = $this->inspector->capture(
                        $processedSource,
                        $checksumAlgorithm,
                        $maximumSize,
                    );
                    $source = $processedSnapshot;
                    $metadata = $source->metadata();
                    $this->validate($pending, $metadata, $maximumSize, $allowedMimeTypes, $blockedMimeTypes);
                }
            }

            $extension = $this->extensions->resolve($metadata->mimeType);
            $requestedPath = $directory === ''
                ? "{$filename}.{$extension}"
                : "{$directory}/{$filename}.{$extension}";

            $path = $requestedPath;

            while (true) {
                $storedFile = $this->collisionLock->run(
                    $diskName,
                    $path,
                    fn (): StoredFile|false => $this->storeAtPath(
                        $pending,
                        $metadata,
                        $source,
                        $disk,
                        $diskName,
                        $path,
                        $extension,
                        $visibility,
                        $policy,
                        $model,
                    ),
                );

                if ($storedFile instanceof StoredFile) {
                    return $storedFile;
                }

                $path = $this->uniquePath($requestedPath);
            }
        } finally {
            $processedSnapshot?->release();
            $originalSnapshot?->release();
        }
    }

    /**
     * Store one candidate while its disk and path lock is held.
     */
    private function storeAtPath(
        PendingFile $pending,
        FileMetadata $metadata,
        FileSource $source,
        Filesystem $disk,
        string $diskName,
        string $path,
        string $extension,
        FileVisibility $visibility,
        CollisionPolicy $policy,
        StoredFile $model,
    ): StoredFile|false {
        $pathExisted = $disk->exists($path);

        if ($pathExisted && $policy === CollisionPolicy::Unique) {
            return false;
        }

        if ($pathExisted && $policy === CollisionPolicy::Error) {
            throw new FileWriteFailed("A file already exists at [{$path}].");
        }

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
                    $source,
                    $diskName,
                    $path,
                    \pathinfo($path, PATHINFO_FILENAME),
                    $extension,
                    $visibility,
                    $policy,
                    $model,
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
            try {
                if ($filesystem->delete($path) === false) {
                    throw new FileWriteFailed('The newly written file could not be removed during recovery.');
                }
            } catch (Throwable $recoveryFailure) {
                throw new FileRecoveryFailed(
                    'The newly written file could not be removed after an operation failure.',
                    $operationFailure,
                    $recoveryFailure,
                );
            }
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
     *
     * @param  list<string>  $allowedMimeTypes
     * @param  list<string>  $blockedMimeTypes
     */
    private function validate(
        PendingFile $pending,
        FileMetadata $metadata,
        int $maximumSize,
        array $allowedMimeTypes,
        array $blockedMimeTypes,
    ): void {
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

        if (
            ($allowedMimeTypes !== [] && \in_array($metadata->mimeType, $allowedMimeTypes, true) === false) ||
            \in_array($metadata->mimeType, $blockedMimeTypes, true)
        ) {
            throw new DisallowedMimeType("The MIME type [{$metadata->mimeType}] is not allowed.");
        }
    }

    /**
     * Add a random collision suffix to the original requested path.
     */
    private function uniquePath(string $path): string
    {
        $extension = \pathinfo($path, PATHINFO_EXTENSION);
        $basename = \pathinfo($path, PATHINFO_FILENAME);
        $directory = \pathinfo($path, PATHINFO_DIRNAME);

        $uniqueName = "{$basename}-".Str::lower(Str::random(12)).".{$extension}";

        return $directory === '.' ? $uniqueName : "{$directory}/{$uniqueName}";
    }

    /**
     * Resolve a configured filesystem disk before materializing the source.
     */
    private function resolveDisk(string $disk, string $option): Filesystem
    {
        try {
            return $this->filesystems->disk($disk);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfiguration(
                "The [{$option}] value must resolve to a configured filesystem disk.",
                previous: $exception,
            );
        }
    }

    /**
     * Create the configured Eloquent file record.
     */
    private function createRecord(
        PendingFile $pending,
        FileMetadata $metadata,
        FileSource $source,
        string $disk,
        string $path,
        string $filename,
        string $extension,
        FileVisibility $visibility,
        CollisionPolicy $policy,
        StoredFile $model,
    ): StoredFile {
        $locationHash = $this->locationHash($disk, $path);

        /** @var StoredFile|null $file */
        $file = $policy === CollisionPolicy::Overwrite
            ? $model->newQueryWithoutScopes()->where('location_hash', $locationHash)->first()
            : null;
        $file ??= $model;
        $file->fill([
            'disk' => $disk,
            'path' => $path,
            'location_hash' => $locationHash,
            'filename' => $filename,
            'original_filename' => $this->originalFilename($source),
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
        return \hash(self::LOCATION_HASH_ALGORITHM, $disk."\0".$path);
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
}
