<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use finfo;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\TrustedMimeTypeSource;
use Mattmy\FileMagic\Data\FileMetadata;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;

final class FileInspector
{
    private const int BUFFER_SIZE = 8192;

    /**
     * Detect MIME type, byte size and checksum without loading the whole file.
     */
    public function inspect(FileSource $source, string $checksumAlgorithm): FileMetadata
    {
        $stream = $source->openStream();

        try {
            $hash = \hash_init($checksumAlgorithm);
            $size = 0;
            $sample = '';

            while (\feof($stream) === false) {
                $chunk = \fread($stream, self::BUFFER_SIZE);

                if ($chunk === false) {
                    throw new InvalidFileSource('The file stream could not be read.');
                }

                if ($sample === '') {
                    $sample = $chunk;
                }

                $size += \strlen($chunk);
                \hash_update($hash, $chunk);
            }

            $mimeType = $source instanceof TrustedMimeTypeSource
                ? $source->trustedMimeType()
                : $this->detectMimeType($sample);

            return new FileMetadata($mimeType, $size, \hash_final($hash));
        } finally {
            \fclose($stream);
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
