<?php

namespace App\Exceptions;

use DomainException;

class InvalidInvoiceStateTransitionException extends DomainException
{
    public function __construct(string $from, string $to, string $context = '')
    {
        $suffix = $context !== '' ? " ({$context})" : '';
        parent::__construct("Invalid payment invoice state transition from [{$from}] to [{$to}]{$suffix}.");
    }
}
