<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;

class BrModsResetException extends LicenseResetException
{
    public function __construct(
        string $message,
        string $reason = 'unavailable',
        ?CarbonInterface $availableAt = null,
    ) {
        parent::__construct($message, $reason, $availableAt);
    }
}
