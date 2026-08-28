<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Mattmy\FileMagic\Models\StoredFile;
use Override;
use RuntimeException;

final class FailingStoredFile extends StoredFile
{
    /**
     * Register a deterministic persistence failure for recovery tests.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (): never {
            throw new RuntimeException('Simulated stored-file persistence failure.');
        });
    }
}
