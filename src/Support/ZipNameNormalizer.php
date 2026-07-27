<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Exceptions\InvalidFileName;

final readonly class ZipNameNormalizer
{
    private const string DEFAULT_NAME_PREFIX = 'files-';

    private const int RANDOM_NAME_BYTES = 8;

    private const string ZIP_EXTENSION = '.zip';

    /**
     * Create the ZIP name normalizer.
     */
    public function __construct(private PathNormalizer $paths) {}

    /**
     * Normalize a requested ZIP download name and append its extension.
     */
    public function downloadName(?string $name): string
    {
        if ($name === null) {
            return self::DEFAULT_NAME_PREFIX
                . \bin2hex(\random_bytes(self::RANDOM_NAME_BYTES))
                . self::ZIP_EXTENSION;
        }

        $name = \trim($name);

        if (\str_ends_with(\strtolower($name), self::ZIP_EXTENSION)) {
            $name = \substr($name, 0, -\strlen(self::ZIP_EXTENSION));
        }

        return $this->paths->filename($name) . self::ZIP_EXTENSION;
    }

    /**
     * Normalize an archive entry name without retaining source directories.
     */
    public function entryName(string $name, string $fallback): string
    {
        $name = \basename(\str_replace('\\', '/', $name));
        $name = \preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '_', $name);

        if ($name === null) {
            throw new InvalidFileName('The archive entry name contains invalid UTF-8.');
        }

        $name = \trim($name, " .\t\n\r\0\x0B");

        if ($name === '') {
            $name = $fallback;
        }

        $extensionPosition = \strrpos($name, '.');
        $stem = $extensionPosition === false ? $name : \substr($name, 0, $extensionPosition);

        try {
            $this->paths->filename($stem);
        } catch (InvalidFileName) {
            $name = $fallback;
        }

        return $this->truncate($name);
    }

    /**
     * Produce a unique archive entry name while preserving its extension.
     *
     * @param  array<string, true>  $usedNames
     */
    public function uniqueEntryName(string $name, array $usedNames): string
    {
        $candidate = $name;
        $suffix = 2;

        while (isset($usedNames[\strtolower($candidate)])) {
            $candidate = $this->withSuffix($name, $suffix);
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Limit an archive entry to the package filename boundary.
     */
    private function truncate(string $name): string
    {
        return \mb_substr($name, 0, PathNormalizer::MAX_FILENAME_LENGTH);
    }

    /**
     * Add a numeric collision suffix before the final extension.
     */
    private function withSuffix(string $name, int $suffix): string
    {
        $extensionPosition = \strrpos($name, '.');
        $extension = $extensionPosition === false ? '' : \substr($name, $extensionPosition);
        $stem = $extensionPosition === false ? $name : \substr($name, 0, $extensionPosition);
        $suffixText = " ({$suffix})";
        $maximumStemLength = PathNormalizer::MAX_FILENAME_LENGTH
            - \mb_strlen($extension)
            - \mb_strlen($suffixText);

        return \mb_substr($stem, 0, \max(1, $maximumStemLength))
            . $suffixText
            . $extension;
    }
}
