<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Mattmy\FileMagic\Actions\CreateZipDownload;
use Mattmy\FileMagic\Actions\DeleteFiles;
use Mattmy\FileMagic\Exceptions\FileNotFound;
use Mattmy\FileMagic\Exceptions\FileRecordFailed;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Exceptions\InvalidFileName;
use Mattmy\FileMagic\Exceptions\InvalidFileTarget;
use Mattmy\FileMagic\Exceptions\PartialFileDeletion;
use Mattmy\FileMagic\Exceptions\ZipCreationFailed;
use Mattmy\FileMagic\Exceptions\ZipCreationUnavailable;
use Mattmy\FileMagic\Exceptions\ZipLimitExceeded;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Queries\FileFinder;
use Mattmy\FileMagic\Support\FileMagicConfig;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FileQuery
{
    /**
     * @var EloquentCollection<int, StoredFile>|null
     */
    private ?EloquentCollection $resolvedFiles = null;

    /**
     * Create a file query from normalized public targets.
     *
     * @param  list<int|string|StoredFile|array<array-key, int|string|StoredFile>|Collection<array-key, covariant int|string|StoredFile>>  $targets
     */
    public function __construct(
        private readonly FileFinder $finder,
        private readonly DeleteFiles $deleteFiles,
        private readonly CreateZipDownload $createZipDownload,
        private readonly FileMagicConfig $config,
        private readonly array $targets,
    ) {}

    /**
     * Return the first resolved file model.
     *
     * @throws InvalidFileTarget
     */
    public function one(): ?StoredFile
    {
        return $this->resolve()->first();
    }

    /**
     * Return all resolved files as a standard Laravel collection.
     *
     * @return Collection<int, StoredFile>
     *
     * @throws InvalidFileTarget
     */
    public function get(): Collection
    {
        return $this->resolve()->toBase()->values();
    }

    /**
     * Return public URLs keyed by model key for files that exist on disk.
     *
     * @return Collection<int|string, string>
     *
     * @throws InvalidFileTarget
     */
    public function urls(): Collection
    {
        return $this->resolve()
            ->filter(static fn (StoredFile $file): bool => $file->existsOnDisk())
            ->mapWithKeys(static function (StoredFile $file): array {
                $key = $file->getKey();

                if (\is_int($key) === false && \is_string($key) === false) {
                    throw new InvalidFileTarget('A stored file must have an integer or string primary key.');
                }

                return [$key => $file->url()];
            })
            ->toBase();
    }

    /**
     * Determine whether the first resolved file exists on disk.
     *
     * @throws FileNotFound
     */
    public function exists(): bool
    {
        return $this->requiredFile()->existsOnDisk();
    }

    /**
     * Return the first resolved file's public URL.
     *
     * @throws FileNotFound
     */
    public function url(): string
    {
        return $this->requiredFile()->url();
    }

    /**
     * Return the first resolved file's temporary URL.
     *
     * @throws FileNotFound
     * @throws InvalidConfiguration
     */
    public function temporaryUrl(?DateTimeInterface $expiration = null): string
    {
        $file = $this->requiredFile();
        $expiration ??= now()->addMinutes($this->config->temporaryUrlTtl());

        return $file->temporaryUrl($expiration);
    }

    /**
     * Read the first resolved file into memory.
     *
     * @throws FileNotFound
     */
    public function contents(): string
    {
        return $this->requiredFile()->contents();
    }

    /**
     * Open the first resolved file as a readable stream.
     *
     * @return resource
     *
     * @throws FileNotFound
     */
    public function readStream()
    {
        return $this->requiredFile()->readStream();
    }

    /**
     * Create a streamed response for the first resolved file.
     *
     * @throws FileNotFound
     */
    public function download(?string $name = null): StreamedResponse
    {
        return $this->requiredFile()->download($name);
    }

    /**
     * Create a ZIP download containing every resolved file.
     *
     * @throws FileNotFound
     * @throws InvalidConfiguration
     * @throws InvalidFileName
     * @throws ZipCreationFailed
     * @throws ZipCreationUnavailable
     * @throws ZipLimitExceeded
     */
    public function downloadZip(?string $name = null): BinaryFileResponse
    {
        return $this->createZipDownload->execute($this->resolve(), $name);
    }

    /**
     * Delete every resolved file in filesystem and database batches.
     *
     * @throws FileRecordFailed
     * @throws InvalidConfiguration
     * @throws PartialFileDeletion
     */
    public function delete(): int
    {
        return $this->deleteFiles->execute($this->resolve());
    }

    /**
     * Resolve targets once for the lifetime of this query.
     *
     * @return EloquentCollection<int, StoredFile>
     */
    private function resolve(): EloquentCollection
    {
        return $this->resolvedFiles ??= $this->finder->find($this->targets);
    }

    /**
     * Return the first file or throw a domain exception.
     */
    private function requiredFile(): StoredFile
    {
        return $this->one() ?? throw new FileNotFound('No stored file matched the supplied target.');
    }
}
