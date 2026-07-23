<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mattmy\FileMagic\FileMagicServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Create the package table for each isolated test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stored_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk');
            $table->string('path', 2048);
            $table->char('location_hash', 64)->unique();
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('extension', 32);
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 128)->nullable()->index();
            $table->string('visibility', 16);
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });
    }

    /**
     * Drop the package table after each isolated test.
     */
    protected function tearDown(): void
    {
        Schema::dropIfExists('stored_files');

        parent::tearDown();
    }

    /**
     * Register the package service provider.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FileMagicServiceProvider::class];
    }

    /**
     * Configure the isolated Laravel application.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('filesystems.default', 'testing');
        $app['config']->set('filesystems.disks.testing', [
            'driver' => 'local',
            'root' => \sys_get_temp_dir() . '/file-magic-tests',
            'throw' => false,
        ]);
        $app['config']->set('file-magic.disk', 'testing');
    }
}
