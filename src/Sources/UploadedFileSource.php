<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Illuminate\Http\UploadedFile;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;
use Override;

final readonly class UploadedFileSource implements FileSource
{
    /**
     * Create a source from a valid Laravel uploaded file.
     */
    public function __construct(private UploadedFile $file)
    {
        if ($this->file->isValid() === false) {
            throw new InvalidFileSource('The uploaded file is not valid.');
        }
    }

    /**
     * Open the uploaded file as a binary stream.
     *
     * @return resource
     */
    #[Override]
    public function openStream()
    {
        $stream = \fopen($this->file->getPathname(), 'rb');

        if ($stream === false) {
            throw new InvalidFileSource('The uploaded file could not be opened.');
        }

        return $stream;
    }

    /**
     * Return the client-provided original filename.
     */
    #[Override]
    public function originalFilename(): string
    {
        return $this->file->getClientOriginalName();
    }

    /**
     * Return the client-provided MIME type.
     */
    #[Override]
    public function clientMimeType(): string
    {
        return $this->file->getClientMimeType();
    }
}
