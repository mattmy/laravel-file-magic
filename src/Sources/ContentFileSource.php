<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;
use Override;

final readonly class ContentFileSource implements FileSource
{
    /**
     * Create an in-memory source.
     */
    public function __construct(
        private string $contents,
        private ?string $originalFilename = null,
        private ?string $clientMimeType = null,
    ) {}

    /**
     * Copy the content into a seekable temporary stream.
     *
     * @return resource
     */
    #[Override]
    public function openStream()
    {
        $stream = \fopen('php://temp', 'w+b');

        if ($stream === false || \fwrite($stream, $this->contents) === false) {
            throw new InvalidFileSource('The content could not be opened as a stream.');
        }

        \rewind($stream);

        return $stream;
    }

    /**
     * Return the optional original filename.
     */
    #[Override]
    public function originalFilename(): ?string
    {
        return $this->originalFilename;
    }

    /**
     * Return the optional MIME type hint.
     */
    #[Override]
    public function clientMimeType(): ?string
    {
        return $this->clientMimeType;
    }
}
