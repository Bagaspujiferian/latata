<?php

namespace App\Support;

use App\Exceptions\InvalidInvoiceStateTransitionException;
use App\Exceptions\InvalidOrderStateTransitionException;

final class StateTransitionGuard
{
    /** @var array<string, list<string>> */
    public const ORDER_TRANSITIONS = [
        'WAITING_PAYMENT' => ['PAID_ESCROW', 'CANCELLED'],
        'PAID_ESCROW' => ['DELIVERED', 'PROCESSING', 'REFUNDED'],
        'PROCESSING' => ['DELIVERED', 'REFUNDED'],
        'DELIVERED' => ['COMPLETED', 'DISPUTED'],
        'DISPUTED' => ['COMPLETED', 'REFUNDED'],
        'COMPLETED' => [],
        'REFUNDED' => [],
        'CANCELLED' => [],
    ];

    /** @var array<string, list<string>> */
    public const INVOICE_TRANSITIONS = [
        'UNPAID' => ['PAID', 'EXPIRED', 'FAILED'],
        'EXPIRED' => ['PAID_LATE_REFUNDED'],
        'PAID' => [],
        'PAID_LATE_REFUNDED' => [],
        'FAILED' => [],
    ];

    public static function assertOrderTransition(string $from, string $to, string $context = ''): void
    {
        $allowed = self::ORDER_TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new InvalidOrderStateTransitionException($from, $to, $context);
        }
    }

    public static function assertInvoiceTransition(string $from, string $to, string $context = ''): void
    {
        $allowed = self::INVOICE_TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new InvalidInvoiceStateTransitionException($from, $to, $context);
        }
    }

    public static function canOrderTransition(string $from, string $to): bool
    {
        return in_array($to, self::ORDER_TRANSITIONS[$from] ?? [], true);
    }

    public static function canInvoiceTransition(string $from, string $to): bool
    {
        return in_array($to, self::INVOICE_TRANSITIONS[$from] ?? [], true);
    }
}
