<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Data;

final readonly class RemoteDownload
{
    /**
     * Describe a downloaded temporary file and its untrusted response metadata.
     */
    public function __construct(
        public string $path,
        public ?string $originalFilename,
        public ?string $clientMimeType,
    ) {}
}
