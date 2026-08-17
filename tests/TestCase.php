<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests;

use Illuminate\Database\Migrations\Migration;
use Mattmy\FileMagic\FileMagicServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

abstract class TestCase extends Orchestra
{
    private Migration $migration;

    /**
     * Run the publishable package migration for each isolated test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__ . '/../database/migrations/create_stored_files_table.php.stub';

        if ($migration instanceof Migration === false) {
            throw new RuntimeException('The FileMagic migration stub must return a Migration instance.');
        }

        $this->migration = $migration;
        $this->migration->up();
    }

    /**
     * Roll back the publishable package migration after each isolated test.
     */
    protected function tearDown(): void
    {
        $this->migration->down();

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
        $app['config']->set('database.connections.testing', $this->databaseConfiguration());
        $app['config']->set('filesystems.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('file-magic.collision_lock.wait_seconds', 1);
        $app['config']->set('filesystems.disks.testing', [
            'driver' => 'local',
            'root' => \sys_get_temp_dir() . '/file-magic-tests',
            'throw' => false,
        ]);
        $app['config']->set('file-magic.disk', 'testing');
    }

    /**
     * Return the configured SQLite or MySQL test connection.
     *
     * @return array<string, bool|int|string>
     */
    private function databaseConfiguration(): array
    {
        if (\getenv('FILE_MAGIC_TEST_DATABASE') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => (string) (\getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (\getenv('DB_PORT') ?: 3306),
            'database' => (string) (\getenv('DB_DATABASE') ?: 'file_magic'),
            'username' => (string) (\getenv('DB_USERNAME') ?: 'root'),
            'password' => (string) (\getenv('DB_PASSWORD') ?: ''),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}
