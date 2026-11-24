<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\DigiflazzH2HService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchH2HOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $orderId,
    ) {
        $this->afterCommit();
    }

    /**
     * Idempotently dispatch an H2H order to Digiflazz after the escrow database transaction commits.
     */
    public function handle(DigiflazzH2HService $h2hService): void
    {
        $order = Order::with(['items.product', 'paymentInvoice'])->find($this->orderId);

        if (! $order || $order->delivery_method !== 'api_h2h') {
            return;
        }

        if (! in_array($order->status, ['PAID_ESCROW', 'PROCESSING'], true)) {
            return;
        }

        // Idempotency guard: if already dispatched to provider (h2h_reference_id or h2h_response_log present), skip API call.
        if ($order->h2h_reference_id !== null || ! empty($order->h2h_response_log)) {
            return;
        }

        $h2hService->dispatchTopUp($order);
    }
}
