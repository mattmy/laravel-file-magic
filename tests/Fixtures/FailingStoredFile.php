<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Mattmy\FileMagic\Models\StoredFile;
use RuntimeException;

final class FailingStoredFile extends StoredFile
{
    /**
     * Register a deterministic persistence failure for recovery tests.
     */
    protected static function booted(): void
    {
        self::saving(static function (): never {
            throw new RuntimeException('Simulated stored-file persistence failure.');
        });
    }
}
