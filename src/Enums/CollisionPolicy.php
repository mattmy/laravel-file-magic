<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Enums;

enum CollisionPolicy: string
{
    case Error = 'error';
    case Overwrite = 'overwrite';
    case Unique = 'unique';
}
