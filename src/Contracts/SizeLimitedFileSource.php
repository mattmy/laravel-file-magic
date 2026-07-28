<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Contracts;

interface SizeLimitedFileSource
{
    /**
     * Apply the maximum number of bytes that may be materialized.
     */
    public function limitSize(int $bytes): void;
}
