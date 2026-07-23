<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\FileNotFound;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string $location_hash
 * @property string $filename
 * @property string|null $original_filename
 * @property string $extension
 * @property string $mime_type
 * @property int $size
 * @property string|null $checksum
 * @property FileVisibility $visibility
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property array<string, mixed>|null $metadata
 * @method static Builder<static> query()
 */
class StoredFile extends Model
{
    /**
     * Attributes accepted when creating a stored file record.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'disk',
        'path',
        'location_hash',
        'filename',
        'original_filename',
        'extension',
        'mime_type',
        'size',
        'checksum',
        'visibility',
        'metadata',
    ];

    /**
     * Create the model and use the configurable package table.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable((string) \config('file-magic.table', 'stored_files'));
    }

    /**
     * Return the owning Eloquent model.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the configured filesystem adapter.
     */
    public function storage(): FilesystemAdapter
    {
        return Storage::disk($this->disk);
    }

    /**
     * Determine whether the physical file exists.
     */
    public function existsOnDisk(): bool
    {
        return $this->storage()->exists($this->path);
    }

    /**
     * Return the stored filename with its extension.
     */
    public function fullName(): string
    {
        return "{$this->filename}.{$this->extension}";
    }

    /**
     * Return the original display filename when known.
     */
    public function originalName(): string
    {
        return $this->original_filename ?? $this->fullName();
    }

    /**
     * Return a public URL for the physical file.
     */
    public function url(): string
    {
        return $this->storage()->url($this->path);
    }

    /**
     * Return a temporary URL for the physical file.
     */
    public function temporaryUrl(?DateTimeInterface $expiration = null): string
    {
        $expiration ??= \now()->addMinutes((int) \config('file-magic.temporary_url_ttl', 5));

        return $this->storage()->temporaryUrl($this->path, $expiration);
    }

    /**
     * Read the entire physical file into memory.
     */
    public function contents(): string
    {
        $contents = $this->storage()->get($this->path);

        if ($contents === null) {
            throw new FileNotFound('The physical file does not exist.');
        }

        return $contents;
    }

    /**
     * Open the physical file as a readable stream.
     *
     * @return resource
     */
    public function readStream()
    {
        $stream = $this->storage()->readStream($this->path);

        if ($stream === null) {
            throw new FileNotFound('The physical file does not exist.');
        }

        return $stream;
    }

    /**
     * Create a streamed download response.
     */
    public function download(?string $name = null): StreamedResponse
    {
        return $this->storage()->download(
            $this->path,
            $name ?? $this->originalName(),
            ['Content-Type' => $this->mime_type],
        );
    }

    /**
     * Delete the physical file before deleting its database record.
     */
    public function delete(): ?bool
    {
        if ($this->existsOnDisk() && $this->storage()->delete($this->path) === false) {
            throw new FileNotFound('The physical file could not be deleted.');
        }

        return parent::delete();
    }

    /**
     * Define native attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'visibility' => FileVisibility::class,
            'metadata' => 'array',
        ];
    }
}
