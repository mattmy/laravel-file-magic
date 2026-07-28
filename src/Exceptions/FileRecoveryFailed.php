<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Exceptions;

use Throwable;

final class FileRecoveryFailed extends FileMagicException
{
    /**
     * Create an exception containing both the operation and recovery failures.
     */
    public function __construct(
        string $message,
        private readonly Throwable $operationFailure,
        Throwable $recoveryFailure,
    ) {
        parent::__construct($message, previous: $recoveryFailure);
    }

    /**
     * Return the failure that originally triggered recovery.
     */
    public function operationFailure(): Throwable
    {
        return $this->operationFailure;
    }
}
