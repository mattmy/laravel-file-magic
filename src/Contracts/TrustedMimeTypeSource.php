<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Contracts;

interface TrustedMimeTypeSource extends FileSource
{
    /**
     * Return the MIME type guaranteed by package or application-generated content.
     */
    public function trustedMimeType(): string;
}
