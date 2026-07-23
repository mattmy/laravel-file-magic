<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Data;

final readonly class FileMetadata
{
    /**
     * Describe trusted metadata detected from file content.
     */
    public function __construct(
        public string $mimeType,
        public int $size,
        public string $checksum,
    ) {}
}
