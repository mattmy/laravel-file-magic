<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Contracts;

interface TrustedMimeTypeSource extends FileSource
{
    /**
     * Return the MIME type guaranteed by package-controlled serialization.
     */
    public function trustedMimeType(): string;
}
