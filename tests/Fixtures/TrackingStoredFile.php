<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Mattmy\FileMagic\Models\StoredFile;
use Override;

final class TrackingStoredFile extends StoredFile
{
    public static int $queriesWithoutScopes = 0;

    /**
     * The custom model primary key.
     *
     * @var string
     */
    protected $primaryKey = 'file_id';

    /**
     * Track internal unscoped batch queries for integration tests.
     *
     * @return Builder<static>
     */
    #[Override]
    public function newQueryWithoutScopes(): Builder
    {
        self::$queriesWithoutScopes++;

        return parent::newQueryWithoutScopes();
    }
}
