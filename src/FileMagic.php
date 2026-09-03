<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as SupportCollection;
use Mattmy\FileMagic\Actions\CreateZipDownload;
use Mattmy\FileMagic\Actions\DeleteFiles;
use Mattmy\FileMagic\Actions\StoreFile;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Queries\FileFinder;
use Mattmy\FileMagic\Sources\Base64FileSource;
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Sources\GeneratedDocumentSource;
use Mattmy\FileMagic\Sources\PathFileSource;
use Mattmy\FileMagic\Sources\RemoteFileSource;
use Mattmy\FileMagic\Sources\UploadedFileSource;
use Mattmy\FileMagic\Support\DocumentFactory;
use Mattmy\FileMagic\Support\FileMagicConfig;
use Mattmy\FileMagic\Support\RemoteDownloader;
use Mattmy\FileMagic\Support\RemoteFileOptionsFactory;
use Symfony\Component\Mime\MimeTypes;

final class FileMagic
{
    /**
     * Create the FileMagic entry point.
     */
    public function __construct(
        private readonly FileFinder $finder,
        private readonly DeleteFiles $deleteFiles,
        private readonly CreateZipDownload $createZipDownload,
        private readonly DocumentFactory $documents,
        private readonly RemoteDownloader $remoteDownloader,
        private readonly RemoteFileOptionsFactory $remoteOptions,
        private readonly StoreFile $storeFile,
        private readonly FileMagicConfig $config,
    ) {}

    /**
     * Begin storing a Laravel uploaded file.
     */
    public function fromUpload(UploadedFile $file): PendingFile
    {
        return $this->pending(new UploadedFileSource($file));
    }

    /**
     * Begin storing a readable local file.
     */
    public function fromPath(string $path): PendingFile
    {
        return $this->pending(new PathFileSource($path));
    }

    /**
     * Begin storing string or binary content.
     */
    public function fromContent(
        string $contents,
        ?string $originalFilename = null,
        ?string $mimeType = null,
    ): PendingFile {
        return $this->pending(new ContentFileSource($contents, $originalFilename, $mimeType));
    }

    /**
     * Begin storing application-generated content with an optional trusted MIME type.
     */
    public function fromGeneratedContent(
        string $contents,
        ?string $originalFilename = null,
        ?string $mimeType = null,
    ): PendingFile {
        if ($mimeType === null) {
            return $this->fromContent($contents, $originalFilename);
        }

        if (MimeTypes::getDefault()->getExtensions($mimeType) === []) {
            return $this->fromContent($contents, $originalFilename, $mimeType);
        }

        return $this->pending(new GeneratedDocumentSource($contents, $mimeType, $originalFilename));
    }

    /**
     * Begin storing a plain Base64 value or Data URI.
     */
    public function fromBase64(string $base64, ?string $originalFilename = null): PendingFile
    {
        return $this->pending(new Base64FileSource($base64, $originalFilename));
    }

    /**
     * Begin securely downloading and storing a remote HTTP file.
     */
    public function fromUrl(string $url, ?RemoteFileOptions $options = null): PendingFile
    {
        return $this->pending(new RemoteFileSource(
            $url,
            $options ?? $this->remoteOptions->defaults(),
            $this->remoteDownloader,
        ));
    }

    /**
     * Begin storing a generated UTF-8 plain-text document.
     */
    public function text(string $text): PendingFile
    {
        return $this->pending($this->documents->text($text));
    }

    /**
     * Begin storing a generated JSON document.
     *
     * @param  array<array-key, mixed>|\JsonSerializable  $data
     */
    public function json(array|\JsonSerializable $data): PendingFile
    {
        return $this->pending($this->documents->json($data));
    }

    /**
     * Begin storing a generated CSV document.
     *
     * @param  iterable<array-key, array<array-key, scalar|null>>  $rows
     */
    public function csv(iterable $rows): PendingFile
    {
        return $this->pending($this->documents->csv($rows));
    }

    /**
     * Begin a query for IDs, UUIDs, stored file models, arrays or Collections.
     *
     * @param  int|string|StoredFile|array<array-key, int|string|StoredFile>|SupportCollection<array-key, covariant int|string|StoredFile>  ...$targets
     */
    public function find(int|string|StoredFile|array|SupportCollection ...$targets): FileQuery
    {
        return new FileQuery(
            $this->finder,
            $this->deleteFiles,
            $this->createZipDownload,
            $this->config,
            \array_values($targets),
        );
    }

    /**
     * Create a pending file with the package's explicit dependencies.
     */
    private function pending(FileSource $source): PendingFile
    {
        return new PendingFile($source, $this->storeFile, $this->config);
    }
}
