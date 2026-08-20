<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Exceptions\FileRecoveryFailed;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Exceptions\InvalidFileOwner;
use Mattmy\FileMagic\Exceptions\InvalidFileTarget;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Tests\Fixtures\CompatibleStoredFile;
use Mattmy\FileMagic\Tests\Fixtures\CompatibleStoredFileChild;
use Mattmy\FileMagic\Tests\Fixtures\FailingStoredFile;
use Mattmy\FileMagic\Tests\Fixtures\GloballyScopedStoredFile;
use Mattmy\FileMagic\Tests\Fixtures\TrackingStoredFile;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('rejects an incompatible model target before deleting its object or a configured record', function (): void {
    $file = FileMagic::fromContent('original')->named('original')->store();

    \config()->set('file-magic.model', TrackingStoredFile::class);

    expect(static fn () => FileMagic::find($file)->delete())
        ->toThrow(InvalidFileTarget::class);

    expect(StoredFile::query()->find($file->id))->not->toBeNull();
    Storage::disk('testing')->assertExists($file->path);
});

it('rejects invalid model target identity before querying', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $wrongKey = clone $file;
    $wrongTable = clone $file;
    $wrongConnection = clone $file;
    $missingKey = clone $file;
    $queries = 0;

    $wrongKey->setKeyName('file_id');
    $wrongTable->setTable('other_files');
    $wrongConnection->setConnection('other');
    $missingKey->setAttribute($missingKey->getKeyName(), null);
    \config()->set('database.connections.other', \config('database.connections.testing'));
    \DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    foreach ([new StoredFile(), $wrongKey, $wrongTable, $wrongConnection, $missingKey] as $target) {
        expect(static fn () => FileMagic::find($target)->one())
            ->toThrow(InvalidFileTarget::class);
    }

    expect($queries)->toBe(0);
});

it('accepts a persisted model of the configured compatible subclass', function (): void {
    \config()->set('file-magic.model', CompatibleStoredFile::class);

    $file = FileMagic::fromContent('contents')->store();

    expect(FileMagic::find($file)->one())->toBe($file);
});

it('deduplicates same-row compatible subclasses while preserving the first model instance', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $child = CompatibleStoredFile::query()->findOrFail($file->getKey());

    expect(FileMagic::find($file, $child)->get())
        ->toHaveCount(1)
        ->first()->toBe($file)
        ->and(FileMagic::find($child, $file)->get())
        ->toHaveCount(1)
        ->first()->toBe($child);
});

it('deduplicates child subclasses of a configured custom model while preserving the first instance', function (): void {
    \config()->set('file-magic.model', CompatibleStoredFile::class);

    $file = FileMagic::fromContent('contents')->store();
    $child = CompatibleStoredFileChild::query()->findOrFail($file->getKey());

    expect(FileMagic::find($file, $child)->get())
        ->toHaveCount(1)
        ->first()->toBe($file)
        ->and(FileMagic::find($child, $file)->get())
        ->toHaveCount(1)
        ->first()->toBe($child);
});

it('deduplicates compatible subclasses across IDs UUIDs and supported containers in first-target order', function (): void {
    $file = FileMagic::fromContent('first')->store();
    $other = FileMagic::fromContent('second')->store();
    $child = CompatibleStoredFile::query()->findOrFail($file->getKey());
    $queries = 0;

    \DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $files = FileMagic::find(
        $child,
        [$file->getKey()],
        collect([$file->uuid, $other->getKey()]),
        $other,
    )->get();

    expect($queries)->toBe(1)
        ->and($files)->toHaveCount(2)
        ->and($files->first())->toBe($child)
        ->and($files->map(static fn (StoredFile $storedFile): int => $storedFile->getKey())->all())
        ->toBe([$file->getKey(), $other->getKey()]);
});

it('keeps the first query result when an ID or UUID precedes a compatible subclass', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $child = CompatibleStoredFile::query()->findOrFail($file->getKey());

    $fromId = FileMagic::find($file->getKey(), $child)->one();
    $fromUuid = FileMagic::find($file->uuid, $child)->one();

    expect($fromId)->toBeInstanceOf(StoredFile::class)
        ->not->toBe($child)
        ->and($fromUuid)->toBeInstanceOf(StoredFile::class)
        ->not->toBe($child);
});

it('does not query for model-only compatible duplicates or deduplicate distinct records', function (): void {
    $first = FileMagic::fromContent('first')->store();
    $second = FileMagic::fromContent('second')->store();
    $child = CompatibleStoredFile::query()->findOrFail($first->getKey());
    $queries = 0;

    \DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $files = FileMagic::find($child, $first, $second)->get();

    expect($queries)->toBe(0)
        ->and($files)->toHaveCount(2)
        ->and($files->all())->toBe([$child, $second]);
});

it('rejects invalid mixed model targets before querying', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $invalid = clone $file;
    $queries = 0;

    $invalid->setTable('other_files');
    \DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    expect(static fn () => FileMagic::find($file, $invalid, $file->getKey())->get())
        ->toThrow(InvalidFileTarget::class)
        ->and($queries)->toBe(0);
});

it('deletes a same-row compatible subclass target only once', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $child = CompatibleStoredFile::query()->findOrFail($file->getKey());

    expect(FileMagic::find($file, $child)->delete())->toBe(1);

    expect(StoredFile::query()->find($file->getKey()))->toBeNull();
    Storage::disk('testing')->assertMissing($file->path);
});

it('rejects an unsaved owner before storage begins', function (): void {
    $owner = new class() extends Model {};
    $ownerWithoutKey = new class() extends Model
    {
        public $exists = true;
    };

    expect(static fn () => FileMagic::fromContent('contents')->ownedBy($owner))
        ->toThrow(InvalidFileOwner::class)
        ->and(static fn () => FileMagic::fromContent('contents')->ownedBy($ownerWithoutKey))
        ->toThrow(InvalidFileOwner::class);
});

it('preserves record and cleanup failures when a newly written object cannot be deleted', function (): void {
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);

    $this->app->instance(FilesystemFactory::class, $factory);
    \config()->set('file-magic.model', FailingStoredFile::class);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/new.txt')->andReturnFalse();
    $filesystem->shouldReceive('put')->once()->andReturnTrue();
    $filesystem->shouldReceive('delete')->once()->with('files/new.txt')->andReturnFalse();

    try {
        FileMagic::fromContent('contents')->named('new')->store();
    } catch (FileRecoveryFailed $exception) {
        expect($exception->operationFailure())->toBeInstanceOf(\RuntimeException::class)
            ->and($exception->getPrevious())->toBeInstanceOf(FileWriteFailed::class);

        return;
    }

    throw new \RuntimeException('The cleanup failure was not thrown.');
});

it('preserves record and cleanup failures when deleting a newly written object throws', function (): void {
    $filesystem = \Mockery::mock(Filesystem::class);
    $factory = \Mockery::mock(FilesystemFactory::class);

    $this->app->instance(FilesystemFactory::class, $factory);
    \config()->set('file-magic.model', FailingStoredFile::class);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/new.txt')->andReturnFalse();
    $filesystem->shouldReceive('put')->once()->andReturnTrue();
    $filesystem->shouldReceive('delete')->once()->with('files/new.txt')
        ->andThrow(new \RuntimeException('Simulated cleanup failure.'));

    try {
        FileMagic::fromContent('contents')->named('new')->store();
    } catch (FileRecoveryFailed $exception) {
        expect($exception->operationFailure())->toBeInstanceOf(\RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('Simulated cleanup failure.');

        return;
    }

    throw new \RuntimeException('The cleanup failure was not thrown.');
});

it('updates the existing overwrite record when its global scope hides it', function (): void {
    $original = FileMagic::fromContent('old contents')->named('same')->store();

    \config()->set('file-magic.model', GloballyScopedStoredFile::class);

    $replacement = FileMagic::fromContent('new contents')
        ->named('same')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store();

    expect($replacement)->toBeInstanceOf(GloballyScopedStoredFile::class)
        ->and($replacement->getKey())->toBe($original->getKey())
        ->and(StoredFile::query()->findOrFail($original->id)->contents())->toBe('new contents');
});
