<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;

final class OverwriteBackup
{
    /**
     * @var resource
     */
    private $stream;

    /**
     * Create a restorable overwrite backup.
     *
     * @param  resource  $stream
     */
    public function __construct(
        $stream,
        private readonly FileVisibility $visibility,
    ) {
        $this->stream = $stream;
    }

    /**
     * Restore the backup to its original storage path and visibility.
     */
    public function restore(Filesystem $filesystem, string $path): void
    {
        if (\is_resource($this->stream) === false || \rewind($this->stream) === false) {
            throw new FileWriteFailed('The overwrite backup stream could not be rewound.');
        }

        if ($filesystem->put($path, $this->stream, ['visibility' => $this->visibility->value]) === false) {
            throw new FileWriteFailed('The original file could not be restored after an overwrite failure.');
        }
    }

    /**
     * Close the backup stream and delete its temporary file.
     */
    public function close(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }
}
