<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

class BrModsResetException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'unavailable',
        public readonly ?CarbonInterface $availableAt = null,
    ) {
        parent::__construct($message);
    }
}
