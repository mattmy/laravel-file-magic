<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Symfony\Component\Mime\MimeTypes;

final class ExtensionResolver
{
    private const string FALLBACK_EXTENSION = 'bin';

    /**
     * Resolve the preferred extension for a trusted MIME type.
     */
    public function resolve(string $mimeType): string
    {
        $extension = MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? self::FALLBACK_EXTENSION;

        return \strtolower($extension);
    }
}
