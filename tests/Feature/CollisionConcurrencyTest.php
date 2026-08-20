<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Support\CollisionLock;
use Mattmy\FileMagic\Support\FileMagicConfig;

beforeEach(function (): void {
    Storage::fake('testing');
    config()->set('file-magic.collision_lock.enabled', true);
});

it('stores without resolving a cache lock when collision locking is disabled', function (array $lockConfig): void {
    config()->set('file-magic.collision_lock', $lockConfig);

    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Factory::class);
    $cache->shouldNotReceive('store');
    $this->app->instance(\Illuminate\Contracts\Cache\Factory::class, $cache);

    $file = FileMagic::fromContent('contents')->named('unlocked')->store();

    expect(Storage::disk('testing')->get($file->path))->toBe('contents');
})->with([
    'default' => [[
        'store' => 'missing',
        'lease_seconds' => 'invalid',
        'wait_seconds' => null,
    ]],
    'explicit false' => [[
        'enabled' => false,
        'store' => 'missing',
        'lease_seconds' => 'invalid',
        'wait_seconds' => null,
    ]],
]);

it('rejects a non-boolean collision lock switch before materializing the source', function (mixed $enabled): void {
    config()->set('file-magic.collision_lock.enabled', $enabled);
    $source = new CollisionCountingSource('contents');

    expect(fn () => pendingFile($source)->named('never-opened')->store())
        ->toThrow(InvalidConfiguration::class)
        ->and($source->openedStreams)->toBe(0);
})->with([
    'integer' => [1],
    'string' => ['true'],
    'null' => [null],
    'array' => [[]],
]);

it('preserves callback results and failures when collision locking is disabled', function (): void {
    config()->set('file-magic.collision_lock.enabled', false);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Factory::class);
    $cache->shouldNotReceive('store');
    $locks = new CollisionLock($cache, app(FileMagicConfig::class));

    expect($locks->run('testing', 'files/result.txt', static fn (): string => 'result'))
        ->toBe('result')
        ->and(fn () => $locks->run(
            'testing',
            'files/failure.txt',
            static fn (): never => throw new RuntimeException('callback failed'),
        ))->toThrow(RuntimeException::class, 'callback failed');
});

it('serializes the same storage path and releases the lock after the callback', function (): void {
    $locks = app(CollisionLock::class);
    $nestedCallbackRan = false;

    $result = $locks->run('testing', 'documents/report.txt', function () use (
        $locks,
        &$nestedCallbackRan,
    ): string {
        expect(fn (): string => $locks->run(
            'testing',
            'documents/report.txt',
            function () use (&$nestedCallbackRan): string {
                $nestedCallbackRan = true;

                return 'nested';
            },
        ))->toThrow(FileWriteFailed::class);

        expect($locks->run('testing', 'documents/other.txt', static fn (): string => 'other'))
            ->toBe('other')
            ->and($locks->run('archive', 'documents/report.txt', static fn (): string => 'other disk'))
            ->toBe('other disk');

        return 'outer';
    });

    expect($result)->toBe('outer')
        ->and($nestedCallbackRan)->toBeFalse()
        ->and($locks->run('testing', 'documents/report.txt', static fn (): string => 'released'))
        ->toBe('released');
});

it('rejects invalid collision lock configuration', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn (): CollisionLock => app(CollisionLock::class))
        ->toThrow(InvalidConfiguration::class);
})->with([
    'empty store' => ['file-magic.collision_lock.store', ''],
    'whitespace store' => ['file-magic.collision_lock.store', ' array'],
    'array store' => ['file-magic.collision_lock.store', []],
    'integer store' => ['file-magic.collision_lock.store', 1],
    'non-string store' => ['file-magic.collision_lock.store', false],
    'zero lease' => ['file-magic.collision_lock.lease_seconds', 0],
    'negative lease' => ['file-magic.collision_lock.lease_seconds', -1],
    'string lease' => ['file-magic.collision_lock.lease_seconds', '300'],
    'float lease' => ['file-magic.collision_lock.lease_seconds', 300.0],
    'boolean lease' => ['file-magic.collision_lock.lease_seconds', true],
    'null lease' => ['file-magic.collision_lock.lease_seconds', null],
    'zero wait' => ['file-magic.collision_lock.wait_seconds', 0],
    'negative wait' => ['file-magic.collision_lock.wait_seconds', -1],
    'string wait' => ['file-magic.collision_lock.wait_seconds', '10'],
    'float wait' => ['file-magic.collision_lock.wait_seconds', 1.0],
    'boolean wait' => ['file-magic.collision_lock.wait_seconds', false],
    'null wait' => ['file-magic.collision_lock.wait_seconds', null],
]);

it('uses an explicit valid collision lock store and positive timing values', function (): void {
    config()->set('file-magic.collision_lock.store', 'array');
    config()->set('file-magic.collision_lock.lease_seconds', 1);
    config()->set('file-magic.collision_lock.wait_seconds', 1);

    expect(app(CollisionLock::class)->run(
        'testing',
        'files/valid.txt',
        static fn (): string => 'locked',
    ))->toBe('locked');
});

it('rejects unknown and no-op collision lock stores', function (string $store): void {
    config()->set('file-magic.collision_lock.store', $store);
    config()->set("cache.stores.{$store}", $store === 'disabled'
        ? ['driver' => 'null']
        : null);

    expect(fn (): CollisionLock => app(CollisionLock::class))
        ->toThrow(InvalidConfiguration::class);
})->with([
    'unknown store' => 'missing',
    'null store' => 'disabled',
]);

it('rejects a cache store without atomic lock support', function (): void {
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Factory::class);
    $repository = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $store = Mockery::mock(\Illuminate\Contracts\Cache\Store::class);

    $cache->shouldReceive('store')->once()->with(null)->andReturn($repository);
    $repository->shouldReceive('getStore')->once()->andReturn($store);

    expect(fn (): CollisionLock => new CollisionLock(
        $cache,
        app(FileMagicConfig::class),
    ))->toThrow(InvalidConfiguration::class);
});

it('fails before storage when collision lock configuration is invalid', function (): void {
    config()->set('file-magic.collision_lock.lease_seconds', 0);
    $source = new CollisionCountingSource('contents');

    expect(fn () => pendingFile($source)->named('never-written')->store())
        ->toThrow(InvalidConfiguration::class);

    Storage::disk('testing')->assertMissing('files/never-written.txt');
    expect(StoredFile::query()->count())->toBe(0)
        ->and($source->openedStreams)->toBe(0);
});

it('wraps acquisition failures and preserves callback failures', function (): void {
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Factory::class);
    $repository = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $lockProvider = Mockery::mock(LockProvider::class);
    $lock = Mockery::mock(LockContract::class);

    $cache->shouldReceive('store')->once()->with(null)->andReturn($repository);
    $repository->shouldReceive('getStore')->once()->andReturn($lockProvider);
    $lockProvider->shouldReceive('lock')->once()->andReturn($lock);
    $lock->shouldReceive('block')->once()->andThrow(new RuntimeException('backend unavailable'));

    $locks = new CollisionLock($cache, app(FileMagicConfig::class));
    $acquisitionFailed = false;

    try {
        $locks->run('testing', 'files/report.txt', static fn (): string => 'unused');
    } catch (FileWriteFailed $exception) {
        $acquisitionFailed = true;
        expect($exception->getPrevious()?->getMessage())->toBe('backend unavailable');
    }

    expect($acquisitionFailed)->toBeTrue();

    expect(fn () => app(CollisionLock::class)->run(
        'testing',
        'files/callback.txt',
        static fn (): never => throw new RuntimeException('callback failed'),
    ))->toThrow(RuntimeException::class, 'callback failed');
});

it('applies error and overwrite policies after the previous writer releases the path', function (): void {
    $original = FileMagic::fromContent('original')->named('same')->store();

    expect(fn () => FileMagic::fromContent('rejected')
        ->named('same')
        ->onCollision(CollisionPolicy::Error)
        ->store())
        ->toThrow(FileWriteFailed::class);

    expect(Storage::disk('testing')->get($original->path))->toBe('original')
        ->and(StoredFile::query()->count())->toBe(1);

    $replacement = FileMagic::fromContent('replacement')
        ->named('same')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store();

    expect($replacement->getKey())->toBe($original->getKey())
        ->and(Storage::disk('testing')->get($original->path))->toBe('replacement')
        ->and(StoredFile::query()->count())->toBe(1);
});

it('applies the unique policy after the previous writer releases the path', function (): void {
    $first = FileMagic::fromContent('first')->named('same')->store();
    $second = FileMagic::fromContent('second')->named('same')->store();

    expect($second->path)->not->toBe($first->path)
        ->and(Storage::disk('testing')->get($first->path))->toBe('first')
        ->and(Storage::disk('testing')->get($second->path))->toBe('second')
        ->and(StoredFile::query()->count())->toBe(2);
});

it('does not let a contending store inspect or mutate the path while a writer holds its lock', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);
    $contenderFailed = false;
    $contenderAttempted = false;
    $puts = 0;

    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->twice()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->with('files/same.txt')->andReturnFalse();
    $filesystem->shouldReceive('put')
        ->withArgs(function (
            string $path,
            mixed $stream,
            array $options,
        ) use (&$contenderAttempted, &$contenderFailed, &$puts): bool {
            $puts++;

            if ($contenderAttempted === false) {
                $contenderAttempted = true;

                try {
                    FileMagic::fromContent('contender')->named('same')->store();
                } catch (FileWriteFailed) {
                    $contenderFailed = true;
                }
            }

            return $path === 'files/same.txt' &&
                \is_resource($stream) &&
                $options === ['visibility' => 'private'];
        })
        ->andReturnTrue();

    $winner = FileMagic::fromContent('winner')->named('same')->store();

    expect($contenderFailed)->toBeTrue()
        ->and($winner)->toBeInstanceOf(StoredFile::class)
        ->and(StoredFile::query()->count())->toBe(1)
        ->and($puts)->toBe(1);
});

it('rechecks repeated unique suffixes under their candidate locks', function (): void {
    Storage::disk('testing')->put('files/same.txt', 'existing');
    Storage::disk('testing')->put('files/same-aaaaaaaaaaaa.txt', 'existing suffix');
    $suffixes = ['aaaaaaaaaaaa', 'bbbbbbbbbbbb'];

    Str::createRandomStringsUsing(static function (int $length) use (&$suffixes): string {
        return \array_shift($suffixes) ?? \str_repeat('c', $length);
    });

    try {
        $file = FileMagic::fromContent('new')->named('same')->store();
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($file->path)->toBe('files/same-bbbbbbbbbbbb.txt')
        ->and(Storage::disk('testing')->get('files/same.txt'))->toBe('existing')
        ->and(Storage::disk('testing')->get('files/same-aaaaaaaaaaaa.txt'))->toBe('existing suffix')
        ->and(Storage::disk('testing')->get($file->path))->toBe('new');
});

it('locks a generated suffix against an explicit request for that path', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);
    $contenderFailed = false;

    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->twice()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->ordered()->with('files/same.txt')->andReturnTrue();
    $filesystem->shouldReceive('exists')
        ->once()
        ->ordered()
        ->with('files/same-aaaaaaaaaaaa.txt')
        ->andReturnFalse();
    $filesystem->shouldReceive('put')
        ->once()
        ->ordered()
        ->withArgs(function (string $path, mixed $stream, array $options) use (&$contenderFailed): bool {
            try {
                FileMagic::fromContent('contender')->named('same-aaaaaaaaaaaa')->store();
            } catch (FileWriteFailed) {
                $contenderFailed = true;
            }

            return $path === 'files/same-aaaaaaaaaaaa.txt' &&
                \is_resource($stream) &&
                \stream_get_contents($stream) === 'winner' &&
                $options === ['visibility' => 'private'];
        })
        ->andReturnTrue();

    Str::createRandomStringsUsing(static fn (int $length): string => \str_repeat('a', $length));

    try {
        $winner = FileMagic::fromContent('winner')->named('same')->store();
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($contenderFailed)->toBeTrue()
        ->and($winner->path)->toBe('files/same-aaaaaaaaaaaa.txt')
        ->and(StoredFile::query()->count())->toBe(1);
});

it('keeps the path lock until overwrite recovery and backup cleanup finish', function (): void {
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Factory::class);
    $repository = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $lockProvider = Mockery::mock(LockProvider::class);
    $lock = Mockery::mock(LockContract::class);
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);
    $restoredStream = null;
    $events = [];
    $expectedKey = 'file-magic:collision:' . \hash('sha256', "testing\0files/same.txt");

    $cache->shouldReceive('store')->once()->with(null)->andReturn($repository);
    $repository->shouldReceive('getStore')->once()->andReturn($lockProvider);
    $lockProvider->shouldReceive('lock')->once()->with($expectedKey, 300)->andReturn($lock);
    $lock->shouldReceive('block')
        ->once()
        ->withArgs(static fn (int $wait, mixed $callback): bool => $wait === 1 && \is_callable($callback))
        ->andReturnUsing(function (int $wait, callable $callback) use (&$events, &$restoredStream): mixed {
            $events[] = 'lock acquired';

            try {
                return $callback();
            } finally {
                $events[] = \is_resource($restoredStream)
                    ? 'lock released before backup close'
                    : 'lock released after backup close';
            }
        });

    $this->app->instance(CollisionLock::class, new CollisionLock(
        $cache,
        app(FileMagicConfig::class),
    ));
    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/same.txt')->andReturnTrue();
    $filesystem->shouldReceive('getVisibility')->once()->with('files/same.txt')->andReturn('private');
    $filesystem->shouldReceive('readStream')->once()->with('files/same.txt')->andReturn(
        collisionStreamContaining('old'),
    );
    $filesystem->shouldReceive('put')->once()->ordered()->andThrow(new RuntimeException('write failed'));
    $filesystem->shouldReceive('put')
        ->once()
        ->ordered()
        ->withArgs(function (string $path, mixed $stream, array $options) use (&$restoredStream, &$events): bool {
            $restoredStream = $stream;
            $events[] = 'backup restored';

            return $path === 'files/same.txt' && $options === ['visibility' => 'private'];
        })
        ->andReturnTrue();

    expect(fn () => FileMagic::fromContent('new')
        ->named('same')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store())
        ->toThrow(FileWriteFailed::class);

    expect($events)->toBe([
        'lock acquired',
        'backup restored',
        'lock released after backup close',
    ]);
});

/**
 * Create a rewound temporary stream for collision tests.
 *
 * @return resource
 */
function collisionStreamContaining(string $contents)
{
    $stream = \tmpfile();

    if ($stream === false || \fwrite($stream, $contents) !== \strlen($contents) || \rewind($stream) === false) {
        throw new RuntimeException('The collision test stream could not be created.');
    }

    return $stream;
}

final class CollisionCountingSource implements FileSource
{
    public int $openedStreams = 0;

    public function __construct(private readonly string $contents) {}

    /** @return resource */
    public function openStream()
    {
        $this->openedStreams++;

        return (new ContentFileSource($this->contents))->openStream();
    }

    public function originalFilename(): ?string
    {
        return null;
    }

    public function clientMimeType(): ?string
    {
        return null;
    }
}
