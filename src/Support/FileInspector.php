<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use finfo;
use Illuminate\Filesystem\Filesystem;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\TrustedMimeTypeSource;
use Mattmy\FileMagic\Data\FileMetadata;
use Mattmy\FileMagic\Exceptions\FileMagicException;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;

final class FileInspector
{
    private const int BUFFER_SIZE = 8192;

    /**
     * Capture, inspect and checksum a source in one bounded read.
     */
    public function capture(
        FileSource $source,
        string $checksumAlgorithm,
        int $maximumSize,
    ): FileSnapshot {
        $originalFilename = $source->originalFilename();
        $clientMimeType = $source->clientMimeType();
        $trustedMimeType = $source instanceof TrustedMimeTypeSource
            ? $source->trustedMimeType()
            : null;
        $stream = $source->openStream();
        $path = null;

        try {
            $path = \tempnam(\sys_get_temp_dir(), 'file-magic-snapshot-');

            if ($path === false) {
                throw new InvalidFileSource('The file source could not be captured.');
            }

            $temporary = \fopen($path, 'w+b');

            if ($temporary === false) {
                throw new InvalidFileSource('The file source could not be captured.');
            }

            try {
                $hash = \hash_init($checksumAlgorithm);
                $size = 0;
                $sample = '';

                while (\feof($stream) === false) {
                    $chunk = \fread($stream, self::BUFFER_SIZE);

                    if ($chunk === false || ($chunk === '' && \feof($stream) === false)) {
                        throw new InvalidFileSource('The file stream could not be read.');
                    }

                    $size += \strlen($chunk);

                    if ($size > $maximumSize) {
                        throw new FileTooLarge("The file exceeds the {$maximumSize} byte limit.");
                    }

                    $this->writeAll($temporary, $chunk);
                    \hash_update($hash, $chunk);

                    if ($sample === '' && $chunk !== '') {
                        $sample = $chunk;
                    }
                }

                if (\fflush($temporary) === false) {
                    throw new InvalidFileSource('The file source could not be captured.');
                }

                $metadata = new FileMetadata(
                    $trustedMimeType ?? $this->detectMimeType($sample),
                    $size,
                    \hash_final($hash),
                );
            } finally {
                \fclose($temporary);
            }

            $snapshot = new FileSnapshot(
                $path,
                $metadata,
                $originalFilename,
                $clientMimeType,
            );
            $path = null;

            return $snapshot;
        } catch (FileMagicException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidFileSource('The file source could not be captured.', previous: $exception);
        } finally {
            \fclose($stream);

            if (\is_string($path)) {
                (new Filesystem())->delete($path);
            }
        }
    }

    /**
     * Write a complete chunk to the snapshot stream.
     *
     * @param  resource  $stream
     */
    private function writeAll($stream, string $contents): void
    {
        for ($offset = 0, $length = \strlen($contents); $offset < $length;) {
            $written = \fwrite($stream, \substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new InvalidFileSource('The file source could not be captured.');
            }

            $offset += $written;
        }
    }

    /**
     * Detect a MIME type from a sample of untrusted file content.
     */
    private function detectMimeType(string $sample): string
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample);

        return \is_string($mimeType) && $mimeType !== ''
            ? $mimeType
            : 'application/octet-stream';
    }
}
