<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Mattmy\FileMagic\Models\StoredFile;
use Override;

final class GloballyScopedStoredFile extends StoredFile
{
    /**
     * Hide every record from consumer-scoped queries.
     */
    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('hidden', static fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }
}
