<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use Illuminate\Database\Eloquent\Model;
use Mattmy\FileMagic\Actions\StoreFile;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Data\ImageOptions;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Models\StoredFile;

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
    public function __construct(private readonly FileSource $source) {}

    /**
     * Select the Laravel filesystem disk.
     */
    public function onDisk(string $disk): self
    {
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
        $this->owner = $owner;

        return $this;
    }

    /**
     * Resize an image proportionally before storage.
     */
    public function resizeImage(?int $maxWidth = null, ?int $quality = null): self
    {
        $this->imageOptions = new ImageOptions(
            $maxWidth ?? (int) \config('file-magic.image.max_width', 1920),
            $quality ?? (int) \config('file-magic.image.quality', 80),
        );

        return $this;
    }

    /**
     * Store the file and return its database record.
     */
    public function store(): StoredFile
    {
        return \app(StoreFile::class)->execute($this);
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
}
