<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Bukti Pesanan {{ $order->order_code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; margin: 0; padding: 24px; background: #FFFFFF; }
        .header { border-bottom: 2px solid #00AA5B; padding-bottom: 14px; margin-bottom: 18px; }
        .brand { font-size: 20px; font-weight: bold; color: #0068E1; }
        .brand span { color: #111827; }
        .muted { color: #6B7280; font-size: 10px; }
        .grid-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .grid-table td { vertical-align: top; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .data-table th { background: #F8FAFC; border: 1px solid #E4E8EE; padding: 7px 8px; text-align: left; font-size: 10px; color: #4B5563; }
        .data-table td { border: 1px solid #E4E8EE; padding: 7px 8px; font-size: 10px; }
        .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .warranty-box { margin-top: 18px; border: 1.5px solid #00AA5B; background: #ECFDF5; padding: 12px 14px; border-radius: 6px; }
        .warranty-title { font-size: 12px; font-weight: bold; color: #065F46; margin-bottom: 6px; }
        .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #E4E8EE; font-size: 9px; color: #6B7280; }
    </style>
</head>
<body>
    <div class="header">
        <table class="grid-table" style="margin-bottom:0;">
            <tr>
                <td>
                    <div class="brand">Nexa<span>Trade</span></div>
                    <div class="muted">Bukti Transaksi Resmi &amp; Perlindungan Safe Trading Escrow</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:13px;font-weight:bold;">BUKTI PESANAN</div>
                    <div style="font-family:DejaVu Sans Mono,monospace;font-size:11px;margin-top:2px;">{{ $order->order_code }}</div>
                    <div class="muted" style="margin-top:3px;">Status: <strong>{{ $order->status }}</strong></div>
                </td>
            </tr>
        </table>
    </div>

    <table class="grid-table">
        <tr>
            <td style="width:50%;">
                <div class="muted">PEMBELI</div>
                <div style="font-weight:bold;">{{ $order->buyer?->name }}</div>
                <div class="muted">{{ $order->buyer?->email }}</div>
            </td>
            <td style="width:50%;text-align:right;">
                <div class="muted">TOKO PENJUAL</div>
                <div style="font-weight:bold;">{{ $order->sellerProfile?->store_name ?? $order->seller?->name }}</div>
                <div class="muted">Invoice Induk: {{ $order->paymentInvoice?->invoice_code ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Produk</th>
                <th class="num">Qty</th>
                <th class="num">Harga Satuan</th>
                <th class="num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td style="font-family:DejaVu Sans Mono,monospace;">{{ $item->sku_snapshot }}</td>
                    <td>{{ $item->product_title_snapshot }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">Rp {{ number_format((float) $item->unit_price_snapshot, 0, ',', '.') }}</td>
                    <td class="num">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="grid-table" style="margin-top:14px;">
        <tr>
            <td style="width:55%;">
                @if($order->verified_game_nickname)
                    <div class="muted">TARGET GAME TERVERIFIKASI</div>
                    <div style="font-weight:bold;">{{ $order->verified_game_nickname }}</div>
                @endif
            </td>
            <td style="width:45%;text-align:right;">
                <div>Harga Kotor: <strong>Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }}</strong></div>
                @if((float) $order->seller_discount_amount > 0)
                    <div>Diskon Toko: - Rp {{ number_format((float) $order->seller_discount_amount, 0, ',', '.') }}</div>
                @endif
                @if((float) $order->platform_discount_amount > 0)
                    <div>Subsidi Platform: - Rp {{ number_format((float) $order->platform_discount_amount, 0, ',', '.') }}</div>
                @endif
                <div style="font-size:12px;font-weight:bold;color:#0068E1;margin-top:4px;">
                    Total Dibayar Pembeli: Rp {{ number_format($order->refundableBuyerAmount(), 0, ',', '.') }}
                </div>
            </td>
        </tr>
    </table>

    @if($order->warranty)
        <div class="warranty-box">
            <div class="warranty-title">SERTIFIKAT GARANSI ANTI-HACKBACK {{ $order->warranty->warranty_days }} HARI</div>
            <div style="font-size:10px;color:#047857;line-height:1.5;">
                Pesanan akun ini dilindungi oleh Program Jaminan Anti-Hackback NexaTrade dengan nilai pertanggungan
                <strong>Rp {{ number_format((float) $order->warranty->coverage_amount, 0, ',', '.') }}</strong>.<br>
                Berlaku: <strong>{{ $order->warranty->starts_at?->format('d M Y H:i') }} WIB</strong> s.d.
                <strong>{{ $order->warranty->expires_at?->format('d M Y H:i') }} WIB</strong> |
                Status Sertifikat: <strong style="text-transform:uppercase;">{{ $order->warranty->status }}</strong>.
            </div>
        </div>
    @endif

    <div class="footer">
        Diterbitkan oleh NexaTrade Enterprise Engine pada {{ $generatedAt->format('d M Y H:i:s') }} WIB.
        Kredensial Brankas AES-256 tidak dicetak pada dokumen PDF demi keamanan akun pembeli.
    </div>
</body>
</html>
