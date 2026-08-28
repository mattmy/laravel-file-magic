<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * @extends Builder<MismatchedDeleteStoredFile>
 */
final class MismatchedDeleteBuilder extends Builder
{
    /**
     * Delete matching rows while simulating an invalid affected-row count.
     */
    #[Override]
    public function delete(): int
    {
        parent::delete();

        return 0;
    }
}
