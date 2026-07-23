<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Contracts;

interface FileSource
{
    /**
     * Open a new readable stream positioned at its beginning.
     *
     * @return resource
     */
    public function openStream();

    /**
     * Return the untrusted original filename when one is available.
     */
    public function originalFilename(): ?string;

    /**
     * Return the untrusted client-provided MIME type when available.
     */
    public function clientMimeType(): ?string;
}
