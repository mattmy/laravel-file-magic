<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as SupportCollection;
use Mattmy\FileMagic\Actions\DeleteFiles;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Queries\FileFinder;
use Mattmy\FileMagic\Sources\Base64FileSource;
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Sources\PathFileSource;
use Mattmy\FileMagic\Sources\UploadedFileSource;

final class FileMagic
{
    /**
     * Create the FileMagic entry point.
     */
    public function __construct(
        private readonly FileFinder $finder,
        private readonly DeleteFiles $deleteFiles,
    ) {}

    /**
     * Begin storing a Laravel uploaded file.
     */
    public function fromUpload(UploadedFile $file): PendingFile
    {
        return new PendingFile(new UploadedFileSource($file));
    }

    /**
     * Begin storing a readable local file.
     */
    public function fromPath(string $path): PendingFile
    {
        return new PendingFile(new PathFileSource($path));
    }

    /**
     * Begin storing string or binary content.
     */
    public function fromContent(
        string $contents,
        ?string $originalFilename = null,
        ?string $mimeType = null,
    ): PendingFile {
        return new PendingFile(new ContentFileSource($contents, $originalFilename, $mimeType));
    }

    /**
     * Begin storing a plain Base64 value or Data URI.
     */
    public function fromBase64(string $base64, ?string $originalFilename = null): PendingFile
    {
        return new PendingFile(new Base64FileSource($base64, $originalFilename));
    }

    /**
     * Begin a query for IDs, UUIDs, stored file models, arrays or Collections.
     *
     * @param  int|string|StoredFile|array<array-key, int|string|StoredFile>|SupportCollection<array-key, int|string|StoredFile>  ...$targets
     */
    public function find(int|string|StoredFile|array|SupportCollection ...$targets): FileQuery
    {
        return new FileQuery($this->finder, $this->deleteFiles, \array_values($targets));
    }
}
