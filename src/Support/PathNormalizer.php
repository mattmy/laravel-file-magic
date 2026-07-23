<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Exceptions\InvalidFileName;
use Mattmy\FileMagic\Exceptions\InvalidStoragePath;

final class PathNormalizer
{
    /**
     * Normalize a relative storage directory.
     */
    public function directory(string $directory): string
    {
        $directory = \str_replace('\\', '/', \trim($directory));
        $segments = \array_values(\array_filter(
            \explode('/', $directory),
            static fn (string $segment): bool => $segment !== '',
        ));

        if (
            $directory === '' ||
            \str_starts_with($directory, '/') ||
            \preg_match('/\A[A-Za-z]:\//', $directory) === 1 ||
            \in_array('..', $segments, true) ||
            \in_array('.', $segments, true) ||
            \str_contains($directory, "\0")
        ) {
            throw new InvalidStoragePath('The storage directory must be a safe relative path.');
        }

        return \implode('/', $segments);
    }

    /**
     * Validate and normalize a filename without its extension.
     */
    public function filename(string $filename): string
    {
        $filename = \trim($filename);
        $reservedNames = [
            'AUX',
            'CON',
            'NUL',
            'PRN',
            'COM1',
            'COM2',
            'COM3',
            'COM4',
            'COM5',
            'COM6',
            'COM7',
            'COM8',
            'COM9',
            'LPT1',
            'LPT2',
            'LPT3',
            'LPT4',
            'LPT5',
            'LPT6',
            'LPT7',
            'LPT8',
            'LPT9',
        ];

        if (
            $filename === '' ||
            \mb_strlen($filename) > 200 ||
            \preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/u', $filename) === 1 ||
            \str_starts_with($filename, '.') ||
            \str_ends_with($filename, '.') ||
            \str_ends_with($filename, ' ') ||
            \in_array(\strtoupper($filename), $reservedNames, true)
        ) {
            throw new InvalidFileName('The filename contains unsupported characters or a reserved name.');
        }

        return $filename;
    }
}
