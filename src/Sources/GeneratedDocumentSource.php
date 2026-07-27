<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Mattmy\FileMagic\Contracts\TrustedMimeTypeSource;

final readonly class GeneratedDocumentSource implements TrustedMimeTypeSource
{
    /**
     * Create a package-generated document source.
     */
    public function __construct(
        private string $contents,
        private string $mimeType,
    ) {}

    /**
     * Copy the generated content into a seekable temporary stream.
     *
     * @return resource
     */
    public function openStream()
    {
        return (new ContentFileSource($this->contents))->openStream();
    }

    /**
     * Generated documents do not have an untrusted original filename.
     */
    public function originalFilename(): ?string
    {
        return null;
    }

    /**
     * Generated documents do not use a client-provided MIME type.
     */
    public function clientMimeType(): ?string
    {
        return null;
    }

    /**
     * Return the MIME type guaranteed by package-controlled serialization.
     */
    public function trustedMimeType(): string
    {
        return $this->mimeType;
    }
}
