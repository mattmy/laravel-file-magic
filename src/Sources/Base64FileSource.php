<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Exceptions\InvalidBase64;

final readonly class Base64FileSource implements FileSource
{
    private string $contents;

    private ?string $mimeType;

    /**
     * Decode a plain Base64 string or Data URI.
     */
    public function __construct(string $base64, private ?string $originalFilename = null)
    {
        [$encoded, $mimeType] = $this->parse($base64);
        $contents = \base64_decode($encoded, true);

        if ($contents === false || \base64_encode($contents) !== $encoded) {
            throw new InvalidBase64('The value is not valid canonical Base64.');
        }

        $this->contents = $contents;
        $this->mimeType = $mimeType;
    }

    /**
     * Copy the decoded content into a seekable temporary stream.
     *
     * @return resource
     */
    public function openStream()
    {
        return (new ContentFileSource($this->contents))->openStream();
    }

    /**
     * Return the optional original filename.
     */
    public function originalFilename(): ?string
    {
        return $this->originalFilename;
    }

    /**
     * Return the MIME type declared by a Data URI.
     */
    public function clientMimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Separate encoded data and MIME type.
     *
     * @return array{0: string, 1: string|null}
     */
    private function parse(string $base64): array
    {
        if (\str_starts_with($base64, 'data:') === false) {
            return [$base64, null];
        }

        if (\preg_match('/\Adata:([^;,]+);base64,(.*)\z/s', $base64, $matches) !== 1) {
            throw new InvalidBase64('The Data URI is not valid Base64.');
        }

        return [$matches[2], $matches[1]];
    }
}
