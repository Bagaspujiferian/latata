<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\WalletMutation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentExportService
{
    public function renderInvoicePdf(PaymentInvoice $invoice): Response
    {
        $invoice->loadMissing(['buyer', 'orders.items.product', 'orders.sellerProfile']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = "Invoice-{$invoice->invoice_code}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function renderOrderReceiptPdf(Order $order): Response
    {
        $order->loadMissing([
            'buyer',
            'seller',
            'sellerProfile',
            'paymentInvoice',
            'items.product.gameCategory.game',
            'warranty',
        ]);

        $pdf = Pdf::loadView('pdf.order-receipt', [
            'order' => $order,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = "Bukti-Pesanan-{$order->order_code}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Neutralize CSV/Excel formula injection (CWE-1236) while preserving legitimate numeric columns.
     */
    public function sanitizeCsvCell(mixed $value, bool $isNumericColumn = false): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        if ($isNumericColumn) {
            $trimmed = trim($str);
            if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) === 1) {
                return $trimmed;
            }
        }

        if (preg_match('/^(?:[\t\r]| *[=+\-@\t\r])/', $str) === 1) {
            return "'".$str;
        }

        return $str;
    }

    public function streamSellerOrdersCsv(int $sellerId, ?string $from = null, ?string $to = null): StreamedResponse
    {
        $query = Order::with(['buyer', 'items'])
            ->where('seller_id', $sellerId)
            ->orderByDesc('id');

        if (! empty($from)) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if (! empty($to)) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $orders = $query->get();
        $filename = 'nexatrade-orders-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for crisp Microsoft Excel rendering
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Order Code',
                'Tanggal',
                'Pembeli',
                'Metode',
                'Status',
                'Item Ringkas',
                'Gross Amount (Rp)',
                'Diskon Toko (Rp)',
                'Subsidi Platform (Rp)',
                'Komisi Platform (Rp)',
                'Escrow Bersih Penjual (Rp)',
            ]);

            foreach ($orders as $order) {
                $itemSummary = $order->items
                    ->map(fn ($it): string => "{$it->product_title_snapshot} (x{$it->quantity})")
                    ->implode('; ');

                fputcsv($handle, [
                    $this->sanitizeCsvCell($order->order_code),
                    $this->sanitizeCsvCell($order->created_at?->format('Y-m-d H:i:s')),
                    $this->sanitizeCsvCell($order->buyer->name ?? 'Buyer #'.$order->buyer_id),
                    $this->sanitizeCsvCell($order->delivery_method),
                    $this->sanitizeCsvCell($order->status),
                    $this->sanitizeCsvCell($itemSummary),
                    $this->sanitizeCsvCell(number_format((float) $order->gross_amount, 2, '.', ''), true),
                    $this->sanitizeCsvCell(number_format((float) $order->seller_discount_amount, 2, '.', ''), true),
                    $this->sanitizeCsvCell(number_format((float) $order->platform_discount_amount, 2, '.', ''), true),
                    $this->sanitizeCsvCell(number_format((float) $order->platform_commission, 2, '.', ''), true),
                    $this->sanitizeCsvCell(number_format((float) $order->seller_escrow_amount, 2, '.', ''), true),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function streamSellerMutationsCsv(int $sellerId, ?string $from = null, ?string $to = null): StreamedResponse
    {
        $query = WalletMutation::where('user_id', $sellerId)
            ->orderByDesc('id');

        if (! empty($from)) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if (! empty($to)) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $mutations = $query->get();
        $filename = 'nexatrade-mutasi-dompet-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($mutations): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Tanggal',
                'Reference Code',
                'Mutation Type',
                'Bucket',
                'Direction',
                'Amount (Rp)',
                'Balance Before (Rp)',
                'Balance After (Rp)',
                'Keterangan',
            ]);

            foreach ($mutations as $m) {
                fputcsv($handle, [
                    $this->sanitizeCsvCell($m->created_at->format('Y-m-d H:i:s')),
                    $this->sanitizeCsvCell($m->reference_code),
                    $this->sanitizeCsvCell($m->mutation_type),
                    $this->sanitizeCsvCell($m->balance_bucket),
                    $this->sanitizeCsvCell($m->direction),
                    $this->sanitizeCsvCell(number_format((float) $m->amount, 2, '.', ''), true),
                    $this->sanitizeCsvCell(number_format((float) $m->balance_before, 2, '.', ''), true),
                    $this->sanitizeCsvCell(number_format((float) $m->balance_after, 2, '.', ''), true),
                    $this->sanitizeCsvCell($m->description),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
