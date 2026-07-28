<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Throwable;

final class OverwriteBackupFactory
{
    /**
     * Capture an existing object and its visibility in a seekable temporary stream.
     */
    public function create(Filesystem $filesystem, string $path): OverwriteBackup
    {
        try {
            $visibility = FileVisibility::tryFrom($filesystem->getVisibility($path));

            if ($visibility === null) {
                throw new FileWriteFailed('The existing file visibility could not be read before overwrite.');
            }

            $source = $filesystem->readStream($path);

            if ($source === null) {
                throw new FileWriteFailed('The existing file could not be read before overwrite.');
            }

            $backup = \tmpfile();

            if ($backup === false) {
                \fclose($source);

                throw new FileWriteFailed('A temporary overwrite backup could not be created.');
            }

            try {
                if (
                    \stream_copy_to_stream($source, $backup) === false ||
                    \fflush($backup) === false ||
                    \rewind($backup) === false
                ) {
                    throw new FileWriteFailed('The existing file could not be backed up before overwrite.');
                }
            } catch (Throwable $exception) {
                \fclose($backup);

                throw $exception;
            } finally {
                \fclose($source);
            }

            return new OverwriteBackup($backup, $visibility);
        } catch (FileWriteFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FileWriteFailed(
                'The existing file could not be backed up before overwrite.',
                previous: $exception,
            );
        }
    }
}
