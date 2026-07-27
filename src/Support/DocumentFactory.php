<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use JsonSerializable;
use Mattmy\FileMagic\Exceptions\InvalidDocumentData;
use Mattmy\FileMagic\Sources\GeneratedDocumentSource;
use Throwable;

final class DocumentFactory
{
    private const string CSV_MIME_TYPE = 'text/csv';

    private const string JSON_MIME_TYPE = 'application/json';

    private const string TEXT_MIME_TYPE = 'text/plain';

    private const int JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * Create a UTF-8 plain-text document.
     */
    public function text(string $text): GeneratedDocumentSource
    {
        $this->ensureUtf8($text);

        return new GeneratedDocumentSource($text, self::TEXT_MIME_TYPE);
    }

    /**
     * Create a formatted JSON document from structured data.
     *
     * @param  array<array-key, mixed>|JsonSerializable  $data
     */
    public function json(array|JsonSerializable $data): GeneratedDocumentSource
    {
        try {
            $contents = \json_encode($data, self::JSON_FLAGS);
        } catch (Throwable $exception) {
            throw new InvalidDocumentData('The document data could not be encoded as JSON.', previous: $exception);
        }

        return new GeneratedDocumentSource($contents . "\n", self::JSON_MIME_TYPE);
    }

    /**
     * Create a UTF-8 CSV document from consistently shaped rows.
     *
     * @param  iterable<array-key, array<array-key, scalar|null>>  $rows
     */
    public function csv(iterable $rows): GeneratedDocumentSource
    {
        $stream = \fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new InvalidDocumentData('The CSV document could not be opened.');
        }

        try {
            $this->writeCsv($stream, $rows);
            \rewind($stream);
            $contents = \stream_get_contents($stream);

            if ($contents === false) {
                throw new InvalidDocumentData('The CSV document could not be read.');
            }

            return new GeneratedDocumentSource($contents, self::CSV_MIME_TYPE);
        } finally {
            \fclose($stream);
        }
    }

    /**
     * Write validated rows to a CSV stream.
     *
     * @param  resource  $stream
     * @param  iterable<array-key, mixed>  $rows
     */
    private function writeCsv($stream, iterable $rows): void
    {
        $expectedKeys = null;

        foreach ($rows as $row) {
            $row = $this->normalizeCsvRow($row);
            $keys = \array_keys($row);
            $this->ensureCsvShape($expectedKeys, $keys);

            if ($expectedKeys === null) {
                $expectedKeys = $keys;

                if (\array_is_list($row) === false) {
                    $this->writeCsvRow($stream, $this->csvHeaders($keys));
                }
            }

            $this->writeCsvRow($stream, \array_values($row));
        }
    }

    /**
     * Ensure every CSV row uses the same keys and key order.
     *
     * @param  list<int|string>|null  $expectedKeys
     * @param  list<int|string>  $keys
     */
    private function ensureCsvShape(?array $expectedKeys, array $keys): void
    {
        if ($expectedKeys !== null && $keys !== $expectedKeys) {
            throw new InvalidDocumentData('Every CSV row must use the same keys in the same order.');
        }
    }

    /**
     * Validate and normalize one CSV row at the public input boundary.
     *
     * @return array<array-key, scalar|null>
     */
    private function normalizeCsvRow(mixed $row): array
    {
        if (\is_array($row) === false) {
            throw new InvalidDocumentData('Every CSV row must be an array.');
        }

        $normalized = [];

        foreach ($row as $key => $value) {
            if (\is_scalar($value) === false && $value !== null) {
                throw new InvalidDocumentData('Every CSV value must be scalar or null.');
            }

            if (\is_string($value)) {
                $this->ensureUtf8($value);
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Convert validated associative keys into UTF-8 CSV headers.
     *
     * @param  list<int|string>  $keys
     * @return list<string>
     */
    private function csvHeaders(array $keys): array
    {
        return \array_map(function (int|string $key): string {
            $header = (string) $key;
            $this->ensureUtf8($header);

            return $header;
        }, $keys);
    }

    /**
     * Write one row using RFC 4180-compatible defaults.
     *
     * @param  resource  $stream
     * @param  list<scalar|null>  $row
     */
    private function writeCsvRow($stream, array $row): void
    {
        if (\fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new InvalidDocumentData('The CSV row could not be written.');
        }
    }

    /**
     * Reject byte sequences that are not valid UTF-8 text.
     */
    private function ensureUtf8(string $text): void
    {
        if (\preg_match('//u', $text) !== 1) {
            throw new InvalidDocumentData('Document text must be valid UTF-8.');
        }
    }
}
