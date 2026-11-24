<?php

namespace App\Services;

use App\Models\PaymentInvoice;
use App\Models\WalletTopUp;
use DomainException;
use Illuminate\Support\Facades\Http;

class MidtransSnapGatewayService
{
    public function __construct(
        private readonly MidtransWebhookService $midtransWebhookService,
    ) {}

    public function isLiveSnapEnabled(): bool
    {
        $driver = (string) config('nexatrade.midtrans.payment_driver', 'simulation');

        return in_array($driver, ['midtrans_sandbox', 'midtrans_production'], true);
    }

    /**
     * Request or reuse an active Midtrans Snap token for an UNPAID PaymentInvoice.
     * External HTTP call is strictly executed outside any database transaction.
     *
     * @return array{snap_token: string, redirect_url: string}
     */
    public function createOrReuseInvoiceSnapToken(PaymentInvoice $invoice): array
    {
        if ($invoice->status !== 'UNPAID') {
            throw new DomainException("Tagihan {$invoice->invoice_code} sudah berstatus {$invoice->status} dan tidak memerlukan token Snap baru.");
        }

        if ($invoice->expires_at !== null && $invoice->expires_at->isPast()) {
            throw new DomainException("Tagihan {$invoice->invoice_code} telah kedaluwarsa.");
        }

        $grandTotalInt = (int) round((float) $invoice->grand_total);
        if ($grandTotalInt <= 0) {
            throw new DomainException("Tagihan {$invoice->invoice_code} tidak memiliki sisa pembayaran gateway.");
        }

        if (
            ! empty($invoice->snap_token)
            && ! empty($invoice->snap_redirect_url)
            && ! str_starts_with((string) $invoice->snap_token, 'SNAP-')
        ) {
            return [
                'snap_token' => (string) $invoice->snap_token,
                'redirect_url' => (string) $invoice->snap_redirect_url,
            ];
        }

        $invoice->loadMissing('buyer');
        $buyer = $invoice->buyer;

        $payload = [
            'transaction_details' => [
                'order_id' => $invoice->invoice_code,
                'gross_amount' => $grandTotalInt,
            ],
            'customer_details' => [
                'first_name' => $buyer->name ?? 'NexaTrade Buyer',
                'email' => $buyer->email ?? 'buyer@nexatrade.id',
            ],
            'callbacks' => [
                'finish' => route('invoices.show', $invoice->invoice_code),
            ],
        ];

        $snapResponse = $this->requestSnapTransaction($payload);

        $invoice->update([
            'snap_token' => $snapResponse['token'],
            'snap_redirect_url' => $snapResponse['redirect_url'],
        ]);

        return [
            'snap_token' => $snapResponse['token'],
            'redirect_url' => $snapResponse['redirect_url'],
        ];
    }

    /**
     * Request or reuse an active Midtrans Snap token for an UNPAID WalletTopUp.
     * External HTTP call is strictly executed outside any database transaction.
     *
     * @return array{snap_token: string, redirect_url: string}
     */
    public function createOrReuseTopUpSnapToken(WalletTopUp $topup): array
    {
        if ($topup->status !== 'UNPAID') {
            throw new DomainException("Tagihan Top-Up {$topup->topup_code} sudah berstatus {$topup->status}.");
        }

        if ($topup->expires_at !== null && $topup->expires_at->isPast()) {
            throw new DomainException("Tagihan Top-Up {$topup->topup_code} telah kedaluwarsa.");
        }

        $amountInt = (int) round((float) $topup->amount);
        if ($amountInt <= 0) {
            throw new DomainException("Nominal Top-Up {$topup->topup_code} tidak valid.");
        }

        if (! empty($topup->snap_token) && ! empty($topup->snap_redirect_url)) {
            return [
                'snap_token' => (string) $topup->snap_token,
                'redirect_url' => (string) $topup->snap_redirect_url,
            ];
        }

        $topup->loadMissing('user');
        $user = $topup->user;

        $payload = [
            'transaction_details' => [
                'order_id' => $topup->topup_code,
                'gross_amount' => $amountInt,
            ],
            'customer_details' => [
                'first_name' => $user->name ?? 'NexaTrade User',
                'email' => $user->email ?? 'user@nexatrade.id',
            ],
            'callbacks' => [
                'finish' => route('buyer.wallet.topup.show', $topup->topup_code),
            ],
        ];

        $snapResponse = $this->requestSnapTransaction($payload);

        $topup->update([
            'snap_token' => $snapResponse['token'],
            'snap_redirect_url' => $snapResponse['redirect_url'],
        ]);

        return [
            'snap_token' => $snapResponse['token'],
            'redirect_url' => $snapResponse['redirect_url'],
        ];
    }

    /**
     * Query Midtrans Core API GET /v2/{order_id}/status outside any DB transaction.
     *
     * @return array<string, mixed>
     */
    public function fetchTransactionStatus(string $orderId): array
    {
        $serverKey = trim((string) config('nexatrade.midtrans.server_key', ''));
        if ($serverKey === '') {
            throw new DomainException('Konfigurasi MIDTRANS_SERVER_KEY belum diatur.');
        }

        $coreApiUrl = rtrim((string) config('nexatrade.midtrans.core_api_url', 'https://api.sandbox.midtrans.com'), '/');
        $url = "{$coreApiUrl}/v2/".urlencode($orderId).'/status';

        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->timeout(15)
            ->get($url);

        if (! $response->successful()) {
            throw new DomainException('Gagal memeriksa status transaksi ke Midtrans Core API (HTTP '.$response->status().').');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new DomainException('Respons Midtrans Core API tidak valid.');
        }

        return $data;
    }

    /**
     * Fetch live transaction status from Midtrans Core API and feed it into the idempotent MidtransWebhookService pipeline.
     *
     * @return array<string, mixed>
     */
    public function syncTransactionStatus(string $orderId, float $fallbackGrossAmount): array
    {
        $remoteStatus = $this->fetchTransactionStatus($orderId);

        $resolvedOrderId = (string) ($remoteStatus['order_id'] ?? $orderId);
        $resolvedStatusCode = (string) ($remoteStatus['status_code'] ?? '200');
        $resolvedGrossAmount = isset($remoteStatus['gross_amount'])
            ? (string) $remoteStatus['gross_amount']
            : number_format($fallbackGrossAmount, 2, '.', '');
        $transactionStatus = strtolower((string) ($remoteStatus['transaction_status'] ?? 'pending'));
        $fraudStatus = strtolower((string) ($remoteStatus['fraud_status'] ?? 'accept'));
        $transactionId = (string) ($remoteStatus['transaction_id'] ?? ('MT-SYNC-'.$resolvedOrderId));

        $signature = $this->midtransWebhookService->generateSignature(
            $resolvedOrderId,
            $resolvedStatusCode,
            $resolvedGrossAmount,
        );

        return $this->midtransWebhookService->handleNotification([
            'order_id' => $resolvedOrderId,
            'status_code' => $resolvedStatusCode,
            'gross_amount' => $resolvedGrossAmount,
            'transaction_status' => $transactionStatus,
            'fraud_status' => $fraudStatus,
            'transaction_id' => $transactionId,
            'signature_key' => $signature,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{token: string, redirect_url: string}
     */
    private function requestSnapTransaction(array $payload): array
    {
        $serverKey = trim((string) config('nexatrade.midtrans.server_key', ''));
        if ($serverKey === '') {
            throw new DomainException('Konfigurasi MIDTRANS_SERVER_KEY belum diatur.');
        }

        $snapApiUrl = (string) config('nexatrade.midtrans.snap_api_url', 'https://app.sandbox.midtrans.com/snap/v1/transactions');

        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post($snapApiUrl, $payload);

        if (! $response->successful()) {
            $errMsg = is_array($response->json())
                ? implode(' ', (array) ($response->json('error_messages') ?? [$response->body()]))
                : $response->body();

            throw new DomainException('Gagal membuat sesi pembayaran Midtrans Snap (HTTP '.$response->status().'): '.mb_substr($errMsg, 0, 180));
        }

        $token = trim((string) $response->json('token', ''));
        $redirectUrl = trim((string) $response->json('redirect_url', ''));

        if ($token === '') {
            throw new DomainException('Midtrans Snap API tidak mengembalikan token transaksi.');
        }

        return [
            'token' => $token,
            'redirect_url' => $redirectUrl !== '' ? $redirectUrl : "https://app.sandbox.midtrans.com/snap/v2/vtweb/{$token}",
        ];
    }
}
