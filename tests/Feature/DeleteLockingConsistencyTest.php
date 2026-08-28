<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Exceptions\PartialFileDeletion;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\CollisionLock;
use Mattmy\FileMagic\Support\FileMagicConfig;

beforeEach(function (): void {
    Storage::fake('testing');
    Storage::fake('other');
    config()->set('file-magic.collision_lock.enabled', true);
});

it('does not touch storage when the record disappears after its path lock is acquired', function (): void {
    $file = FileMagic::fromContent('stale')->named('stale')->store();

    installDeleteLock(function () use ($file): bool {
        StoredFile::query()->whereKey($file->id)->delete();

        return true;
    });

    $deleted = FileMagic::find($file)->delete();

    expect($deleted)->toBe(0);
    Storage::disk('testing')->assertExists($file->path);
});

it('preserves both paths when the locked record identity has changed', function (): void {
    $file = FileMagic::fromContent('old')->named('old')->store();
    Storage::disk('other')->put('files/new.txt', 'new');

    installDeleteLock(function () use ($file): bool {
        StoredFile::query()->whereKey($file->id)->update([
            'disk' => 'other',
            'path' => 'files/new.txt',
            'location_hash' => \hash('sha256', "other\0files/new.txt"),
        ]);

        return true;
    });

    $caught = false;

    try {
        FileMagic::find($file)->delete();
    } catch (PartialFileDeletion $exception) {
        $caught = true;
        expect($exception->deletedCount())->toBe(0)
            ->and($exception->failedKeys())->toBe([$file->id]);
    }

    expect($caught)->toBeTrue();
    Storage::disk('testing')->assertExists($file->path);
    Storage::disk('other')->assertExists('files/new.txt');
});

it('reports lock acquisition failure without mutating storage or records', function (): void {
    $file = FileMagic::fromContent('locked')->named('locked')->store();

    installDeleteLock(static fn (): never => throw new RuntimeException('lock unavailable'));

    $caught = false;

    try {
        FileMagic::find($file)->delete();
    } catch (PartialFileDeletion $exception) {
        $caught = true;
        expect($exception->deletedCount())->toBe(0)
            ->and($exception->failedKeys())->toBe([$file->id])
            ->and($exception->getPrevious()?->getPrevious()?->getMessage())->toBe('lock unavailable');
    }

    expect($caught)->toBeTrue()
        ->and($file->fresh())->not->toBeNull();
    Storage::disk('testing')->assertExists($file->path);
});

/**
 * Install one controlled atomic lock at the cache boundary.
 *
 * @param  callable(): bool  $acquire
 */
function installDeleteLock(callable $acquire): void
{
    $cache = Mockery::mock(CacheFactory::class);
    $repository = Mockery::mock(Repository::class);
    $provider = Mockery::mock(LockProvider::class);
    $lock = Mockery::mock(LockContract::class);

    $cache->shouldReceive('store')->once()->with(null)->andReturn($repository);
    $repository->shouldReceive('getStore')->once()->andReturn($provider);
    $provider->shouldReceive('lock')->once()->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(1)->andReturnUsing($acquire);
    $lock->shouldReceive('release')->zeroOrMoreTimes()->andReturnTrue();
    $lock->shouldNotReceive('forceRelease');

    app()->instance(CollisionLock::class, new CollisionLock(
        $cache,
        app(FileMagicConfig::class),
    ));
    app()->forgetInstance(Mattmy\FileMagic\FileMagic::class);
    FileMagic::clearResolvedInstance(Mattmy\FileMagic\FileMagic::class);
}
