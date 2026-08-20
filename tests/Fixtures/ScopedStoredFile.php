<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Mattmy\FileMagic\Models\StoredFile;

final class ScopedStoredFile extends StoredFile
{
    /**
     * The custom database connection.
     *
     * @var string
     */
    protected $connection = 'audit';

    /**
     * The custom database table.
     *
     * @var string
     */
    protected $table = 'audit_stored_files';

    /**
     * The custom primary key.
     *
     * @var string
     */
    protected $primaryKey = 'file_key';

    /**
     * Register a scope that must not hide records from maintenance operations.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(
            'hidden-from-normal-queries',
            static fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
        );
    }
}
