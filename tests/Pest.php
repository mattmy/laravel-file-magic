<?php

declare(strict_types=1);

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
