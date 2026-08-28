<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Support\CollisionLock;
use Mattmy\FileMagic\Support\FileMagicConfig;
use Mattmy\FileMagic\Tests\Fixtures\MismatchedDeleteStoredFile;
use Mattmy\FileMagic\Tests\Fixtures\ScopedStoredFile;
use Mockery\MockInterface;

beforeEach(function (): void {
    Storage::fake('testing');
    Storage::fake('other');
});

afterEach(function (): void {
    \config()->set('file-magic.model', StoredFile::class);
    \config()->set('file-magic.table', 'stored_files');
});

it('reports a clean read-only audit', function (): void {
    FileMagic::fromContent('healthy')->store();

    $this->artisan('file-magic:audit')
        ->expectsOutputToContain('1')
        ->assertExitCode(0);

    expect(StoredFile::query()->count())->toBe(1);
});

it('reports missing records without changing the database', function (): void {
    $file = FileMagic::fromContent('missing')->store();
    Storage::disk('testing')->delete($file->path);

    $this->artisan('file-magic:audit')
        ->expectsOutputToContain("MISSING key={$file->id} disk=testing path={$file->path}")
        ->assertExitCode(1);

    expect($file->fresh())->not->toBeNull();
});

it('keeps unknown records and performs exactly one existence check per record', function (): void {
    $healthy = FileMagic::fromContent('healthy')->store();
    $unknown = FileMagic::fromContent('unknown')->store();
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);

    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with($healthy->path)->andReturnTrue();
    $filesystem->shouldReceive('exists')->once()->with($unknown->path)->andThrow(
        new RuntimeException('Storage unavailable.'),
    );
    app()->instance(FilesystemFactory::class, $factory);

    $this->artisan('file-magic:audit')->assertExitCode(2);

    expect(StoredFile::query()->count())->toBe(2);
});

it('filters one configured disk', function (): void {
    $testing = FileMagic::fromContent('testing')->onDisk('testing')->store();
    $other = FileMagic::fromContent('other')->onDisk('other')->store();
    Storage::disk('testing')->delete($testing->path);
    Storage::disk('other')->delete($other->path);

    $this->artisan('file-magic:audit', ['--disk' => 'testing'])
        ->expectsOutputToContain("MISSING key={$testing->id}")
        ->doesntExpectOutputToContain("MISSING key={$other->id}")
        ->assertExitCode(1);
});

it('rejects invalid options before touching storage', function (array $options): void {
    $factory = Mockery::mock(FilesystemFactory::class);
    $factory->shouldNotReceive('disk');
    app()->instance(FilesystemFactory::class, $factory);

    $this->artisan('file-magic:audit', $options)->assertExitCode(2);
})->with([
    'unknown disk' => [['--disk' => 'unknown']],
    'empty chunk' => [['--chunk' => '']],
    'zero chunk' => [['--chunk' => '0']],
    'negative chunk' => [['--chunk' => '-1']],
    'chunk above maximum' => [['--chunk' => '5001']],
    'non-integer chunk' => [['--chunk' => '1.5']],
    'force without deletion' => [['--force' => true]],
]);

it('refuses deletion without scanning', function (): void {
    $file = FileMagic::fromContent('missing')->store();
    Storage::disk('testing')->delete($file->path);

    $this->artisan('file-magic:audit', ['--delete-missing-records' => true])
        ->expectsConfirmation(
            'This will permanently delete database records for confirmed missing storage objects. Continue?',
            'no',
        )
        ->expectsOutputToContain('Audit cancelled')
        ->assertExitCode(0);

    expect($file->fresh())->not->toBeNull();
});

it('accepts interactive deletion confirmation', function (): void {
    $file = FileMagic::fromContent('missing')->store();
    Storage::disk('testing')->delete($file->path);

    $this->artisan('file-magic:audit', ['--delete-missing-records' => true])
        ->expectsConfirmation(
            'This will permanently delete database records for confirmed missing storage objects. Continue?',
            'yes',
        )
        ->assertExitCode(0);

    expect($file->fresh())->toBeNull();
});

it('keeps a cleanup candidate when its object reappears after locking', function (): void {
    $file = FileMagic::fromContent('reappeared')->store();
    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);

    \config()->set('file-magic.collision_lock.enabled', true);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->twice()->with($file->path)->andReturn(false, true);
    app()->instance(FilesystemFactory::class, $factory);

    $this->artisan('file-magic:audit', [
        '--delete-missing-records' => true,
        '--force' => true,
    ])->assertExitCode(0);

    expect($file->fresh())->not->toBeNull();
});

it('keeps a cleanup candidate whose identity changes after locking', function (): void {
    $file = FileMagic::fromContent('changed')->store();
    Storage::disk('testing')->delete($file->path);

    installAuditLock(function () use ($file): bool {
        StoredFile::query()->whereKey($file->id)->update([
            'path' => 'files/replacement.txt',
            'location_hash' => \hash('sha256', "testing\0files/replacement.txt"),
        ]);

        return true;
    });

    $this->artisan('file-magic:audit', [
        '--delete-missing-records' => true,
        '--force' => true,
    ])->assertExitCode(2);

    expect($file->fresh()?->path)->toBe('files/replacement.txt');
});

it('requires force for non-interactive deletion', function (): void {
    FileMagic::fromContent('record')->store();

    $exitCode = Artisan::call('file-magic:audit', [
        '--delete-missing-records' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(2)
        ->and(StoredFile::query()->count())->toBe(1);
});

it('deletes confirmed missing records in one bulk query per chunk', function (): void {
    $files = collect([
        FileMagic::fromContent('first')->store(),
        FileMagic::fromContent('second')->store(),
        FileMagic::fromContent('third')->store(),
    ]);

    foreach ($files as $file) {
        Storage::disk('testing')->delete($file->path);
    }

    $deleteQueries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$deleteQueries): void {
        if (\str_starts_with(\strtolower($query->sql), 'delete')) {
            $deleteQueries++;
        }
    });

    $this->artisan('file-magic:audit', [
        '--chunk' => '2',
        '--delete-missing-records' => true,
        '--force' => true,
    ])->assertExitCode(0);

    expect(StoredFile::query()->count())->toBe(0)
        ->and($deleteQueries)->toBe(2);
});

it('stops with a failure when affected rows do not match', function (): void {
    \config()->set('file-magic.model', MismatchedDeleteStoredFile::class);
    $file = FileMagic::fromContent('missing')->store();
    Storage::disk('testing')->delete($file->path);

    $this->artisan('file-magic:audit', [
        '--delete-missing-records' => true,
        '--force' => true,
    ])->assertExitCode(2);

    expect(StoredFile::query()->count())->toBe(0);
});

it('returns a failure when the database query cannot run', function (): void {
    \config()->set('file-magic.table', 'missing_stored_files_table');

    $this->artisan('file-magic:audit')->assertExitCode(2);
});

it('uses a custom connection table primary key and ignores global scopes', function (): void {
    \config()->set('database.connections.audit', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    Schema::connection('audit')->create('audit_stored_files', static function (Blueprint $table): void {
        $table->id('file_key');
        $table->string('disk');
        $table->string('path');
        $table->string('location_hash', 64);
    });
    \config()->set('file-magic.model', ScopedStoredFile::class);
    \config()->set('file-magic.table', 'audit_stored_files');
    DB::connection('audit')->table('audit_stored_files')->insert([
        'disk' => 'testing',
        'path' => 'missing/custom.txt',
        'location_hash' => \hash('sha256', "testing\0missing/custom.txt"),
    ]);

    $this->artisan('file-magic:audit', [
        '--delete-missing-records' => true,
        '--force' => true,
    ])->assertExitCode(0);

    expect(DB::connection('audit')->table('audit_stored_files')->count())->toBe(0);
});

it('processes large record sets using the requested chunk size', function (): void {
    $records = [];

    for ($index = 1; $index <= 11; $index++) {
        $records[] = [
            'uuid' => \sprintf('00000000-0000-4000-8000-%012d', $index),
            'disk' => 'testing',
            'path' => "missing/{$index}.txt",
            'location_hash' => \hash('sha256', "testing\0missing/{$index}.txt"),
            'filename' => "{$index}.txt",
            'original_filename' => "{$index}.txt",
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'checksum' => \str_repeat('a', 64),
            'visibility' => 'private',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    StoredFile::query()->insert($records);
    $selectQueries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$selectQueries): void {
        if (
            \str_starts_with(\strtolower($query->sql), 'select') &&
            \str_contains($query->sql, 'stored_files')
        ) {
            $selectQueries++;
        }
    });

    $this->artisan('file-magic:audit', ['--chunk' => '3'])->assertExitCode(1);

    expect($selectQueries)->toBe(4);
});

/**
 * Install one controlled audit lock at the cache boundary.
 *
 * @param  callable(): bool  $acquire
 */
function installAuditLock(callable $acquire): void
{
    /** @var CacheFactory&MockInterface $cache */
    $cache = Mockery::mock(CacheFactory::class);
    $repository = Mockery::mock(Repository::class);
    $provider = Mockery::mock(LockProvider::class);
    $lock = Mockery::mock(LockContract::class);

    \config()->set('file-magic.collision_lock.enabled', true);
    $cache->shouldReceive('store')->once()->with(null)->andReturn($repository);
    $repository->shouldReceive('getStore')->once()->andReturn($provider);
    $provider->shouldReceive('lock')->once()->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(1)->andReturnUsing($acquire);
    $lock->shouldReceive('release')->once()->andReturnTrue();
    $lock->shouldNotReceive('forceRelease');

    app()->instance(CollisionLock::class, new CollisionLock(
        $cache,
        app(FileMagicConfig::class),
    ));
}
