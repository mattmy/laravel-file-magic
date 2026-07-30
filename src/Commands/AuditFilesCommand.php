<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\StoredFileModelResolver;
use RuntimeException;
use Throwable;

#[Signature('file-magic:audit
    {--disk= : Audit only one configured Laravel filesystem disk}
    {--chunk=' . self::DEFAULT_CHUNK_SIZE . ' : Number of database records processed per chunk}
    {--delete-missing-records : Delete records whose storage objects are confirmed missing}
    {--force : Skip confirmation when deleting missing records}')]
#[Description('Audit FileMagic database records against their storage objects.')]
final class AuditFilesCommand extends Command
{
    private const int DEFAULT_CHUNK_SIZE = 500;

    private const int MAXIMUM_CHUNK_SIZE = 5000;

    private const int EXIT_CLEAN = 0;

    private const int EXIT_MISSING = 1;

    private const int EXIT_FAILED = 2;

    private int $checked = 0;

    private int $healthy = 0;

    private int $missing = 0;

    private int $deleted = 0;

    private int $failed = 0;

    /**
     * @var array<string, Filesystem>
     */
    private array $resolvedFilesystems = [];

    /**
     * Create the FileMagic consistency audit command.
     */
    public function __construct(
        private readonly Config $config,
        private readonly FilesystemFactory $filesystems,
        private readonly StoredFileModelResolver $models,
    ) {
        parent::__construct();
    }

    /**
     * Validate input, audit stored-file records, and return the documented exit code.
     */
    public function handle(): int
    {
        $this->resetState();

        try {
            $disk = $this->validatedDisk();
            $chunkSize = $this->validatedChunkSize();
            $deleteMissingRecords = $this->booleanOption('delete-missing-records');
            $force = $this->booleanOption('force');
            $this->validateDeletionOptions($deleteMissingRecords, $force);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::EXIT_FAILED;
        }

        if ($this->shouldCancelDeletion($deleteMissingRecords, $force)) {
            $this->components->info('Audit cancelled. No records were scanned or changed.');

            return self::EXIT_CLEAN;
        }

        try {
            $this->audit($disk, $chunkSize, $deleteMissingRecords);
        } catch (Throwable) {
            $this->failed++;
            $this->writeAuditFailure($deleteMissingRecords);
            $this->writeSummary();

            return self::EXIT_FAILED;
        }

        $this->writeSummary();

        return $this->exitCode($deleteMissingRecords);
    }

    /**
     * Reset mutable counters and resolved disks before one command execution.
     */
    private function resetState(): void
    {
        $this->checked = 0;
        $this->healthy = 0;
        $this->missing = 0;
        $this->deleted = 0;
        $this->failed = 0;
        $this->resolvedFilesystems = [];
    }

    /**
     * Return the optional configured disk after strict validation.
     */
    private function validatedDisk(): ?string
    {
        $disk = $this->option('disk');

        if ($disk === null) {
            return null;
        }

        if (\is_string($disk) === false || $disk === '' || \trim($disk) !== $disk) {
            throw new InvalidArgumentException('The --disk option must be a non-empty configured disk name.');
        }

        $configuredDisks = $this->config->get('filesystems.disks', []);

        if (
            \is_array($configuredDisks) === false ||
            \array_key_exists($disk, $configuredDisks) === false
        ) {
            throw new InvalidArgumentException("The [{$disk}] filesystem disk is not configured.");
        }

        return $disk;
    }

    /**
     * Return the chunk size after validating its integer representation and bounds.
     */
    private function validatedChunkSize(): int
    {
        $chunkSize = $this->option('chunk');

        if (\is_string($chunkSize) === false || \preg_match('/^[1-9][0-9]*$/D', $chunkSize) !== 1) {
            throw new InvalidArgumentException('The --chunk option must be an integer from 1 through 5000.');
        }

        $validatedChunkSize = (int) $chunkSize;

        if ($validatedChunkSize > self::MAXIMUM_CHUNK_SIZE) {
            throw new InvalidArgumentException('The --chunk option must be an integer from 1 through 5000.');
        }

        return $validatedChunkSize;
    }

    /**
     * Return a flag option after ensuring Symfony supplied a boolean value.
     */
    private function booleanOption(string $name): bool
    {
        $value = $this->option($name);

        if (\is_bool($value) === false) {
            throw new InvalidArgumentException("The --{$name} option must be a boolean flag.");
        }

        return $value;
    }

    /**
     * Reject unsafe or contradictory deletion option combinations before scanning.
     */
    private function validateDeletionOptions(bool $deleteMissingRecords, bool $force): void
    {
        if ($force && $deleteMissingRecords === false) {
            throw new InvalidArgumentException(
                'The --force option may only be used with --delete-missing-records.',
            );
        }

        if ($deleteMissingRecords && $force === false && $this->input->isInteractive() === false) {
            throw new InvalidArgumentException(
                'Non-interactive deletion requires --delete-missing-records and --force together.',
            );
        }
    }

    /**
     * Ask for dangerous-operation confirmation and report whether execution should stop.
     */
    private function shouldCancelDeletion(bool $deleteMissingRecords, bool $force): bool
    {
        if ($deleteMissingRecords === false || $force) {
            return false;
        }

        return $this->confirm(
            'This will permanently delete database records for confirmed missing storage objects. Continue?',
        ) === false;
    }

    /**
     * Build the configured unscoped query and process all records by primary key.
     */
    private function audit(?string $disk, int $chunkSize, bool $deleteMissingRecords): void
    {
        $modelClass = $this->models->resolve();
        $model = new $modelClass();
        $keyName = $model->getKeyName();
        $qualifiedKeyName = $model->qualifyColumn($keyName);
        $query = $model->newQueryWithoutScopes()
            ->select([$keyName, 'disk', 'path']);

        if ($disk !== null) {
            $query->where('disk', $disk);
        }

        $query->chunkById(
            $chunkSize,
            fn (Collection $files): bool => $this->auditChunk($files, $model, $deleteMissingRecords),
            $qualifiedKeyName,
            $keyName,
        );
    }

    /**
     * Audit one bounded model chunk and optionally bulk-delete its missing records.
     *
     * @param  Collection<int, StoredFile>  $files
     */
    private function auditChunk(
        Collection $files,
        StoredFile $model,
        bool $deleteMissingRecords,
    ): bool {
        $missingKeys = [];

        foreach ($files as $file) {
            $missingKey = $this->auditFile($file);

            if ($missingKey !== null) {
                $missingKeys[] = $missingKey;
            }
        }

        if ($deleteMissingRecords && $missingKeys !== []) {
            $this->deleteMissingRecords($model, $missingKeys);
        }

        return true;
    }

    /**
     * Check one record exactly once and return its key only when the object is missing.
     */
    private function auditFile(StoredFile $file): int|string|null
    {
        $this->checked++;
        $key = $this->modelKey($file);
        $disk = $file->getAttribute('disk');
        $path = $file->getAttribute('path');

        if (\is_string($disk) === false || $disk === '' || \is_string($path) === false || $path === '') {
            $this->failed++;
            $this->writeUnknown($key);

            return null;
        }

        try {
            $exists = $this->filesystem($disk)->exists($path);
        } catch (Throwable) {
            $this->failed++;
            $this->writeUnknown($key, $disk, $path);

            return null;
        }

        if ($exists) {
            $this->healthy++;

            return null;
        }

        $this->missing++;
        $this->writeMissing($key, $disk, $path);

        return $key;
    }

    /**
     * Return a model key suitable for output and bulk deletion.
     */
    private function modelKey(StoredFile $file): int|string
    {
        $key = $file->getKey();

        if (\is_int($key) === false && \is_string($key) === false) {
            throw new RuntimeException('A stored-file record has an unsupported primary key.');
        }

        return $key;
    }

    /**
     * Resolve and cache a Laravel filesystem disk without retaining record data.
     */
    private function filesystem(string $disk): Filesystem
    {
        return $this->resolvedFilesystems[$disk] ??= $this->filesystems->disk($disk);
    }

    /**
     * Bulk-delete one chunk of confirmed missing records and verify affected rows.
     *
     * @param  list<int|string>  $keys
     */
    private function deleteMissingRecords(StoredFile $model, array $keys): void
    {
        $deleted = $model->newQueryWithoutScopes()
            ->whereKey($keys)
            ->delete();

        if ($deleted !== \count($keys)) {
            throw new RuntimeException(
                'The number of deleted records did not match the confirmed missing objects.',
            );
        }

        $this->deleted += $deleted;
    }

    /**
     * Write one safe missing-object finding without adapter details or absolute paths.
     */
    private function writeMissing(int|string $key, string $disk, string $path): void
    {
        $this->line("MISSING key={$key} disk={$disk} path={$path}");
    }

    /**
     * Write one safe unknown-state finding without exposing the underlying exception.
     */
    private function writeUnknown(int|string $key, ?string $disk = null, ?string $path = null): void
    {
        $details = "FAILED key={$key}";

        if ($disk !== null) {
            $details .= " disk={$disk}";
        }

        if ($path !== null) {
            $details .= " path={$path}";
        }

        $this->line($details);
    }

    /**
     * Write the required aggregate counters.
     */
    private function writeSummary(): void
    {
        $this->table(
            ['checked', 'healthy', 'missing', 'deleted', 'failed'],
            [[
                $this->checked,
                $this->healthy,
                $this->missing,
                $this->deleted,
                $this->failed,
            ]],
        );
    }

    /**
     * Write a failure message that accurately reflects whether mutation was enabled.
     */
    private function writeAuditFailure(bool $deleteMissingRecords): void
    {
        $message = $deleteMissingRecords
            ? 'The audit could not be completed. Earlier chunks may already have been deleted.'
            : 'The audit could not be completed. Read-only mode did not delete database records.';

        $this->components->error($message);
    }

    /**
     * Determine the final status after a completed scan.
     */
    private function exitCode(bool $deleteMissingRecords): int
    {
        if ($this->failed > 0) {
            return self::EXIT_FAILED;
        }

        if ($deleteMissingRecords === false && $this->missing > 0) {
            return self::EXIT_MISSING;
        }

        return self::EXIT_CLEAN;
    }
}
