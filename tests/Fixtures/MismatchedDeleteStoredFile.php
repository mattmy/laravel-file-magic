<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Mattmy\FileMagic\Models\StoredFile;
use Override;

final class MismatchedDeleteStoredFile extends StoredFile
{
    /**
     * Create a builder that reports a deterministic affected-row mismatch.
     *
     * @param  QueryBuilder  $query
     * @return Builder<static>
     */
    #[Override]
    public function newEloquentBuilder($query): Builder
    {
        return new MismatchedDeleteBuilder($query);
    }
}
