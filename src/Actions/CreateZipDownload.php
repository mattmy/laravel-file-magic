<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Mattmy\FileMagic\Exceptions\FileMagicException;
use Mattmy\FileMagic\Exceptions\FileNotFound;
use Mattmy\FileMagic\Exceptions\ZipCreationFailed;
use Mattmy\FileMagic\Exceptions\ZipCreationUnavailable;
use Mattmy\FileMagic\Exceptions\ZipLimitExceeded;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\FileMagicConfig;
use Mattmy\FileMagic\Support\ZipNameNormalizer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;
use ZipArchive;

final readonly class CreateZipDownload
{
    private const int COPY_CHUNK_BYTES = 8192;

    private const string TEMPORARY_FILE_PREFIX = 'file-magic-';

    /**
     * Create the ZIP download action.
     */
    public function __construct(
        private FileMagicConfig $config,
        private ZipNameNormalizer $names,
    ) {}

    /**
     * Create a temporary ZIP archive and return a self-cleaning download response.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    public function execute(Collection $files, ?string $name = null): BinaryFileResponse
    {
        $this->ensureAvailable();
        $this->ensureFilesExist($files);

        $maximumFiles = $this->config->zipMaximumFiles();
        $maximumSize = $this->config->zipMaximumSize();

        $this->ensureWithinMetadataLimits($files, $maximumFiles, $maximumSize);

        $archivePath = $this->temporaryFile();
        $entryPaths = [];
        $archive = new ZipArchive();
        $archiveOpened = false;
        $preserveArchive = false;

        try {
            $this->openArchive($archive, $archivePath);
            $archiveOpened = true;
            $entryPaths = $this->addFiles($archive, $files, $maximumSize);
            $this->closeArchive($archive);
            $archiveOpened = false;

            $response = $this->response($archivePath, $this->names->downloadName($name));
            $preserveArchive = true;

            return $response;
        } catch (FileMagicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ZipCreationFailed('The ZIP archive could not be created.', previous: $exception);
        } finally {
            if ($archiveOpened) {
                $archive->close();
            }

            $this->deleteTemporaryFiles($entryPaths);

            if ($preserveArchive === false) {
                $this->deleteTemporaryFiles([$archivePath]);
            }
        }
    }

    /**
     * Ensure the optional ZIP extension is available.
     */
    private function ensureAvailable(): void
    {
        if (\extension_loaded('zip') === false || \class_exists(ZipArchive::class) === false) {
            throw new ZipCreationUnavailable('ZIP downloads require the PHP zip extension.');
        }
    }

    /**
     * Reject empty query results.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    private function ensureFilesExist(Collection $files): void
    {
        if ($files->isEmpty()) {
            throw new FileNotFound('No stored files were available for the ZIP download.');
        }
    }

    /**
     * Validate configured file-count and metadata-size limits.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    private function ensureWithinMetadataLimits(
        Collection $files,
        int $maximumFiles,
        int $maximumSize,
    ): void {
        if ($files->count() > $maximumFiles) {
            throw new ZipLimitExceeded("The ZIP download exceeds the {$maximumFiles} file limit.");
        }

        $totalSize = 0;

        foreach ($files as $file) {
            if ($file->size > $maximumSize - $totalSize) {
                throw new ZipLimitExceeded("The ZIP download exceeds the {$maximumSize} byte limit.");
            }

            $totalSize += $file->size;
        }
    }

    /**
     * Create a unique writable temporary file.
     */
    private function temporaryFile(): string
    {
        $path = \tempnam(\sys_get_temp_dir(), self::TEMPORARY_FILE_PREFIX);

        if ($path === false) {
            throw new ZipCreationFailed('A temporary file could not be created for the ZIP download.');
        }

        return $path;
    }

    /**
     * Open the destination archive for replacement.
     */
    private function openArchive(ZipArchive $archive, string $path): void
    {
        $result = $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new ZipCreationFailed("The ZIP archive could not be opened (code {$result}).");
        }
    }

    /**
     * Stream every stored file to a temporary entry and add it to the archive.
     *
     * @param  Collection<int, StoredFile>  $files
     * @return list<string>
     */
    private function addFiles(ZipArchive $archive, Collection $files, int $maximumSize): array
    {
        $entryPaths = [];
        $usedNames = [];
        $writtenBytes = 0;

        try {
            foreach ($files as $file) {
                $entryPath = $this->temporaryFile();
                $entryPaths[] = $entryPath;
                $writtenBytes = $this->copyFile($file, $entryPath, $writtenBytes, $maximumSize);
                $entryName = $this->names->entryName($file->originalName(), $file->fullName());
                $entryName = $this->names->uniqueEntryName($entryName, $usedNames);

                if ($archive->addFile($entryPath, $entryName) === false) {
                    throw new ZipCreationFailed("The archive entry [{$entryName}] could not be added.");
                }

                $usedNames[\strtolower($entryName)] = true;
            }
        } catch (Throwable $exception) {
            $this->deleteTemporaryFiles($entryPaths);

            throw $exception;
        }

        return $entryPaths;
    }

    /**
     * Copy one stored file to disk without exceeding the actual byte limit.
     */
    private function copyFile(
        StoredFile $file,
        string $entryPath,
        int $writtenBytes,
        int $maximumSize,
    ): int {
        $source = $file->readStream();
        $destination = \fopen($entryPath, 'w+b');

        if ($destination === false) {
            \fclose($source);

            throw new ZipCreationFailed('A ZIP entry temporary file could not be opened.');
        }

        try {
            while (\feof($source) === false) {
                $chunk = \fread($source, self::COPY_CHUNK_BYTES);

                if ($chunk === false) {
                    throw new FileNotFound('A stored file could not be read for the ZIP download.');
                }

                $chunkLength = \strlen($chunk);

                if ($chunkLength > $maximumSize - $writtenBytes) {
                    throw new ZipLimitExceeded("The ZIP download exceeds the {$maximumSize} byte limit.");
                }

                $this->writeChunk($destination, $chunk);
                $writtenBytes += $chunkLength;
            }
        } finally {
            \fclose($source);
            \fclose($destination);
        }

        return $writtenBytes;
    }

    /**
     * Write a complete chunk, including streams that perform partial writes.
     *
     * @param  resource  $destination
     */
    private function writeChunk($destination, string $chunk): void
    {
        $offset = 0;
        $length = \strlen($chunk);

        while ($offset < $length) {
            $written = \fwrite($destination, \substr($chunk, $offset));

            if ($written === false || $written === 0) {
                throw new ZipCreationFailed('A ZIP entry temporary file could not be written.');
            }

            $offset += $written;
        }
    }

    /**
     * Finish writing the archive.
     */
    private function closeArchive(ZipArchive $archive): void
    {
        if ($archive->close() === false) {
            throw new ZipCreationFailed('The ZIP archive could not be finalized.');
        }
    }

    /**
     * Build an attachment response that deletes the archive after transmission.
     */
    private function response(string $path, string $name): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Delete temporary paths when they still exist.
     *
     * @param  list<string>  $paths
     */
    private function deleteTemporaryFiles(array $paths): void
    {
        $filesystem = new Filesystem();

        foreach ($paths as $path) {
            $filesystem->delete($path);
        }
    }
}
