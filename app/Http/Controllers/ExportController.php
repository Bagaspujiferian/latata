<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Services\DocumentExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly DocumentExportService $exportService,
    ) {}

    public function invoicePdf(Request $request, string $invoiceCode): Response
    {
        $invoice = PaymentInvoice::where('invoice_code', $invoiceCode)->firstOrFail();
        $user = $request->user();

        if (! $user || ((int) $invoice->buyer_id !== (int) $user->id && ! $user->isAdmin())) {
            abort(403, 'Anda tidak berwenang mengunduh invoice PDF ini.');
        }

        return $this->exportService->renderInvoicePdf($invoice);
    }

    public function orderPdf(Request $request, string $orderCode): Response
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $user = $request->user();

        if (
            ! $user
            || ((int) $order->buyer_id !== (int) $user->id
                && (int) $order->seller_id !== (int) $user->id
                && ! $user->isAdmin())
        ) {
            abort(403, 'Anda tidak berwenang mengunduh bukti transaksi ini.');
        }

        return $this->exportService->renderOrderReceiptPdf($order);
    }

    public function sellerOrdersCsv(Request $request): StreamedResponse
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;

        return $this->exportService->streamSellerOrdersCsv($request->user()->id, $from, $to);
    }

    public function sellerMutationsCsv(Request $request): StreamedResponse
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;

        return $this->exportService->streamSellerMutationsCsv($request->user()->id, $from, $to);
    }
}
