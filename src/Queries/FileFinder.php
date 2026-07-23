<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Queries;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mattmy\FileMagic\Exceptions\InvalidFileTarget;
use Mattmy\FileMagic\Models\StoredFile;

final readonly class FileFinder
{
    /**
     * Create the file finder.
     */
    public function __construct(private Config $config) {}

    /**
     * Resolve file targets while preserving input order and removing duplicates.
     *
     * @param  list<int|string|StoredFile|array<array-key, int|string|StoredFile>|Collection<array-key, int|string|StoredFile>>  $targets
     * @return EloquentCollection<int, StoredFile>
     */
    public function find(array $targets): EloquentCollection
    {
        $normalizedTargets = $this->normalize($targets);
        $storedFiles = $this->fetchStoredFiles($normalizedTargets);
        $filesById = $storedFiles->keyBy(
            static fn (StoredFile $file): string => (string) $file->getKey(),
        );
        $filesByUuid = $storedFiles->keyBy('uuid');
        $resolvedFiles = new EloquentCollection();
        $resolvedKeys = [];

        foreach ($normalizedTargets as $target) {
            $file = match (true) {
                $target instanceof StoredFile => $target,
                \is_int($target) => $filesById->get((string) $target),
                default => $filesByUuid->get($target),
            };

            if ($file instanceof StoredFile === false) {
                continue;
            }

            $key = $file->getMorphClass() . '|' . (string) $file->getKey();

            if (\array_key_exists($key, $resolvedKeys)) {
                continue;
            }

            $resolvedKeys[$key] = true;
            $resolvedFiles->add($file);
        }

        return $resolvedFiles;
    }

    /**
     * Normalize variadic, array and Collection targets into one strict list.
     *
     * @param  list<int|string|StoredFile|array<array-key, int|string|StoredFile>|Collection<array-key, int|string|StoredFile>>  $targets
     * @return list<int|string|StoredFile>
     */
    private function normalize(array $targets): array
    {
        $normalizedTargets = [];

        foreach ($targets as $target) {
            $values = match (true) {
                \is_array($target) => $target,
                $target instanceof Collection => $target->all(),
                default => [$target],
            };

            foreach ($values as $value) {
                $normalizedTargets[] = $this->normalizeTarget($value);
            }
        }

        return $normalizedTargets;
    }

    /**
     * Validate and normalize one file target.
     */
    private function normalizeTarget(mixed $target): int|string|StoredFile
    {
        if ($target instanceof StoredFile) {
            if ($target->exists === false) {
                throw new InvalidFileTarget('A file model target must already exist.');
            }

            return $target;
        }

        if (\is_int($target) && $target > 0) {
            return $target;
        }

        if (\is_string($target) && Str::isUuid($target)) {
            return $target;
        }

        throw new InvalidFileTarget('A file target must be a positive integer ID, UUID, or stored file model.');
    }

    /**
     * Fetch unresolved ID and UUID targets in one database query.
     *
     * @param  list<int|string|StoredFile>  $targets
     * @return EloquentCollection<int, StoredFile>
     */
    private function fetchStoredFiles(array $targets): EloquentCollection
    {
        $ids = [];
        $uuids = [];

        foreach ($targets as $target) {
            if (\is_int($target)) {
                $ids[] = $target;
            } elseif (\is_string($target)) {
                $uuids[] = $target;
            }
        }

        if ($ids === [] && $uuids === []) {
            return new EloquentCollection();
        }

        $modelClass = $this->modelClass();

        return $modelClass::query()
            ->where(function (Builder $query) use ($ids, $uuids): void {
                if ($ids !== []) {
                    $query->whereIn('id', \array_values(\array_unique($ids)));
                }

                if ($uuids !== []) {
                    $method = $ids === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('uuid', \array_values(\array_unique($uuids)));
                }
            })
            ->get();
    }

    /**
     * Return the configured stored file model class.
     *
     * @return class-string<StoredFile>
     */
    private function modelClass(): string
    {
        $modelClass = $this->config->get('file-magic.model', StoredFile::class);

        if (\is_string($modelClass) === false || \is_a($modelClass, StoredFile::class, true) === false) {
            throw new InvalidFileTarget('The configured file model must extend StoredFile.');
        }

        return $modelClass;
    }
}
