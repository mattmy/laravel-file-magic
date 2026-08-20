<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mattmy\FileMagic\Exceptions\InvalidFileTarget;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\StoredFileModelResolver;

final readonly class FileFinder
{
    /**
     * Create the file finder.
     */
    public function __construct(private StoredFileModelResolver $models) {}

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

            $key = (string) $file->getKey();

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
            return $this->models->validateTarget($target);
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

        $modelClass = $this->models->resolve();
        $keyName = (new $modelClass())->getKeyName();

        return $modelClass::query()
            ->where(function (Builder $query) use ($ids, $keyName, $uuids): void {
                if ($ids !== []) {
                    $query->whereIn($keyName, \array_values(\array_unique($ids)));
                }

                if ($uuids !== []) {
                    $method = $ids === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('uuid', \array_values(\array_unique($uuids)));
                }
            })
            ->get();
    }
}
