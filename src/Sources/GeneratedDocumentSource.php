<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Mattmy\FileMagic\Contracts\TrustedMimeTypeSource;
use Override;

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
    #[Override]
    public function openStream()
    {
        return (new ContentFileSource($this->contents))->openStream();
    }

    /**
     * Generated documents do not have an untrusted original filename.
     */
    #[Override]
    public function originalFilename(): ?string
    {
        return null;
    }

    /**
     * Generated documents do not use a client-provided MIME type.
     */
    #[Override]
    public function clientMimeType(): ?string
    {
        return null;
    }

    /**
     * Return the MIME type guaranteed by package-controlled serialization.
     */
    #[Override]
    public function trustedMimeType(): string
    {
        return $this->mimeType;
    }
}
