<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Actions\DeleteFiles;
use Mattmy\FileMagic\Exceptions\FileRecordFailed;
use Mattmy\FileMagic\Exceptions\PartialFileDeletion;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\StoredFileModelResolver;
use Mattmy\FileMagic\Tests\Fixtures\TrackingStoredFile;

beforeEach(function (): void {
    Storage::fake('testing');
    Storage::fake('other');
    TrackingStoredFile::$queriesWithoutScopes = 0;
});

it('uses one disk deletion and no existence checks on the successful path', function (): void {
    $first = FileMagic::fromContent('first')->store();
    $second = FileMagic::fromContent('second')->store();
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);
    $files = new Collection([$first, $second]);

    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('delete')
        ->once()
        ->with([$first->path, $second->path])
        ->andReturnTrue();
    $filesystem->shouldNotReceive('exists');

    $deleted = batchDeleteAction($factory)->execute($files);

    expect($deleted)->toBe(2)
        ->and(StoredFile::query()->count())->toBe(0);
});

it('deletes only confirmed missing records after a partial disk failure', function (): void {
    $deletedFile = FileMagic::fromContent('deleted')->store();
    $remainingFile = FileMagic::fromContent('remaining')->store();
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);

    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('delete')->once()->andReturnFalse();
    $filesystem->shouldReceive('exists')->once()->with($deletedFile->path)->andReturnFalse();
    $filesystem->shouldReceive('exists')->once()->with($remainingFile->path)->andReturnTrue();

    try {
        batchDeleteAction($factory)->execute(new Collection([$deletedFile, $remainingFile]));
    } catch (PartialFileDeletion $exception) {
        expect($exception->deletedCount())->toBe(1)
            ->and($exception->failedCount())->toBe(1)
            ->and($exception->failedKeys())->toBe([$remainingFile->id])
            ->and($exception->getPrevious())->not->toBeNull()
            ->and(StoredFile::query()->whereKey($deletedFile->id)->exists())->toBeFalse()
            ->and(StoredFile::query()->whereKey($remainingFile->id)->exists())->toBeTrue();

        return;
    }

    throw new RuntimeException('The partial deletion exception was not thrown.');
});

it('treats a false bulk result as success when every object is missing', function (): void {
    $first = FileMagic::fromContent('first')->store();
    $second = FileMagic::fromContent('second')->store();
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);

    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('delete')->once()->andReturnFalse();
    $filesystem->shouldReceive('exists')->twice()->andReturnFalse();

    $deleted = batchDeleteAction($factory)->execute(new Collection([$first, $second]));

    expect($deleted)->toBe(2)
        ->and(StoredFile::query()->count())->toBe(0);
});

it('continues deleting later disks after an earlier disk fails', function (): void {
    $failedFile = FileMagic::fromContent('failed')->onDisk('testing')->store();
    $deletedFile = FileMagic::fromContent('deleted')->onDisk('other')->store();
    $failedFilesystem = \Mockery::mock(Filesystem::class);
    $successfulFilesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);

    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($failedFilesystem);
    $factory->shouldReceive('disk')->once()->with('other')->andReturn($successfulFilesystem);
    $failedFilesystem->shouldReceive('delete')->once()->andReturnFalse();
    $failedFilesystem->shouldReceive('exists')->once()->with($failedFile->path)->andReturnTrue();
    $successfulFilesystem->shouldReceive('delete')->once()->with([$deletedFile->path])->andReturnTrue();

    try {
        batchDeleteAction($factory)->execute(new Collection([$failedFile, $deletedFile]));
    } catch (PartialFileDeletion $exception) {
        expect($exception->deletedCount())->toBe(1)
            ->and($exception->failedKeys())->toBe([$failedFile->id])
            ->and(StoredFile::query()->whereKey($failedFile->id)->exists())->toBeTrue()
            ->and(StoredFile::query()->whereKey($deletedFile->id)->exists())->toBeFalse();

        return;
    }

    throw new RuntimeException('The multi-disk partial deletion exception was not thrown.');
});

it('keeps records whose storage state cannot be verified', function (): void {
    $file = FileMagic::fromContent('unknown')->store();
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);

    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('delete')->once()->andThrow(new RuntimeException('Delete failed.'));
    $filesystem->shouldReceive('exists')->once()->andThrow(new RuntimeException('Exists failed.'));

    try {
        batchDeleteAction($factory)->execute(new Collection([$file]));
    } catch (PartialFileDeletion $exception) {
        expect($exception->deletedCount())->toBe(0)
            ->and($exception->failedKeys())->toBe([$file->id])
            ->and($exception->getPrevious()?->getMessage())->toBe('Delete failed.')
            ->and(StoredFile::query()->whereKey($file->id)->exists())->toBeTrue();

        return;
    }

    throw new RuntimeException('The unknown-state partial deletion exception was not thrown.');
});

it('fails explicitly when deleted record counts do not match confirmed objects', function (): void {
    $first = FileMagic::fromContent('first')->store();
    $stale = FileMagic::fromContent('stale')->store();
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);
    $files = new Collection([$first, $stale]);

    StoredFile::query()->whereKey($stale->id)->delete();
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('delete')->once()->andReturnTrue();

    batchDeleteAction($factory)->execute($files);
})->throws(FileRecordFailed::class);

it('uses the configured custom model for store find and batch delete', function (): void {
    Schema::table('stored_files', static function (Blueprint $table): void {
        $table->renameColumn('id', 'file_id');
    });
    \config()->set('file-magic.model', TrackingStoredFile::class);

    $file = FileMagic::fromContent('custom model')->store();
    $found = FileMagic::find($file->getKey())->one();
    $queriesBeforeDeletion = TrackingStoredFile::$queriesWithoutScopes;
    $deleted = FileMagic::find($file)->delete();

    expect($file)->toBeInstanceOf(TrackingStoredFile::class)
        ->and($found)->toBeInstanceOf(TrackingStoredFile::class)
        ->and($deleted)->toBe(1)
        ->and(TrackingStoredFile::$queriesWithoutScopes - $queriesBeforeDeletion)->toBe(3)
        ->and(TrackingStoredFile::query()->count())->toBe(0);
});

/**
 * Create a batch deletion action with a controlled filesystem factory.
 */
function batchDeleteAction(FilesystemFactory $factory): DeleteFiles
{
    return new DeleteFiles($factory, \app(StoredFileModelResolver::class));
}
