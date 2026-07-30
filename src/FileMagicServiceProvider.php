<?php

declare(strict_types=1);

namespace Mattmy\FileMagic;

use Illuminate\Support\ServiceProvider;
use Mattmy\FileMagic\Commands\AuditFilesCommand;
use Mattmy\FileMagic\Contracts\HostResolver;
use Mattmy\FileMagic\Support\NativeHostResolver;

final class FileMagicServiceProvider extends ServiceProvider
{
    /**
     * Register package services and configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/file-magic.php', 'file-magic');
        $this->app->bind(HostResolver::class, NativeHostResolver::class);
        $this->app->singleton(FileMagic::class);
    }

    /**
     * Register publishable package resources.
     */
    public function boot(): void
    {
        $timestamp = \date('Y_m_d_His');

        if ($this->app->runningInConsole()) {
            $this->commands([AuditFilesCommand::class]);
        }

        $this->publishes([
            __DIR__ . '/../config/file-magic.php' => \config_path('file-magic.php'),
        ], 'file-magic-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_stored_files_table.php.stub' => \database_path("migrations/{$timestamp}_create_stored_files_table.php"),
        ], 'file-magic-migrations');
    }
}
