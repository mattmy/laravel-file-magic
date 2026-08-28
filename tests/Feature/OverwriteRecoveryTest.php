<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\FileRecordFailed;
use Mattmy\FileMagic\Exceptions\FileRecoveryFailed;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Tests\Fixtures\FailingStoredFile;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('restores original content visibility and record when overwrite persistence fails', function (): void {
    $original = \app(FileMagic::class)
        ->fromContent('old contents', 'old.txt')
        ->named('same')
        ->visibility(FileVisibility::Public)
        ->withMetadata(['version' => 'old'])
        ->store();
    $originalChecksum = $original->checksum;
    $originalUpdatedAt = $original->updated_at?->toDateTimeString();

    \config()->set('file-magic.model', FailingStoredFile::class);

    try {
        \app(FileMagic::class)
            ->fromContent('new contents', 'new.txt')
            ->named('same')
            ->visibility(FileVisibility::Private)
            ->withMetadata(['version' => 'new'])
            ->onCollision(CollisionPolicy::Overwrite)
            ->store();
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(FileRecordFailed::class);
    }

    $persisted = StoredFile::query()->findOrFail($original->id);

    expect($persisted->uuid)->toBe($original->uuid)
        ->and($persisted->original_filename)->toBe('old.txt')
        ->and($persisted->mime_type)->toBe('text/plain')
        ->and($persisted->size)->toBe(12)
        ->and($persisted->checksum)->toBe($originalChecksum)
        ->and($persisted->visibility)->toBe(FileVisibility::Public)
        ->and($persisted->metadata)->toBe(['version' => 'old'])
        ->and($persisted->updated_at?->toDateTimeString())->toBe($originalUpdatedAt)
        ->and($persisted->contents())->toBe('old contents')
        ->and(Storage::disk('testing')->getVisibility($persisted->path))->toBe('public');
});

it('restores an existing object when overwrite storage returns false', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);
    $restoredContents = null;

    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/same.txt')->andReturnTrue();
    $filesystem->shouldReceive('getVisibility')->once()->with('files/same.txt')->andReturn('private');
    $filesystem->shouldReceive('readStream')->once()->with('files/same.txt')->andReturn(
        streamContaining('old contents'),
    );
    $filesystem->shouldReceive('put')
        ->once()
        ->ordered()
        ->withArgs(static fn (string $path, mixed $stream, array $options): bool => (
            $path === 'files/same.txt' &&
            \is_resource($stream) &&
            \stream_get_contents($stream) === 'new contents' &&
            $options === ['visibility' => 'private']
        ))
        ->andReturnFalse();
    $filesystem->shouldReceive('put')
        ->once()
        ->ordered()
        ->withArgs(function (string $path, mixed $stream, array $options) use (&$restoredContents): bool {
            $restoredContents = \is_resource($stream) ? \stream_get_contents($stream) : null;

            return $path === 'files/same.txt' && $options === ['visibility' => 'private'];
        })
        ->andReturnTrue();

    \app(FileMagic::class)
        ->fromContent('new contents')
        ->named('same')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store();

    expect($restoredContents)->toBe('old contents');
})->throws(FileWriteFailed::class);

it('does not start overwrite when the original visibility cannot be backed up', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);

    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/same.txt')->andReturnTrue();
    $filesystem->shouldReceive('getVisibility')->once()->with('files/same.txt')->andReturn('unsupported');
    $filesystem->shouldNotReceive('readStream');
    $filesystem->shouldNotReceive('put');

    \app(FileMagic::class)
        ->fromContent('new contents')
        ->named('same')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store();
})->throws(FileWriteFailed::class);

it('preserves operation and recovery failures when overwrite restoration fails', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);

    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/same.txt')->andReturnTrue();
    $filesystem->shouldReceive('getVisibility')->once()->with('files/same.txt')->andReturn('private');
    $filesystem->shouldReceive('readStream')->once()->with('files/same.txt')->andReturn(
        streamContaining('old contents'),
    );
    $filesystem->shouldReceive('put')
        ->once()
        ->ordered()
        ->andThrow(new RuntimeException('Simulated overwrite failure.'));
    $filesystem->shouldReceive('put')
        ->once()
        ->ordered()
        ->andThrow(new RuntimeException('Simulated recovery failure.'));

    try {
        \app(FileMagic::class)
            ->fromContent('new contents')
            ->named('same')
            ->onCollision(CollisionPolicy::Overwrite)
            ->store();
    } catch (FileRecoveryFailed $exception) {
        expect($exception->operationFailure())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->operationFailure()->getMessage())->toBe('Simulated overwrite failure.')
            ->and($exception->getPrevious()?->getMessage())->toBe('Simulated recovery failure.');

        return;
    }

    throw new RuntimeException('The overwrite recovery failure was not thrown.');
});

/**
 * Create a rewound temporary stream containing a test value.
 *
 * @return resource
 */
function streamContaining(string $contents)
{
    $stream = \tmpfile();

    if ($stream === false || \fwrite($stream, $contents) !== \strlen($contents) || \rewind($stream) === false) {
        throw new RuntimeException('The test stream could not be created.');
    }

    return $stream;
}
