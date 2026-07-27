<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Data;

use InvalidArgumentException;

final readonly class ImageOptions
{
    public const int MINIMUM_QUALITY = 1;

    public const int MAXIMUM_QUALITY = 100;

    /**
     * Describe an optional image resize and encoding operation.
     */
    public function __construct(
        public int $maxWidth,
        public int $quality,
    ) {
        if ($this->maxWidth < 1) {
            throw new InvalidArgumentException('The image maximum width must be greater than zero.');
        }

        if ($this->quality < self::MINIMUM_QUALITY || $this->quality > self::MAXIMUM_QUALITY) {
            throw new InvalidArgumentException('The image quality must be between 1 and 100.');
        }
    }
}
