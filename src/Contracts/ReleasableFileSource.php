<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Contracts;

interface ReleasableFileSource
{
    /**
     * Release temporary resources owned by the source.
     */
    public function release(): void;
}
