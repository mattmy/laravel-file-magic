<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Mattmy\FileMagic\Actions\DeleteFiles;
use Mattmy\FileMagic\Exceptions\FileNotFound;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Queries\FileFinder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FileQuery
{
    /**
     * @var EloquentCollection<int, StoredFile>|null
     */
    private ?EloquentCollection $resolvedFiles = null;

    /**
     * Create a file query from normalized public targets.
     *
     * @param  list<int|string|StoredFile|array<array-key, int|string|StoredFile>|Collection<array-key, int|string|StoredFile>>  $targets
     */
    public function __construct(
        private readonly FileFinder $finder,
        private readonly DeleteFiles $deleteFiles,
        private readonly array $targets,
    ) {}

    /**
     * Return the first resolved file model.
     */
    public function one(): ?StoredFile
    {
        return $this->resolve()->first();
    }

    /**
     * Return all resolved files as an operational collection.
     */
    public function get(): FileCollection
    {
        return new FileCollection($this->resolve(), $this->deleteFiles);
    }

    /**
     * Determine whether the first resolved file exists on disk.
     */
    public function exists(): bool
    {
        return $this->requiredFile()->existsOnDisk();
    }

    /**
     * Return the first resolved file's public URL.
     */
    public function url(): string
    {
        return $this->requiredFile()->url();
    }

    /**
     * Return the first resolved file's temporary URL.
     */
    public function temporaryUrl(?DateTimeInterface $expiration = null): string
    {
        return $this->requiredFile()->temporaryUrl($expiration);
    }

    /**
     * Read the first resolved file into memory.
     */
    public function contents(): string
    {
        return $this->requiredFile()->contents();
    }

    /**
     * Open the first resolved file as a readable stream.
     *
     * @return resource
     */
    public function readStream()
    {
        return $this->requiredFile()->readStream();
    }

    /**
     * Create a streamed response for the first resolved file.
     */
    public function download(?string $name = null): StreamedResponse
    {
        return $this->requiredFile()->download($name);
    }

    /**
     * Delete every resolved file in filesystem and database batches.
     */
    public function delete(): int
    {
        return $this->get()->delete();
    }

    /**
     * Resolve targets once for the lifetime of this query.
     *
     * @return EloquentCollection<int, StoredFile>
     */
    private function resolve(): EloquentCollection
    {
        return $this->resolvedFiles ??= $this->finder->find($this->targets);
    }

    /**
     * Return the first file or throw a domain exception.
     */
    private function requiredFile(): StoredFile
    {
        return $this->one() ?? throw new FileNotFound('No stored file matched the supplied target.');
    }
}
