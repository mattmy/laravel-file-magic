<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Sources;

use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\SizeLimitedFileSource;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidBase64;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;

final class Base64FileSource implements FileSource, SizeLimitedFileSource
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private readonly string $encoded;

    private readonly int $decodedSize;

    private readonly ?string $mimeType;

    private ?string $decodedContents = null;

    private ?int $maximumBytes = null;

    /**
     * Parse and validate canonical Base64 without decoding its complete contents.
     */
    public function __construct(string $base64, private readonly ?string $originalFilename = null)
    {
        [$encoded, $mimeType] = $this->parse($base64);

        if (
            \preg_match(
                '/\A(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?\z/',
                $encoded,
            ) !== 1 ||
            $this->hasNonCanonicalFinalQuantum($encoded)
        ) {
            throw new InvalidBase64('The value is not valid canonical Base64.');
        }

        $padding = \str_ends_with($encoded, '==') ? 2 : (\str_ends_with($encoded, '=') ? 1 : 0);

        $this->encoded = $encoded;
        $this->decodedSize = \intdiv(\strlen($encoded), 4) * 3 - $padding;
        $this->mimeType = $mimeType;
    }

    /**
     * Apply the maximum decoded byte limit before materialization.
     */
    public function limitSize(int $bytes): void
    {
        $this->maximumBytes = $bytes;

        if ($this->decodedSize > $bytes) {
            throw new FileTooLarge("The Base64 file exceeds the {$bytes} byte limit.");
        }
    }

    /**
     * Copy the decoded content into a seekable temporary stream.
     *
     * @return resource
     */
    public function openStream()
    {
        if ($this->maximumBytes === null) {
            throw new InvalidFileSource('The Base64 file size limit was not initialized.');
        }

        if ($this->decodedContents === null) {
            $contents = \base64_decode($this->encoded, true);

            if ($contents === false) {
                throw new InvalidBase64('The value is not valid canonical Base64.');
            }

            $this->decodedContents = $contents;
        }

        return (new ContentFileSource($this->decodedContents))->openStream();
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

    /**
     * Determine whether the padded final quantum uses non-zero discarded bits.
     */
    private function hasNonCanonicalFinalQuantum(string $encoded): bool
    {
        if ($encoded === '' || \str_ends_with($encoded, '=') === false) {
            return false;
        }

        $character = \str_ends_with($encoded, '==')
            ? $encoded[\strlen($encoded) - 3]
            : $encoded[\strlen($encoded) - 2];
        $value = \strpos(self::ALPHABET, $character);

        return $value === false || ($value & (\str_ends_with($encoded, '==') ? 15 : 3)) !== 0;
    }
}
