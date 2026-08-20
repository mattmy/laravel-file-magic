<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\ReleasableFileSource;
use Mattmy\FileMagic\Data\FileMetadata;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;

final class FileSnapshot implements FileSource, ReleasableFileSource
{
    private ?string $path;

    /**
     * Represent one captured file source and its inspected metadata.
     */
    public function __construct(
        string $path,
        private readonly FileMetadata $metadata,
        private readonly ?string $originalFilename,
        private readonly ?string $clientMimeType,
    ) {
        $this->path = $path;
    }

    /**
     * Return the metadata produced while capturing this snapshot.
     */
    public function metadata(): FileMetadata
    {
        return $this->metadata;
    }

    /**
     * Open a new readable stream positioned at the snapshot's beginning.
     *
     * @return resource
     */
    public function openStream()
    {
        $path = $this->path;

        if ($path === null) {
            throw new InvalidFileSource('The captured file source is no longer available.');
        }

        $stream = \fopen($path, 'rb');

        if ($stream === false) {
            throw new InvalidFileSource('The captured file source could not be opened.');
        }

        return $stream;
    }

    /**
     * Return the original filename fixed at capture time.
     */
    public function originalFilename(): ?string
    {
        return $this->originalFilename;
    }

    /**
     * Return the client MIME hint fixed at capture time.
     */
    public function clientMimeType(): ?string
    {
        return $this->clientMimeType;
    }

    /**
     * Release the temporary file owned by this snapshot.
     */
    public function release(): void
    {
        $path = $this->path;

        if ($path === null) {
            return;
        }

        $this->path = null;
        @\unlink($path);
    }
}
