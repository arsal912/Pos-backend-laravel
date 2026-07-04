<?php

namespace App\Exceptions;

use RuntimeException;

class CreditLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Credit limit exceeded.',
        public readonly float $currentBalance = 0,
        public readonly float $creditLimit = 0,
        public readonly float $requestedAmount = 0
    ) {
        parent::__construct($message);
    }
}
