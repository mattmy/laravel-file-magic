<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Mattmy\FileMagic\Actions\StoreFile;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\PendingFile;
use Mattmy\FileMagic\Support\FileMagicConfig;
use Mattmy\FileMagic\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Create a pending file with explicit package dependencies for source tests.
 */
function pendingFile(FileSource $source): PendingFile
{
    return new PendingFile(
        $source,
        app(StoreFile::class),
        app(FileMagicConfig::class),
    );
}

/**
 * Return the integer key used by the package test models.
 */
function storedFileKey(Model $model): int
{
    $key = $model->getKey();

    if (! is_int($key)) {
        throw new RuntimeException('The stored file test model must have an integer key.');
    }

    return $key;
}
