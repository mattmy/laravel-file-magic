<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Exceptions;

use Throwable;

final class PartialFileDeletion extends FileMagicException
{
    /**
     * @param  list<int|string>  $failedKeys
     */
    public function __construct(
        string $message,
        private readonly int $deletedCount,
        private readonly array $failedKeys,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Return the number of database records removed for confirmed missing objects.
     */
    public function deletedCount(): int
    {
        return $this->deletedCount;
    }

    /**
     * Return the number of objects that still exist or could not be verified.
     */
    public function failedCount(): int
    {
        return \count($this->failedKeys);
    }

    /**
     * Return the model keys whose deletion did not complete.
     *
     * @return list<int|string>
     */
    public function failedKeys(): array
    {
        return $this->failedKeys;
    }
}
