@extends('layouts.app')

@section('title', 'Detail Pesanan ' . $order->order_code . ' — NexaTrade')

@section('content')
    @php
        $stepIndex = match ($order->status) {
            'WAITING_PAYMENT' => 1,
            'PAID_ESCROW', 'PROCESSING' => 2,
            'DELIVERED', 'DISPUTED' => 3,
            'COMPLETED' => 4,
            default => 0,
        };
        $steps = [
            1 => 'Pembayaran',
            2 => 'Escrow Aktif',
            3 => 'Pesanan Dikirim',
            4 => 'Selesai & Cair',
        ];
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-[#6B7280] flex items-center gap-1.5">
            <a href="{{ route('buyer.orders.index') }}" class="hover:text-[#0068E1]">Pesanan Saya</a>
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m9 18 6-6-6-6"/></svg>
            <span class="font-mono-tabular font-bold text-[#111827]">{{ $order->order_code }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('buyer.orders.pdf', $order->order_code) }}" target="_blank"
               class="btn-tactile inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#ECFDF5] hover:bg-[#D1FAE5] border border-[#A7F3D0] text-xs font-bold text-[#047857]">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                <span>Unduh Bukti &amp; Garansi PDF</span>
            </a>
            @if($order->paymentInvoice)
                <a href="{{ route('invoices.show', $order->paymentInvoice->invoice_code) }}"
                   class="btn-tactile inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-[#E4E8EE] hover:border-[#0068E1] text-xs font-medium text-[#4B5563] hover:text-[#0068E1]">
                    <span>Invoice Induk:</span>
                    <span class="font-mono-tabular font-bold text-[#111827]">{{ $order->paymentInvoice->invoice_code }}</span>
                </a>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start"
         x-data="{
            disputeModalOpen: false,
            warrantyModalOpen: false,
            rating: 5,
            decryptedItems: {},
            decryptErrors: {},
            decrypting: {},
            copiedId: null,
            sendingMessage: false,

            async unsealVault(itemId, endpoint) {
                this.decrypting[itemId] = true;
                this.decryptErrors[itemId] = null;
                try {
                    const revealBeat = new Promise((r) => setTimeout(r, 360));
                    const req = fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                    }).then((res) => res.json());

                    const [data] = await Promise.all([req, revealBeat]);
                    if (data.ok) {
                        this.decryptedItems[itemId] = data;
                        window.nexaToast('success', 'Brankas AES-256 Terbuka', 'Kode voucher / kredensial berhasil didekripsi aman.');
                    } else {
                        this.decryptErrors[itemId] = data.message || 'Gagal membuka brankas.';
                    }
                } catch (e) {
                    this.decryptErrors[itemId] = 'Terjadi kesalahan jaringan saat dekripsi AES-256.';
                } finally {
                    this.decrypting[itemId] = false;
                }
            },

            copySecret(itemId, text) {
                navigator.clipboard.writeText(text);
                this.copiedId = itemId;
                window.nexaToast('success', 'Tersalin', 'Kode berhasil disalin ke clipboard.');
                setTimeout(() => { this.copiedId = null; }, 2200);
            }
         }">

        {{-- LEFT COLUMN (7/12): ORDER STEPPER, TIMERS, AES-256 VAULT, ITEMS & CONFIRMATION --}}
        <div class="lg:col-span-7 space-y-5">
            {{-- Order Summary & Horizontal Stepper Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-5 animate-enter" style="--stagger-index: 0">
                <div class="flex flex-wrap items-start justify-between gap-4 pb-4 border-b border-[#F1F5F9]">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-mono-tabular text-lg font-extrabold text-[#111827]">{{ $order->order_code }}</h1>
                            <span class="px-2 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563] text-[10px] font-semibold uppercase">{{ $order->delivery_method }}</span>
                        </div>
                        <div class="text-xs text-[#6B7280] mt-1">
                            Penjual: <strong class="text-[#111827]">{{ $order->sellerProfile?->store_name }}</strong>
                            &middot; Target SLA {{ $order->sla_target_minutes_snapshot }} menit
                        </div>
                    </div>

                    <div class="text-right">
                        @php
                            $statusPill = match ($order->status) {
                                'COMPLETED' => 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]',
                                'DELIVERED' => 'bg-[#EFF6FF] text-[#0068E1] border-[#BFDBFE]',
                                'PROCESSING' => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
                                'DISPUTED' => 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]',
                                default => 'bg-[#F1F5F9] text-[#4B5563] border-[#E4E8EE]',
                            };
                            $pulseBadge = in_array($order->status, ['PROCESSING', 'DISPUTED'], true);
                        @endphp
                        <span class="{{ $pulseBadge ? 'badge-live-pulse' : 'inline-flex' }} px-2.5 py-1 rounded-full border text-xs font-bold {{ $statusPill }}">
                            {{ $order->status }}
                        </span>
                        <div class="font-mono-tabular text-lg font-extrabold text-[#111827] mt-1.5">
                            Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }}
                        </div>
                        @if((float) $order->seller_discount_amount > 0 || (float) $order->platform_discount_amount > 0)
                            <div class="font-mono-tabular text-[11px] text-[#047857] mt-0.5">
                                Bayar Bersih: <strong>Rp {{ number_format($order->refundableBuyerAmount(), 0, ',', '.') }}</strong>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Visual Horizontal Order Stepper Timeline --}}
                @if($stepIndex > 0)
                    <div class="grid grid-cols-4 gap-2">
                        @foreach($steps as $num => $stepLabel)
                            @php
                                $done = $stepIndex > $num || ($stepIndex === 4 && $num === 4);
                                $current = $stepIndex === $num && $stepIndex !== 4;
                            @endphp
                            <div class="flex flex-col items-center text-center relative">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold border {{ $done ? 'bg-[#00AA5B] border-[#00AA5B] text-white' : ($current ? 'bg-[#0068E1] border-[#0068E1] text-white ring-4 ring-[#EFF6FF]' : 'bg-[#F8FAFC] border-[#E4E8EE] text-[#6B7280]') }}">
                                    @if($done)
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    @else
                                        {{ $num }}
                                    @endif
                                </div>
                                <span class="mt-1.5 text-[11px] font-semibold {{ $done ? 'text-[#047857]' : ($current ? 'text-[#0068E1]' : 'text-[#6B7280]') }}">{{ $stepLabel }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- LIVE SLA COUNTDOWN BANNER & AUTO-UNLOCKING REFUND BUTTON --}}
                @if($order->status === 'PROCESSING' && $order->sla_deadline_at)
                    <div class="p-4 rounded-xl bg-[#FFF4ED] border border-[#FED7AA]"
                         x-data="liveCountdown('{{ $order->sla_deadline_at->toIso8601String() }}')">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="badge-live-pulse text-xs font-bold" :class="expired ? 'text-[#DC2626]' : 'text-[#C2410C]'">
                                    <span x-show="!expired">Timer Garansi SLA Penjual Aktif</span>
                                    <span x-show="expired" x-cloak>Pelanggaran SLA Terdeteksi</span>
                                </div>
                                <div class="font-mono-tabular text-2xl font-extrabold mt-1"
                                     :class="expired ? 'text-[#DC2626]' : 'text-[#111827]'" x-text="formatted"></div>
                                <div class="text-[11px] text-[#9A3412] mt-0.5">
                                    Batas kirim penjual: {{ $order->sla_deadline_at->format('d M Y H:i:s') }} WIB
                                </div>
                            </div>

                            <form method="POST" action="{{ route('buyer.orders.claim-sla', $order->order_code) }}">
                                @csrf
                                <button type="submit" :disabled="!expired"
                                        class="btn-tactile px-4 py-2.5 rounded-lg text-xs font-bold cursor-pointer"
                                        :class="expired ? 'bg-[#DC2626] hover:bg-[#B91C1C] text-white' : 'bg-white border border-[#FED7AA] text-[#9A3412]/60 cursor-not-allowed'">
                                    <span x-show="!expired">Klaim Refund Terkunci (Menunggu SLA)</span>
                                    <span x-show="expired" x-cloak>Batalkan &amp; Klaim Refund Instan ke Dompet</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- LIVE 24H AUTO-COMPLETE BANNER --}}
                @if($order->status === 'DELIVERED' && $order->auto_complete_at)
                    <div class="p-4 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0]"
                         x-data="liveCountdown('{{ $order->auto_complete_at->toIso8601String() }}')">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="badge-live-pulse text-xs font-bold text-[#047857]">Masa Pemeriksaan Pembeli (24 Jam)</div>
                                <div class="font-mono-tabular text-2xl font-extrabold text-[#111827] mt-1" x-text="formatted"></div>
                                <div class="text-[11px] text-[#047857] mt-0.5">
                                    Otomatis selesai pada {{ $order->auto_complete_at->format('d M Y H:i') }} WIB jika tidak ada komplain.
                                </div>
                            </div>

                            <button type="button" @click="disputeModalOpen = true"
                                    class="btn-tactile px-3.5 py-2.5 rounded-lg bg-white hover:bg-[#FEF2F2] border border-[#FECACA] text-[#DC2626] text-xs font-bold cursor-pointer shrink-0">
                                Ajukan Komplain (Bekukan Escrow)
                            </button>
                        </div>
                    </div>
                @endif

                @if($order->status === 'DISPUTED')
                    <div class="p-4 rounded-xl bg-[#FEF2F2] border border-[#FECACA] text-xs text-[#B91C1C]">
                        <div class="badge-live-pulse font-bold">Escrow Dibekukan — Mediasi Admin Aktif</div>
                        <p class="mt-1">Timer auto-settlement dihentikan (`auto_complete_at = NULL`). Dana Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }} aman sampai putusan arbitrase.</p>
                    </div>
                @endif

                @if($order->cancelled_reason)
                    <div class="p-3.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] text-xs text-[#4B5563]">
                        <strong class="text-[#111827]">Keterangan:</strong> {{ $order->cancelled_reason }}
                    </div>
                @endif

                {{-- Buyer Pre-Processing Cancellation Box (Manual Trade Only, Before Seller Accepts) --}}
                @if($order->delivery_method === 'manual_trade' && in_array($order->status, ['PAID_ESCROW', 'PROCESSING'], true) && (int) $order->buyer_id === (int) auth()->id())
                    @if($order->seller_accepted_at === null)
                        <form method="POST" action="{{ route('buyer.orders.cancel', $order->order_code) }}"
                              class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2.5 text-xs">
                            @csrf
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-bold text-[#111827]">Batalkan Pesanan (Sebelum Diproses Penjual)</div>
                                    <p class="text-[11px] text-[#6B7280]">Selama penjual belum menekan Mulai Proses Pesanan, Anda dapat membatalkan pesanan dan menerima refund instan Rp {{ number_format($order->refundableBuyerAmount(), 0, ',', '.') }} ke Saldo Aktif.</p>
                                </div>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input type="text" name="reason" required minlength="3" maxlength="255"
                                       placeholder="Alasan pembatalan singkat (contoh: salah pilih server / ingin ganti nominal)..."
                                       class="flex-1 h-9 px-3 bg-white border border-[#D1D5DB] rounded-lg focus:border-[#DC2626] focus:outline-none">
                                <button type="submit" class="btn-tactile px-4 h-9 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold shrink-0 cursor-pointer">
                                    Batalkan Pesanan
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="p-3.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-xs text-[#1E40AF] flex items-center justify-between gap-2">
                            <span><strong>Penjual Sedang Memproses Pesanan:</strong> Diterima pada {{ $order->seller_accepted_at->format('d M Y, H:i:s') }} WIB. Pembatalan sepihak dikunci hingga batas SLA.</span>
                        </div>
                    @endif
                @endif
            </div>

            {{-- LIGHT-MODE BRANKAS DIGITAL AES-256 CARD --}}
            @if($order->delivery_method === 'instant_vault' && in_array($order->status, ['DELIVERED', 'COMPLETED', 'DISPUTED'], true))
                <div class="bg-white border border-[#A7F3D0] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 1">
                    <div class="px-5 py-3.5 bg-[#ECFDF5] border-b border-[#A7F3D0] flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-[#00AA5B] text-white flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-[#065F46]">Brankas Digital AES-256</h2>
                                <p class="text-[11px] text-[#047857]">Hanya dapat didekripsi oleh Pembeli #{{ $order->buyer_id }} atau Admin Sengketa</p>
                            </div>
                        </div>
                        <span class="font-mono-tabular text-[10px] font-bold px-2 py-0.5 rounded-full bg-white border border-[#A7F3D0] text-[#047857]">AES-256-CBC</span>
                    </div>

                    <div class="p-5 space-y-3">
                        @foreach($order->items as $idx => $item)
                            <div class="border border-[#E4E8EE] rounded-xl overflow-hidden">
                                <div class="px-4 py-2.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between text-xs">
                                    <span class="font-bold text-[#111827]">Item #{{ $idx + 1 }} &middot; {{ $item->product_title_snapshot }}</span>
                                    <span class="font-mono-tabular text-[11px] text-[#6B7280]">Vault #{{ $item->product_vault_id }}</span>
                                </div>

                                <div class="p-4 bg-white">
                                    {{-- Sealed State --}}
                                    <div x-show="!decryptedItems[{{ $item->id }}] && !decrypting[{{ $item->id }}]"
                                         class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-xs font-semibold text-[#111827]">Kredensial / Voucher Tersegel Aman</div>
                                            <div class="font-mono-tabular text-[11px] text-[#6B7280] mt-0.5 truncate max-w-md">
                                                {{ substr((string) $item->encrypted_delivery_payload, 0, 48) }}...
                                            </div>
                                        </div>
                                        <button type="button"
                                                @click="unsealVault({{ $item->id }}, '{{ route('buyer.orders.decrypt', [$order->order_code, $item->id]) }}')"
                                                class="btn-tactile px-4 py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-bold inline-flex items-center gap-1.5 shrink-0 cursor-pointer">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                            <span>Buka Segel Brankas AES-256</span>
                                        </button>
                                    </div>

                                    {{-- 360ms Light-Mode Shimmer Unsealing Reveal --}}
                                    <div x-show="decrypting[{{ $item->id }}]" x-cloak class="space-y-2.5" aria-busy="true">
                                        <div class="flex items-center justify-between">
                                            <div class="h-3.5 w-44 rounded skeleton-shimmer"></div>
                                            <div class="h-3 w-28 rounded skeleton-shimmer"></div>
                                        </div>
                                        <div class="h-11 w-full rounded-lg skeleton-shimmer"></div>
                                    </div>

                                    {{-- Revealed Plaintext Code + Animated Copy Button --}}
                                    <div x-show="decryptedItems[{{ $item->id }}] && !decrypting[{{ $item->id }}]" x-cloak
                                         x-transition:enter="transition ease-out duration-250"
                                         x-transition:enter-start="opacity-0 translate-y-1"
                                         x-transition:enter-end="opacity-100 translate-y-0"
                                         class="space-y-2.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-[#047857]">
                                            <span class="font-semibold inline-flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                                                <span>Terdekripsi Sah</span>
                                            </span>
                                            <span class="font-mono-tabular text-[#6B7280]" x-text="decryptedItems[{{ $item->id }}]?.blind_hash_prefix ? 'Blind Hash: ' + decryptedItems[{{ $item->id }}].blind_hash_prefix : ''"></span>
                                        </div>

                                        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                            <div class="flex-1 p-3 rounded-lg bg-[#F8FAFC] border border-[#00AA5B] font-mono-tabular text-sm font-bold text-[#111827] break-all select-all"
                                                 x-text="decryptedItems[{{ $item->id }}]?.plaintext_payload"></div>
                                            <button type="button"
                                                    @click="copySecret({{ $item->id }}, decryptedItems[{{ $item->id }}].plaintext_payload)"
                                                    :class="copiedId === {{ $item->id }} ? 'bg-[#00AA5B] text-white border-[#00AA5B]' : 'bg-white hover:bg-[#EFF6FF] text-[#0068E1] border-[#0068E1]'"
                                                    class="btn-tactile h-11 px-4 rounded-lg border text-xs font-bold inline-flex items-center justify-center gap-1.5 shrink-0 cursor-pointer">
                                                <svg x-show="copiedId !== {{ $item->id }}" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                                <svg x-show="copiedId === {{ $item->id }}" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                                <span x-text="copiedId === {{ $item->id }} ? 'Tersalin!' : 'Salin Kode'"></span>
                                            </button>
                                        </div>
                                    </div>

                                    <template x-if="decryptErrors[{{ $item->id }}]">
                                        <div class="mt-2 text-xs text-[#DC2626] font-medium" x-text="decryptErrors[{{ $item->id }}]"></div>
                                    </template>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Order Items & Delivery Proof Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 animate-enter" style="--stagger-index: 2">
                <h2 class="text-sm font-bold text-[#111827]">Rincian Produk &amp; Bukti Pengiriman</h2>

                <div class="divide-y divide-[#F1F5F9]">
                    @foreach($order->items as $item)
                        <div class="py-2.5 flex items-center justify-between gap-3 text-xs">
                            <div>
                                <div class="font-semibold text-[#111827]">{{ $item->product_title_snapshot }}</div>
                                <div class="font-mono-tabular text-[11px] text-[#6B7280] mt-0.5">SKU {{ $item->sku_snapshot }} &middot; {{ $item->quantity }} &times; Rp {{ number_format((float) $item->unit_price_snapshot, 0, ',', '.') }}</div>
                            </div>
                            <div class="font-mono-tabular font-bold text-[#111827]">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</div>
                        </div>
                    @endforeach
                </div>

                @if(! empty($order->buyer_game_inputs) || $order->verified_game_nickname)
                    <div class="p-3.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] text-xs space-y-1">
                        <div class="font-semibold text-[#4B5563]">Data Target Game</div>
                        @if($order->verified_game_nickname)
                            <div class="font-semibold text-[#047857]">Nickname Terverifikasi: {{ $order->verified_game_nickname }}</div>
                        @endif
                        <div class="flex flex-wrap gap-2 font-mono-tabular text-[11px]">
                            @foreach($order->buyer_game_inputs ?? [] as $k => $v)
                                <span class="px-2 py-0.5 bg-white border border-[#E4E8EE] rounded">{{ $k }}: <strong class="text-[#111827]">{{ $v }}</strong></span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(! empty($order->seller_delivery_proof))
                    <div class="p-3.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-xs space-y-1 text-[#047857]">
                        <div class="font-bold text-[#065F46]">Bukti Pengiriman / SN H2H</div>
                        @if(! empty($order->seller_delivery_proof['serial_number']))
                            <div class="font-mono-tabular font-bold text-[#111827]">SN: {{ $order->seller_delivery_proof['serial_number'] }}</div>
                        @endif
                        @if(! empty($order->seller_delivery_proof['notes']))
                            <div>{{ $order->seller_delivery_proof['notes'] }}</div>
                        @endif
                        @if(! empty($order->seller_delivery_proof['proof_url']))
                            <div class="font-mono-tabular text-[11px] break-all">Lampiran: {{ $order->seller_delivery_proof['proof_url'] }}</div>
                        @endif
                    </div>
                @endif
            </div>

                {{-- Confirm Order & Rating Card --}}
            @if(in_array($order->status, ['DELIVERED', 'DISPUTED'], true) && (int) $order->buyer_id === (int) auth()->id())
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 animate-enter" style="--stagger-index: 3">
                    <h3 class="text-sm font-bold text-[#111827]">Konfirmasi Pesanan Selesai &amp; Beri Ulasan</h3>
                    <p class="text-xs text-[#6B7280] mt-1">Dana bersih Rp {{ number_format((float) $order->seller_escrow_amount, 0, ',', '.') }} akan dilepaskan dari Escrow ke Saldo Aktif penjual.</p>

                    <form method="POST" action="{{ route('buyer.orders.confirm', $order->order_code) }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs font-semibold text-[#374151] mr-1">Rating:</span>
                            <input type="hidden" name="rating" x-model="rating">
                            @for($star = 1; $star <= 5; $star++)
                                <button type="button" @click="rating = {{ $star }}" class="btn-tactile p-0.5 cursor-pointer" aria-label="Bintang {{ $star }}">
                                    <svg class="w-6 h-6 transition-colors"
                                         :class="rating >= {{ $star }} ? 'text-[#F59E0B] fill-[#F59E0B]' : 'text-[#D1D5DB] fill-none'"
                                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </button>
                            @endfor
                            <span class="font-mono-tabular text-xs font-bold text-[#111827] ml-1" x-text="rating + '/5'"></span>
                        </div>

                        <textarea name="comment" rows="2" placeholder="Bagikan ulasan tentang kecepatan pengiriman dan kualitas produk..."
                                  class="w-full px-3 py-2 text-xs bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>

                        <button type="submit" class="btn-tactile px-4 py-2.5 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                            <span>Konfirmasi Selesai &amp; Cairkan Escrow</span>
                        </button>
                    </form>
                </div>
            @endif

            {{-- KARTU SERTIFIKAT GARANSI ANTI-HACKBACK (PHASE 3) --}}
            @if($order->warranty)
                @php
                    $w = $order->warranty;
                    $wStatusStyle = match ($w->status) {
                        'active' => 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]',
                        'claimed' => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
                        'refunded' => 'bg-[#EFF6FF] text-[#0068E1] border-[#BFDBFE]',
                        default => 'bg-[#F1F5F9] text-[#4B5563] border-[#E4E8EE]',
                    };
                @endphp
                <div class="bg-white border border-[#A7F3D0] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 4">
                    <div class="px-5 py-3.5 bg-[#ECFDF5] border-b border-[#A7F3D0] flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5">
                            <span class="w-8 h-8 rounded-lg bg-[#00AA5B] text-white flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                            </span>
                            <div>
                                <h3 class="text-sm font-bold text-[#065F46]">Sertifikat Garansi Anti-Hackback {{ $w->warranty_days }} Hari</h3>
                                <p class="text-[11px] text-[#047857]">Perlindungan pasca-selesai terhadap penarikan paksa akun oleh pemilik lama.</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full border text-[11px] font-bold uppercase {{ $wStatusStyle }}">
                            {{ $w->status }}
                        </span>
                    </div>

                    <div class="p-5 space-y-4 text-xs">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                <div class="text-[10px] text-[#6B7280] uppercase font-semibold">Nilai Pertanggungan</div>
                                <div class="font-mono-tabular text-sm font-extrabold text-[#047857] mt-0.5">
                                    Rp {{ number_format((float) $w->coverage_amount, 0, ',', '.') }}
                                </div>
                            </div>
                            <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                <div class="text-[10px] text-[#6B7280] uppercase font-semibold">Berlaku Hingga</div>
                                <div class="font-mono-tabular text-xs font-bold text-[#111827] mt-1">
                                    {{ $w->expires_at?->format('d M Y, H:i') }} WIB
                                </div>
                            </div>
                            <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]"
                                 x-data="liveCountdown('{{ $w->expires_at?->toIso8601String() }}')">
                                <div class="text-[10px] text-[#6B7280] uppercase font-semibold">Sisa Proteksi</div>
                                <div class="font-mono-tabular text-xs font-extrabold text-[#0068E1] mt-1" x-text="formatted">--:--:--</div>
                            </div>
                        </div>

                        @if($w->claims->isNotEmpty())
                            <div class="space-y-2">
                                <div class="text-[11px] font-bold text-[#374151] uppercase tracking-wide">Riwayat Klaim Garansi</div>
                                @foreach($w->claims as $cl)
                                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-1.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span class="font-mono-tabular font-bold text-[#111827]">{{ $cl->claim_code }} &middot; {{ \App\Models\WarrantyClaim::REASON_LABELS[$cl->reason_category] ?? $cl->reason_category }}</span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $cl->status === 'approved_refund' ? 'bg-[#ECFDF5] text-[#047857]' : ($cl->status === 'rejected' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#FFF4ED] text-[#C2410C]') }}">
                                                {{ $cl->status }}
                                            </span>
                                        </div>
                                        <p class="text-[#4B5563] leading-relaxed">{{ $cl->description }}</p>
                                        @if(! empty($cl->evidence_paths))
                                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                                @foreach($cl->evidence_paths as $evIdx => $evPath)
                                                    <a href="{{ route('admin.warranties.evidence', [$cl, $evIdx]) }}" target="_blank"
                                                       class="inline-flex items-center gap-1 px-2 py-1 rounded bg-white border border-[#BFDBFE] text-[#0068E1] text-[10px] font-semibold hover:underline">
                                                        Bukti Privat #{{ $evIdx + 1 }}
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if($cl->admin_resolution_notes)
                                            <div class="mt-1.5 pt-1.5 border-t border-[#E4E8EE] text-[11px] text-[#374151]">
                                                <strong>Keputusan Admin:</strong> {{ $cl->admin_resolution_notes }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($w->isClaimable() && (int) $order->buyer_id === (int) auth()->id())
                            <div class="pt-1 flex flex-wrap items-center justify-between gap-2">
                                <span class="text-[11px] text-[#4B5563]">Akun ditarik balik (hackback) atau email recovery diubah penjual?</span>
                                <button type="button" @click="warrantyModalOpen = true"
                                        class="btn-tactile px-4 py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                                    <span>Ajukan Klaim Garansi Hackback</span>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- RIGHT COLUMN (5/12): SIDE-BY-SIDE MODERATED ORDER CHAT --}}
        <div class="lg:col-span-5 animate-enter" style="--stagger-index: 1">
            <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden sticky top-20 flex flex-col">
                <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <div>
                            <h2 class="text-sm font-bold text-[#111827]">Chat Pesanan &amp; Mediasi</h2>
                            <p class="text-[10px] text-[#6B7280]">Dilindungi Filter Anti-Bypass NexaTrade</p>
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded-full bg-[#ECFDF5] text-[#047857] border border-[#A7F3D0] text-[10px] font-semibold">Auto-Mod</span>
                </div>

                {{-- Bubble Chat Feed --}}
                <div class="p-4 max-h-[460px] overflow-y-auto space-y-3 bg-[#F4F6F9]/50">
                    @forelse($order->messages as $msg)
                        @php
                            $isSystem = $msg->sender_role === 'system';
                            $isAdmin = $msg->sender_role === 'admin';
                            $isBuyerMsg = $msg->sender_role === 'buyer';
                        @endphp

                        @if($isSystem)
                            <div class="mx-auto max-w-[92%] text-center px-3 py-2 rounded-lg bg-[#F1F5F9] border border-[#E4E8EE] text-[11px] text-[#4B5563]">
                                <div class="font-semibold text-[#111827] mb-0.5">Notifikasi Sistem &middot; {{ $msg->created_at?->format('H:i') }}</div>
                                <p class="leading-relaxed">{{ $msg->sanitized_message }}</p>
                            </div>
                        @elseif($isAdmin)
                            <div class="mx-auto max-w-[92%] px-3.5 py-2.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-xs text-[#1E3A8A]">
                                <div class="flex items-center justify-between gap-2 text-[10px] font-bold mb-1">
                                    <span>ADMIN ARBITRASE &middot; {{ $msg->sender?->name }}</span>
                                    <span class="font-mono-tabular opacity-75">{{ $msg->created_at?->format('H:i') }}</span>
                                </div>
                                <p class="leading-relaxed">{{ $msg->sanitized_message }}</p>
                            </div>
                        @else
                            <div class="flex {{ $isBuyerMsg ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[85%] rounded-xl px-3.5 py-2.5 text-xs border {{ $isBuyerMsg ? 'bg-[#EFF6FF] border-[#BFDBFE] text-[#111827] rounded-br-sm' : 'bg-[#F8FAFC] border-[#E4E8EE] text-[#111827] rounded-bl-sm' }}">
                                    <div class="flex items-center justify-between gap-3 text-[10px] text-[#6B7280] mb-1">
                                        <strong class="uppercase {{ $isBuyerMsg ? 'text-[#0068E1]' : 'text-[#111827]' }}">{{ $msg->sender_role }} &middot; {{ $msg->sender?->name }}</strong>
                                        <span class="font-mono-tabular">{{ $msg->created_at?->format('H:i') }}</span>
                                    </div>
                                    <p class="leading-relaxed whitespace-pre-line">{{ $msg->sanitized_message }}</p>

                                    @if($msg->is_flagged_bypass)
                                        <div class="mt-2 px-2 py-1 rounded bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] text-[10px] font-semibold flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                            <span>Disensor Anti-Bypass: {{ $msg->detected_violation_type }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @empty
                        <p class="text-xs text-[#6B7280] text-center py-8">Belum ada percakapan pada pesanan ini.</p>
                    @endforelse
                </div>

                {{-- Chat Composer --}}
                <form method="POST" action="{{ route('buyer.orders.messages', $order->order_code) }}" @submit="sendingMessage = true"
                      class="p-3.5 bg-white border-t border-[#E4E8EE] space-y-2">
                    @csrf
                    <textarea name="message" rows="2" required placeholder="Tulis pesan untuk penjual (kontak/rekening luar platform disensor otomatis)..."
                              class="w-full px-3 py-2 text-xs bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] text-[#6B7280]">Jaga transaksi tetap di dalam Escrow NexaTrade</span>
                        <button type="submit" :disabled="sendingMessage"
                                class="btn-tactile px-4 py-1.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-65">
                            <svg x-show="sendingMessage" x-cloak class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span x-text="sendingMessage ? 'Mengirim...' : 'Kirim Pesan'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- DISPUTE MODAL (LIGHT MODE) --}}
        <div x-show="disputeModalOpen" x-cloak
             x-transition:enter="transition ease-out duration-180"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-[2px] p-4">
            <div @click.outside="disputeModalOpen = false"
                 class="bg-white border border-[#E4E8EE] shadow-2xl max-w-lg w-full p-6 rounded-xl">
                <div class="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
                    <div>
                        <h3 class="text-base font-bold text-[#111827]">Ajukan Komplain &amp; Bekukan Escrow</h3>
                        <p class="text-xs text-[#6B7280]">Timer 24 jam akan dihentikan hingga Admin memutus sengketa.</p>
                    </div>
                    <button type="button" @click="disputeModalOpen = false" class="p-1 text-[#6B7280] hover:text-[#111827] rounded-lg cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('buyer.orders.dispute', $order->order_code) }}" class="mt-4 space-y-3.5 text-xs">
                    @csrf
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Alasan &amp; Kronologi Komplain *</label>
                        <textarea name="reason" rows="4" required minlength="10" placeholder="Jelaskan kendala voucher/akun/item yang Anda terima..."
                                  class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#DC2626] focus:outline-none"></textarea>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">URL Bukti Screenshot (Opsional)</label>
                        <input type="text" name="attachment_proof_url" placeholder="https://cdn.nexatrade.id/proofs/..."
                               class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="disputeModalOpen = false" class="btn-tactile px-4 py-2 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold cursor-pointer">Batal</button>
                        <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">Bekukan Escrow Sekarang</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ANTI-HACKBACK WARRANTY CLAIM MODAL (LIGHT MODE) --}}
        @if($order->warranty && $order->warranty->isClaimable())
            <div x-show="warrantyModalOpen" x-cloak @keydown.escape.window="warrantyModalOpen = false"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 backdrop-blur-[2px] p-4">
                <div @click.outside="warrantyModalOpen = false"
                     class="bg-white border border-[#E4E8EE] shadow-2xl max-w-lg w-full rounded-xl overflow-hidden">
                    <div class="px-5 py-3.5 bg-[#ECFDF5] border-b border-[#A7F3D0] flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-[#065F46]">Ajukan Klaim Garansi Anti-Hackback</h3>
                            <p class="text-[11px] text-[#047857]">Pertanggungan 100% sebesar Rp {{ number_format((float) $order->warranty->coverage_amount, 0, ',', '.') }}</p>
                        </div>
                        <button type="button" @click="warrantyModalOpen = false" class="p-1 text-[#047857] hover:text-[#065F46] cursor-pointer">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>

                    <form method="POST" action="{{ route('buyer.orders.warranty-claim', $order) }}" enctype="multipart/form-data"
                          class="p-5 space-y-3.5 text-xs">
                        @csrf
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Kategori Kendala Akun *</label>
                            <select name="reason_category" required
                                    class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                @foreach($reasonLabels as $rk => $rl)
                                    <option value="{{ $rk }}">{{ $rl }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Kronologi Detail *</label>
                            <textarea name="description" rows="4" required minlength="10"
                                      placeholder="Jelaskan kronologi email recovery / password ditarik ulang atau akun terkena banned..."
                                      class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Unggah Bukti Screenshot Privat (JPG/PNG/WEBP, maks 3MB)</label>
                            <input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp"
                                   class="w-full text-xs text-[#4B5563] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-[#BFDBFE] file:bg-[#EFF6FF] file:text-[#0068E1] file:font-semibold file:cursor-pointer">
                            <p class="text-[10px] text-[#6B7280] mt-1">Bukti disimpan terenkripsi pada penyimpanan privat (hanya dapat dilihat Anda &amp; Admin Risiko).</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" @click="warrantyModalOpen = false" class="btn-tactile px-4 py-2 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold cursor-pointer">Batal</button>
                            <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">Kirim Klaim Garansi</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
