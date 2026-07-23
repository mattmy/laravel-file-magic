<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Enums;

enum FileVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
