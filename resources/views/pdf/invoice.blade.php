<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; margin: 0; padding: 24px; background: #FFFFFF; }
        .header { border-bottom: 2px solid #0068E1; padding-bottom: 14px; margin-bottom: 18px; }
        .brand { font-size: 20px; font-weight: bold; color: #0068E1; }
        .brand span { color: #111827; }
        .muted { color: #6B7280; font-size: 10px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .badge-paid { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .badge-unpaid { background: #FFF4ED; color: #C2410C; border: 1px solid #FED7AA; }
        .grid-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .grid-table td { vertical-align: top; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .data-table th { background: #F8FAFC; border: 1px solid #E4E8EE; padding: 7px 8px; text-align: left; font-size: 10px; color: #4B5563; }
        .data-table td { border: 1px solid #E4E8EE; padding: 7px 8px; font-size: 10px; }
        .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .summary-box { width: 52%; margin-left: auto; margin-top: 16px; border: 1px solid #E4E8EE; background: #F8FAFC; padding: 10px 12px; }
        .summary-table { width: 100%; border-collapse: collapse; }
        .summary-table td { padding: 4px 0; font-size: 10px; }
        .total-row td { border-top: 1px solid #CBD5E1; padding-top: 8px; font-size: 12px; font-weight: bold; color: #0068E1; }
        .footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #E4E8EE; font-size: 9px; color: #6B7280; }
    </style>
</head>
<body>
    <div class="header">
        <table class="grid-table" style="margin-bottom:0;">
            <tr>
                <td>
                    <div class="brand">Nexa<span>Trade</span></div>
                    <div class="muted">Enterprise Digital Gaming Marketplace &amp; Automated Escrow</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:14px;font-weight:bold;">INVOICE PEMBAYARAN</div>
                    <div style="font-family:DejaVu Sans Mono,monospace;font-size:11px;margin-top:2px;">{{ $invoice->invoice_code }}</div>
                    <div style="margin-top:6px;">
                        <span class="badge {{ $invoice->status === 'PAID' ? 'badge-paid' : 'badge-unpaid' }}">{{ $invoice->status }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="grid-table">
        <tr>
            <td style="width:55%;">
                <div class="muted">DITAGIHKAN KEPADA</div>
                <div style="font-weight:bold;font-size:12px;margin-top:2px;">{{ $invoice->buyer?->name }}</div>
                <div class="muted">{{ $invoice->buyer?->email }}</div>
            </td>
            <td style="width:45%;text-align:right;">
                <div class="muted">DETAIL TRANSAKSI</div>
                <div>Tanggal: <strong>{{ $invoice->created_at?->format('d M Y H:i') }} WIB</strong></div>
                <div>Kanal: <strong style="text-transform:uppercase;">{{ $invoice->payment_channel }}</strong> ({{ $invoice->payment_mode }})</div>
                @if($invoice->paid_at)
                    <div>Lunas: <strong>{{ $invoice->paid_at->format('d M Y H:i') }} WIB</strong></div>
                @endif
            </td>
        </tr>
    </table>

    <div style="font-weight:bold;margin-bottom:4px;">Rincian Split-Order ({{ $invoice->orders->count() }} Toko)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Kode Pesanan</th>
                <th>Toko Penjual</th>
                <th>Produk</th>
                <th class="num">Qty</th>
                <th class="num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->orders as $ord)
                @foreach($ord->items as $item)
                    <tr>
                        <td style="font-family:DejaVu Sans Mono,monospace;">{{ $ord->order_code }}</td>
                        <td>{{ $ord->sellerProfile?->store_name ?? 'Toko NexaTrade' }}</td>
                        <td>{{ $item->product_title_snapshot }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td>Subtotal Produk</td>
                <td class="num">Rp {{ number_format((float) $invoice->subtotal_amount, 0, ',', '.') }}</td>
            </tr>
            @if((float) $invoice->seller_discount_total > 0)
                <tr>
                    <td>Diskon Voucher Toko</td>
                    <td class="num">- Rp {{ number_format((float) $invoice->seller_discount_total, 0, ',', '.') }}</td>
                </tr>
            @endif
            @if((float) $invoice->platform_discount_total > 0)
                <tr>
                    <td>Subsidi Voucher Platform</td>
                    <td class="num">- Rp {{ number_format((float) $invoice->platform_discount_total, 0, ',', '.') }}</td>
                </tr>
            @endif
            @if((float) $invoice->wallet_used_amount > 0)
                <tr>
                    <td>Pembayaran Saldo Dompet</td>
                    <td class="num">Rp {{ number_format((float) $invoice->wallet_used_amount, 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr>
                <td>Biaya Layanan Gateway</td>
                <td class="num">Rp {{ number_format((float) $invoice->gateway_fee, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td>Total Tagihan Gateway</td>
                <td class="num">Rp {{ number_format((float) $invoice->grand_total, 0, ',', '.') }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Dokumen ini diterbitkan secara otomatis oleh NexaTrade Enterprise Escrow Engine pada {{ $generatedAt->format('d M Y H:i:s') }} WIB.
        Referensi Verifikasi: {{ $invoice->invoice_code }} | Dilindungi Buku Besar Double-Entry MySQL InnoDB.
    </div>
</body>
</html>
