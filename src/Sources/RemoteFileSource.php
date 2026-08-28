<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Illuminate\Filesystem\Filesystem;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\ReleasableFileSource;
use Mattmy\FileMagic\Contracts\SizeLimitedFileSource;
use Mattmy\FileMagic\Data\RemoteDownload;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;
use Mattmy\FileMagic\Support\RemoteDownloader;
use Override;

final class RemoteFileSource implements FileSource, ReleasableFileSource, SizeLimitedFileSource
{
    private ?int $maximumBytes = null;

    private ?RemoteDownload $download = null;

    /**
     * Create a lazily downloaded remote file source.
     */
    public function __construct(
        private readonly string $url,
        private readonly RemoteFileOptions $options,
        private readonly RemoteDownloader $downloader,
    ) {}

    /**
     * Apply the storage operation's byte limit before materialization.
     */
    #[Override]
    public function limitSize(int $bytes): void
    {
        $this->maximumBytes = $bytes;
    }

    /**
     * Open the materialized remote file as a new binary stream.
     *
     * @return resource
     */
    #[Override]
    public function openStream()
    {
        $download = $this->materialize();
        $stream = \fopen($download->path, 'rb');

        if ($stream === false) {
            throw new InvalidFileSource('The downloaded remote file could not be opened.');
        }

        return $stream;
    }

    /**
     * Return the untrusted remote filename hint when available.
     */
    #[Override]
    public function originalFilename(): ?string
    {
        return $this->materialize()->originalFilename;
    }

    /**
     * Return the untrusted remote MIME hint when available.
     */
    #[Override]
    public function clientMimeType(): ?string
    {
        return $this->materialize()->clientMimeType;
    }

    /**
     * Determine whether HTML responses were explicitly enabled.
     */
    public function allowsHtml(): bool
    {
        return $this->options->allowHtml;
    }

    /**
     * Delete the materialized temporary file.
     */
    #[Override]
    public function release(): void
    {
        if ($this->download === null) {
            return;
        }

        if ((new Filesystem)->delete($this->download->path)) {
            $this->download = null;
        }
    }

    /**
     * Download the source once and reuse it for every subsequent stream.
     */
    private function materialize(): RemoteDownload
    {
        if ($this->download instanceof RemoteDownload) {
            return $this->download;
        }

        if ($this->maximumBytes === null) {
            throw new InvalidFileSource('The remote file size limit was not initialized.');
        }

        return $this->download = $this->downloader->download(
            $this->url,
            $this->options,
            $this->maximumBytes,
        );
    }
}
