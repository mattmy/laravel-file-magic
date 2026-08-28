<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;
use Override;

final readonly class PathFileSource implements FileSource
{
    /**
     * Create a source from a readable local path.
     */
    public function __construct(private string $path)
    {
        if (\is_file($this->path) === false || \is_readable($this->path) === false) {
            throw new InvalidFileSource('The file path is not a readable file.');
        }
    }

    /**
     * Open the local file as a binary stream.
     *
     * @return resource
     */
    #[Override]
    public function openStream()
    {
        $stream = \fopen($this->path, 'rb');

        if ($stream === false) {
            throw new InvalidFileSource('The file could not be opened.');
        }

        return $stream;
    }

    /**
     * Return the basename of the source path.
     */
    #[Override]
    public function originalFilename(): string
    {
        return \basename($this->path);
    }

    /**
     * A local path has no client-provided MIME type.
     */
    #[Override]
    public function clientMimeType(): null
    {
        return null;
    }
}
