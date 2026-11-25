<?php

namespace App\Exceptions;

use DomainException;

class OtpRateLimitExceededException extends DomainException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 60,
        public readonly string $limitLayer = 'cooldown',
    ) {
        parent::__construct($message, 429);
    }
}
