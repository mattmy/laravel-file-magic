<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Psr\Http\Message\StreamInterface;

final class LimitedWriteStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $bytesWritten = 0;

    /**
     * Wrap a writable resource with a strict byte limit.
     *
     * @param  resource  $resource
     */
    public function __construct($resource, private readonly int $maximumBytes)
    {
        $this->stream = Utils::streamFor($resource);
    }

    /**
     * Write bytes unless doing so would exceed the configured limit.
     */
    public function write(string $string): int
    {
        $bytes = \strlen($string);

        if ($this->bytesWritten + $bytes > $this->maximumBytes) {
            throw new FileTooLarge("The remote file exceeds the {$this->maximumBytes} byte limit.");
        }

        $written = $this->stream->write($string);
        $this->bytesWritten += $written;

        return $written;
    }
}
