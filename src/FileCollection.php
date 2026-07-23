<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use ArrayIterator;
use Countable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Mattmy\FileMagic\Actions\DeleteFiles;
use Mattmy\FileMagic\Models\StoredFile;
use Traversable;

/**
 * @implements IteratorAggregate<int, StoredFile>
 */
final readonly class FileCollection implements Countable, IteratorAggregate
{
    /**
     * Create an operational collection of stored files.
     *
     * @param  EloquentCollection<int, StoredFile>  $files
     */
    public function __construct(
        private EloquentCollection $files,
        private DeleteFiles $deleteFiles,
    ) {}

    /**
     * Return the number of resolved files.
     */
    public function count(): int
    {
        return $this->files->count();
    }

    /**
     * Determine whether no files were resolved.
     */
    public function isEmpty(): bool
    {
        return $this->files->isEmpty();
    }

    /**
     * Return the first resolved file.
     */
    public function first(): ?StoredFile
    {
        return $this->files->first();
    }

    /**
     * Return public URLs keyed by model key for files that exist on disk.
     *
     * @return Collection<int|string, string>
     */
    public function urls(): Collection
    {
        return $this->files
            ->filter(static fn (StoredFile $file): bool => $file->existsOnDisk())
            ->mapWithKeys(static fn (StoredFile $file): array => [
                $file->getKey() => $file->url(),
            ]);
    }

    /**
     * Delete all resolved files in filesystem and database batches.
     */
    public function delete(): int
    {
        return $this->deleteFiles->execute($this->files);
    }

    /**
     * Iterate over resolved file models without exposing query construction.
     *
     * @return Traversable<int, StoredFile>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->files->all());
    }
}
