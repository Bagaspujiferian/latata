@extends('layouts.app')

@section('title', 'Tagihan ' . $invoice->invoice_code . ' — NexaTrade')

@section('content')
    <div class="max-w-3xl mx-auto space-y-5">
        {{-- Main White Payment Waiting Room Card --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 0"
             x-data="{ ...liveCountdown('{{ $invoice->expires_at?->toIso8601String() }}'), simulating: false }">
            <div class="px-6 py-5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold text-[#0068E1]">Menunggu Pembayaran Tagihan Induk</div>
                    <h1 class="font-mono-tabular text-xl sm:text-2xl font-extrabold text-[#111827] mt-0.5">{{ $invoice->invoice_code }}</h1>
                    <div class="text-xs text-[#6B7280] mt-1">
                        Metode: <strong class="text-[#111827] uppercase">{{ $invoice->payment_channel }}</strong>
                        @if($invoice->snap_token)
                            &middot; Snap Token: <span class="font-mono-tabular text-[#4B5563]">{{ substr($invoice->snap_token, 0, 20) }}...</span>
                        @endif
                    </div>
                </div>

                <div class="text-right space-y-2">
                    @php
                        $statusBadge = match ($invoice->status) {
                            'PAID' => 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]',
                            'PAID_LATE_REFUNDED' => 'bg-[#EFF6FF] text-[#0068E1] border-[#BFDBFE]',
                            'EXPIRED', 'FAILED' => 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]',
                            default => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
                        };
                    @endphp
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <a href="{{ route('invoices.pdf', $invoice->invoice_code) }}" target="_blank"
                           class="btn-tactile inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] text-xs font-bold">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Unduh Invoice PDF</span>
                        </a>
                        <span class="{{ $invoice->status === 'UNPAID' ? 'badge-live-pulse' : 'inline-flex' }} px-2.5 py-1 rounded-full border text-xs font-bold {{ $statusBadge }}">
                            {{ $invoice->status }}
                        </span>
                    </div>

                    @if($invoice->status === 'UNPAID')
                        <div class="mt-2 text-[11px] text-[#6B7280]">Sisa Waktu Pembayaran</div>
                        <div class="font-mono-tabular text-xl font-extrabold" :class="expired ? 'text-[#DC2626]' : 'text-[#F05A28]'" x-text="formatted"></div>
                    @endif
                </div>
            </div>

            <div class="p-6 space-y-5">
                {{-- Payment Summary Grid --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[11px] text-[#6B7280]">Subtotal</div>
                        <div class="font-mono-tabular text-sm font-bold text-[#111827] mt-0.5">Rp {{ number_format((float) $invoice->subtotal_amount, 0, ',', '.') }}</div>
                    </div>
                    <div class="p-3 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0]">
                        <div class="text-[11px] text-[#047857]">Saldo Hibrida</div>
                        <div class="font-mono-tabular text-sm font-bold text-[#047857] mt-0.5">Rp {{ number_format((float) $invoice->wallet_used_amount, 0, ',', '.') }}</div>
                    </div>
                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[11px] text-[#6B7280]">Biaya Gateway</div>
                        <div class="font-mono-tabular text-sm font-bold text-[#111827] mt-0.5">Rp {{ number_format((float) $invoice->gateway_fee, 0, ',', '.') }}</div>
                    </div>
                    <div class="p-3 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE]">
                        <div class="text-[11px] text-[#0068E1] font-semibold">Total Midtrans</div>
                        <div class="font-mono-tabular text-base font-extrabold text-[#0068E1] mt-0.5">Rp {{ number_format((float) $invoice->grand_total, 0, ',', '.') }}</div>
                    </div>
                </div>

                {{-- Midtrans Live/Sandbox Snap Payment Panel & Simulator Panel --}}
                @php
                    $isLiveSnapDriver = in_array(config('nexatrade.midtrans.payment_driver', 'simulation'), ['midtrans_sandbox', 'midtrans_production'], true);
                @endphp

                @if($invoice->status === 'UNPAID' && (float) $invoice->grand_total > 0 && $isLiveSnapDriver)
                    <script src="{{ config('nexatrade.midtrans.snap_js_url') }}" data-client-key="{{ config('nexatrade.midtrans.client_key') }}"></script>
                    <div class="p-4 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] space-y-3"
                         x-data="{
                             snapLoading: false,
                             snapError: '',
                             async payWithSnap() {
                                 this.snapLoading = true;
                                 this.snapError = '';
                                 try {
                                     const res = await fetch('{{ route('invoices.snap-token', $invoice->invoice_code) }}', {
                                         method: 'POST',
                                         headers: {
                                             'Content-Type': 'application/json',
                                             'Accept': 'application/json',
                                             'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                         }
                                     });
                                     const data = await res.json();
                                     if (!res.ok || !data.ok) {
                                         this.snapError = data.message || 'Gagal memuat token Midtrans Snap.';
                                         this.snapLoading = false;
                                         return;
                                     }
                                     if (window.snap && typeof window.snap.pay === 'function') {
                                         window.snap.pay(data.snap_token, {
                                             onSuccess: () => { this.$refs.syncForm.submit(); },
                                             onPending: () => { this.$refs.syncForm.submit(); },
                                             onError: () => { this.snapError = 'Pembayaran Midtrans gagal atau dibatalkan.'; this.snapLoading = false; },
                                             onClose: () => { this.snapLoading = false; }
                                         });
                                     } else if (data.redirect_url) {
                                         window.location.href = data.redirect_url;
                                     }
                                 } catch (e) {
                                     this.snapError = 'Terjadi gangguan jaringan saat menghubungi Midtrans Snap.';
                                     this.snapLoading = false;
                                 }
                             }
                         }">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-extrabold text-[#111827]">Pembayaran Resmi Midtrans Snap ({{ strtoupper($invoice->payment_channel) }})</div>
                                <p class="text-[11px] text-[#374151] mt-0.5">Klik tombol di samping untuk membuka popup resmi Midtrans Snap (QRIS, Virtual Account, GoPay), atau sinkronkan status pembayaran Anda.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <button type="button" @click="payWithSnap()" :disabled="snapLoading"
                                        class="btn-tactile px-4 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-2 cursor-pointer disabled:opacity-65">
                                    <span x-text="snapLoading ? 'Memuat Midtrans Snap...' : 'Bayar Sekarang via Midtrans Snap'"></span>
                                </button>
                                <form x-ref="syncForm" method="POST" action="{{ route('invoices.sync-status', $invoice->invoice_code) }}">
                                    @csrf
                                    <button type="submit" class="btn-tactile px-3.5 py-2.5 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#0068E1] text-[#0068E1] text-xs font-bold cursor-pointer">
                                        Sinkronkan Status Pembayaran
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div x-show="snapError" x-cloak class="p-2.5 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] text-xs font-semibold" x-text="snapError"></div>
                    </div>
                @endif

                @if(in_array($invoice->status, ['UNPAID', 'EXPIRED'], true))
                    @if(config('nexatrade.demo_switcher_enabled'))
                        <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="text-xs font-bold text-[#111827]">
                                    {{ $invoice->status === 'EXPIRED' ? 'Uji Pemulihan Pembayaran Terlambat (Zombie Payment)' : 'Midtrans Snap Sandbox Trigger (SHA-512 Asli)' }}
                                </div>
                                <p class="text-xs text-[#4B5563] mt-1 leading-relaxed">
                                    @if($invoice->status === 'EXPIRED')
                                        Invoice telah kedaluwarsa. Mengirim webhook settlement sekarang akan mengamankan 100% dana ke Saldo Dompet Pembeli (`PAID_LATE_REFUNDED`).
                                    @else
                                        Menandatangani payload Midtrans menggunakan SHA-512 asli dan mengeksekusi settlement Split-Order serta pengiriman brankas AES-256 secara atomik.
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="{{ route('invoices.simulate-pay', $invoice->invoice_code) }}" @submit="simulating = true" class="shrink-0">
                                @csrf
                                <button type="submit" :disabled="simulating"
                                        class="btn-tactile px-4 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-2 cursor-pointer disabled:opacity-65">
                                    <svg x-show="simulating" x-cloak class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    <span x-text="simulating ? 'Memverifikasi SHA-512...' : '{{ $invoice->status === 'EXPIRED' ? 'Simulasi Bayar Telat (Zombie)' : 'Simulasi Bayar Lunas Midtrans' }}'"></span>
                                </button>
                            </form>
                        </div>
                    @elseif(! $isLiveSnapDriver)
                        <div class="p-4 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-xs text-[#1E40AF]">
                            Selesaikan pembayaran melalui kanal <strong class="uppercase">{{ $invoice->payment_channel }}</strong> sebelum batas waktu berakhir. Status tagihan diperbarui otomatis melalui webhook terverifikasi SHA-512.
                        </div>
                    @endif
                @elseif($invoice->status === 'PAID_LATE_REFUNDED')
                    <div class="p-4 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-xs text-[#047857]">
                        <strong>Zombie Payment Recovery Aktif:</strong> Pembayaran terlambat telah diamankan 100% ke Saldo Aktif Dompet Anda (Ref: <span class="font-mono-tabular font-bold">ZOMBIE-{{ $invoice->invoice_code }}</span>).
                    </div>
                @endif
            </div>
        </div>

        {{-- Child Split-Orders Card --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 1">
            <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                <h2 class="text-sm font-bold text-[#111827]">Rincian Split-Order per Toko ({{ $invoice->orders->count() }})</h2>
                <a href="{{ route('buyer.orders.index') }}" class="text-xs font-semibold text-[#0068E1] hover:underline">Lihat Pesanan Saya &rarr;</a>
            </div>
            <div class="divide-y divide-[#F1F5F9]">
                @foreach($invoice->orders as $order)
                    <div class="row-interactive p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="font-mono-tabular font-bold text-[#111827]">{{ $order->order_code }}</span>
                                <span class="px-2 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563] text-[10px] font-semibold uppercase">{{ $order->delivery_method }}</span>
                                <span class="px-2 py-0.5 rounded bg-[#EFF6FF] text-[#0068E1] text-[10px] font-bold">{{ $order->status }}</span>
                            </div>
                            <div class="text-xs font-semibold text-[#4B5563] mt-1">Toko: {{ $order->sellerProfile?->store_name }}</div>
                            <ul class="text-xs text-[#6B7280] mt-1 space-y-0.5">
                                @foreach($order->items as $item)
                                    <li>&bull; {{ $item->product_title_snapshot }} (&times;{{ $item->quantity }})</li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="text-right">
                            <div class="font-mono-tabular text-sm font-extrabold text-[#111827]">Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }}</div>
                            <a href="{{ route('buyer.orders.show', $order->order_code) }}" class="btn-tactile inline-block mt-2 px-3 py-1.5 rounded-lg border border-[#E4E8EE] hover:border-[#0068E1] text-xs font-semibold text-[#0068E1] bg-white">
                                Detail Pesanan
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
