<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Actions;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Collection;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Models\StoredFile;

final readonly class DeleteFiles
{
    /**
     * Create the batch deletion action.
     */
    public function __construct(private FilesystemFactory $filesystems) {}

    /**
     * Delete physical objects in disk batches, then delete their records.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    public function execute(Collection $files): int
    {
        if ($files->isEmpty()) {
            return 0;
        }

        $files
            ->groupBy('disk')
            ->each(function (Collection $diskFiles, string $disk): void {
                /** @var list<string> $paths */
                $paths = $diskFiles->pluck('path')->all();

                if ($this->filesystems->disk($disk)->delete($paths) === false) {
                    throw new FileWriteFailed("One or more files could not be deleted from disk [{$disk}].");
                }
            });

        /** @var list<int> $ids */
        $ids = $files->modelKeys();

        return StoredFile::query()->whereKey($ids)->delete();
    }
}
