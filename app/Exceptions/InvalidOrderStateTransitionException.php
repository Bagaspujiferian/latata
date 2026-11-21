<?php

namespace App\Exceptions;

use DomainException;

class InvalidOrderStateTransitionException extends DomainException
{
    public function __construct(string $from, string $to, string $context = '')
    {
        $suffix = $context !== '' ? " ({$context})" : '';
        parent::__construct("Invalid order state transition from [{$from}] to [{$to}]{$suffix}.");
    }
}
