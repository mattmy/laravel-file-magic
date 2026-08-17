<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Exceptions\InvalidFileName;
use Mattmy\FileMagic\Exceptions\InvalidStoragePath;

final class PathNormalizer
{
    public const int MAX_FILENAME_LENGTH = 200;

    private const array RESERVED_NAMES = [
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

    /**
     * Normalize a relative storage directory.
     */
    public function directory(string $directory): string
    {
        if ($directory === '') {
            return '';
        }

        if (
            \str_starts_with($directory, '/') ||
            \str_ends_with($directory, '/') ||
            \str_contains($directory, '//') ||
            \str_contains($directory, '\\')
        ) {
            throw new InvalidStoragePath('The storage directory must be a safe relative path.');
        }

        foreach (\explode('/', $directory) as $segment) {
            if ($this->unsafeSegment($segment, allowLeadingDot: true)) {
                throw new InvalidStoragePath('The storage directory must be a safe relative path.');
            }
        }

        return $directory;
    }

    /**
     * Validate and normalize a filename without its extension.
     */
    public function filename(string $filename): string
    {
        if (
            $filename === '' ||
            $this->unsafeSegment($filename, allowLeadingDot: false) ||
            \mb_strlen($filename) > self::MAX_FILENAME_LENGTH
        ) {
            throw new InvalidFileName('The filename contains unsupported characters or a reserved name.');
        }

        return $filename;
    }

    /**
     * Determine whether a path segment is ambiguous, unsafe, or reserved.
     */
    private function unsafeSegment(string $segment, bool $allowLeadingDot): bool
    {
        $stem = \explode('.', $segment, 2)[0];
        $unsafeCharacters = \preg_match('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/u', $segment);

        return $segment === '' ||
            $segment === '.' ||
            $segment === '..' ||
            \trim($segment) !== $segment ||
            $unsafeCharacters !== 0 ||
            ($allowLeadingDot === false && \str_starts_with($segment, '.')) ||
            \str_ends_with($segment, '.') ||
            \str_ends_with($segment, ' ') ||
            \in_array(\strtoupper($stem), self::RESERVED_NAMES, true);
    }
}
