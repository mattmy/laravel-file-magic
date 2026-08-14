<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Mattmy\FileMagic\Models\StoredFile;

final class GloballyScopedStoredFile extends StoredFile
{
    protected static function booted(): void
    {
        self::addGlobalScope('hidden', static fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }
}
