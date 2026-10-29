<?php

namespace App\Exceptions;

use RuntimeException;

class UnpaidInvoiceLimitException extends RuntimeException
{
    public function __construct(string $paymentMethod = 'gopay_qris')
    {
        $label = match ($paymentMethod) {
            'crypto' => 'crypto',
            'binance_pay' => 'Binance Pay',
            default => 'QRIS',
        };

        parent::__construct('You have too many unpaid '.$label.' invoices. Continue an existing payment or contact support before creating another.');
    }
}
