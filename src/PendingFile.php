<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use Illuminate\Database\Eloquent\Model;
use Mattmy\FileMagic\Actions\StoreFile;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\ReleasableFileSource;
use Mattmy\FileMagic\Data\ImageOptions;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Exceptions\InvalidFileOwner;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\FileMagicConfig;

final class PendingFile
{
    private ?string $disk = null;

    private ?string $directory = null;

    private ?string $filename = null;

    private ?FileVisibility $visibility = null;

    private ?CollisionPolicy $collisionPolicy = null;

    private ?int $maxSize = null;

    /**
     * @var list<string>|null
     */
    private ?array $allowedMimeTypes = null;

    /**
     * @var list<string>|null
     */
    private ?array $blockedMimeTypes = null;

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    private ?Model $owner = null;

    private ?ImageOptions $imageOptions = null;

    /**
     * Begin configuring storage for a file source.
     */
    public function __construct(
        private readonly FileSource $source,
        private readonly StoreFile $storeFile,
        private readonly FileMagicConfig $config,
    ) {}

    /**
     * Select the Laravel filesystem disk.
     */
    public function onDisk(string $disk): self
    {
        if ($disk === '') {
            throw new InvalidConfiguration('The [onDisk] option must be a non-empty string.');
        }

        $this->disk = $disk;

        return $this;
    }

    /**
     * Select a relative storage directory.
     */
    public function inDirectory(string $directory): self
    {
        $this->directory = $directory;

        return $this;
    }

    /**
     * Select a filename without an extension.
     */
    public function named(string|int $filename): self
    {
        $this->filename = (string) $filename;

        return $this;
    }

    /**
     * Select public or private filesystem visibility.
     */
    public function visibility(FileVisibility $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * Select how an existing storage path is handled.
     */
    public function onCollision(CollisionPolicy $policy): self
    {
        $this->collisionPolicy = $policy;

        return $this;
    }

    /**
     * Restrict the maximum trusted file size in bytes.
     */
    public function maxSize(int $bytes): self
    {
        if ($bytes < 1) {
            throw new InvalidConfiguration('The [maxSize] option must be a positive integer.');
        }

        $this->maxSize = $bytes;

        return $this;
    }

    /**
     * Restrict storage to the supplied trusted MIME types.
     *
     * @param  list<string>  $mimeTypes
     */
    public function allowMimeTypes(array $mimeTypes): self
    {
        $this->validateMimeTypes($mimeTypes, 'allowMimeTypes');
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Reject the supplied trusted MIME types.
     *
     * @param  list<string>  $mimeTypes
     */
    public function blockMimeTypes(array $mimeTypes): self
    {
        $this->validateMimeTypes($mimeTypes, 'blockMimeTypes');
        $this->blockedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Attach JSON-serializable application metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Associate the stored file with an Eloquent model.
     */
    public function ownedBy(Model $owner): self
    {
        $key = $owner->getKey();

        if (
            $owner->exists === false ||
            (\is_int($key) === false && (\is_string($key) === false || $key === ''))
        ) {
            throw new InvalidFileOwner('The file owner must already be persisted.');
        }

        $this->owner = $owner;

        return $this;
    }

    /**
     * Resize an image proportionally before storage.
     */
    public function resizeImage(?int $maxWidth = null, ?int $quality = null): self
    {
        $this->imageOptions = new ImageOptions(
            $maxWidth ?? $this->config->imageMaximumWidth(),
            $quality ?? $this->config->imageQuality(),
        );

        return $this;
    }

    /**
     * Store the file and return its database record.
     */
    public function store(): StoredFile
    {
        try {
            return $this->storeFile->execute($this);
        } finally {
            if ($this->source instanceof ReleasableFileSource) {
                $this->source->release();
            }
        }
    }

    /**
     * Return the configured source.
     */
    public function source(): FileSource
    {
        return $this->source;
    }

    /** Return the configured disk. */
    public function disk(): ?string
    {
        return $this->disk;
    }

    /** Return the configured directory. */
    public function directory(): ?string
    {
        return $this->directory;
    }

    /** Return the configured filename. */
    public function filename(): ?string
    {
        return $this->filename;
    }

    /** Return the configured visibility. */
    public function fileVisibility(): ?FileVisibility
    {
        return $this->visibility;
    }

    /** Return the configured collision policy. */
    public function collisionPolicy(): ?CollisionPolicy
    {
        return $this->collisionPolicy;
    }

    /** Return the configured maximum file size. */
    public function maximumSize(): ?int
    {
        return $this->maxSize;
    }

    /** @return list<string>|null */
    public function allowedMimeTypes(): ?array
    {
        return $this->allowedMimeTypes;
    }

    /** @return list<string>|null */
    public function blockedMimeTypes(): ?array
    {
        return $this->blockedMimeTypes;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** Return the configured owner. */
    public function owner(): ?Model
    {
        return $this->owner;
    }

    /** Return the configured image operation. */
    public function imageOptions(): ?ImageOptions
    {
        return $this->imageOptions;
    }

    /**
     * Validate one operation's MIME type list.
     *
     * @param  array<array-key, mixed>  $mimeTypes
     */
    private function validateMimeTypes(array $mimeTypes, string $option): void
    {
        if (\array_is_list($mimeTypes) === false) {
            throw new InvalidConfiguration("The [{$option}] option must be a list of non-empty strings.");
        }

        foreach ($mimeTypes as $mimeType) {
            if (\is_string($mimeType) === false || $mimeType === '') {
                throw new InvalidConfiguration("The [{$option}] option must be a list of non-empty strings.");
            }
        }
    }
}
