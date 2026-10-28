<?php

namespace App\Exceptions;

use RuntimeException;

class UnpaidInvoiceLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You have too many unpaid QRIS invoices. Continue an existing payment or contact support before creating another.');
    }
}
