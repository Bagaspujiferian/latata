@extends('layouts.app')

@section('title', 'Keranjang Belanja & Split-Order Checkout — NexaTrade')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-extrabold text-[#111827]">Keranjang Multi-Toko</h1>
            <p class="text-xs text-[#6B7280] mt-0.5">1 Tagihan Induk otomatis dipecah ke Escrow masing-masing toko secara atomik.</p>
        </div>
        <a href="{{ route('marketplace.index') }}" class="btn-tactile inline-flex items-center gap-1.5 text-xs font-semibold text-[#0068E1] hover:underline">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            <span>Lanjut Belanja</span>
        </a>
    </div>

    @if($cartItems->isEmpty())
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter">
            <div class="w-12 h-12 rounded-full bg-[#EFF6FF] text-[#0068E1] mx-auto flex items-center justify-center mb-3">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
            </div>
            <h2 class="text-base font-bold text-[#111827]">Keranjang Belanja Masih Kosong</h2>
            <p class="text-xs text-[#6B7280] mt-1">Tambahkan produk dari berbagai toko untuk mencoba Split-Order Escrow otomatis.</p>
            <a href="{{ route('marketplace.index') }}" class="btn-tactile inline-flex items-center mt-4 px-5 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold">
                Jelajahi Katalog Game
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start"
             x-data="{
                subtotal: {{ (float) $subtotal }},
                walletAvailable: {{ (float) $walletAvailable }},
                paymentMode: '{{ $walletAvailable >= $subtotal ? 'wallet_full' : ($walletAvailable >= 10000 ? 'hybrid_wallet_gateway' : 'gateway_only') }}',
                paymentChannel: 'qris',
                walletUsedInput: {{ min(max(10000, floor($subtotal * 0.35)), max(0, min($walletAvailable, $subtotal - 10000))) }},
                fees: @js($gatewayFees),
                submittingCheckout: false,

                get gatewayFee() {
                    if (this.paymentMode === 'wallet_full') return 0;
                    const key = this.paymentMode === 'hybrid_wallet_gateway'
                        ? 'hybrid_wallet_' + this.paymentChannel
                        : this.paymentChannel;
                    return Number(this.fees[key] ?? this.fees[this.paymentChannel] ?? 1500);
                },
                get effectiveWalletUsed() {
                    if (this.paymentMode === 'wallet_full') return this.subtotal;
                    if (this.paymentMode === 'gateway_only') return 0;
                    const maxAllowed = Math.max(0, Math.min(this.walletAvailable, this.subtotal - 1000));
                    return Math.max(0, Math.min(Number(this.walletUsedInput) || 0, maxAllowed));
                },
                get grandTotal() {
                    if (this.paymentMode === 'wallet_full') return 0;
                    return Math.max(0, (this.subtotal - this.effectiveWalletUsed) + this.gatewayFee);
                }
             }">

            {{-- LEFT (7/12): PER-STORE CART CARDS --}}
            <div class="lg:col-span-7 space-y-4">
                @foreach($groupedPreview as $idx => $group)
                    <div style="--stagger-index: {{ $idx }}"
                         class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter">
                        {{-- Store Header --}}
                        <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/></svg>
                                <span class="text-sm font-bold text-[#111827]">{{ $group['store_name'] }}</span>
                                @if($group['is_sla_10m_badge'])
                                    <span class="px-1.5 py-0.5 rounded bg-[#FFF4ED] text-[#C2410C] border border-[#FED7AA] text-[10px] font-semibold">10 Menit Kirim</span>
                                @endif
                            </div>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-md bg-white border border-[#E4E8EE] text-[#4B5563]">
                                Sub-Order #{{ $idx + 1 }} &middot; {{ strtoupper($group['delivery_method']) }}
                            </span>
                        </div>

                        <div class="divide-y divide-[#F1F5F9]">
                            @foreach($group['items'] as $cartItem)
                                <div class="row-interactive p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-start gap-3 min-w-0">
                                        <img src="{{ $cartItem->product->gameCategory->game->thumbnail_url }}" alt="" class="w-16 h-11 rounded-lg object-cover border border-[#E4E8EE] shrink-0">
                                        <div class="min-w-0">
                                            <a href="{{ route('marketplace.show', $cartItem->product->slug) }}" class="text-xs font-bold text-[#111827] hover:text-[#0068E1] line-clamp-2">
                                                {{ $cartItem->product->title }}
                                            </a>
                                            <div class="text-[11px] text-[#6B7280] mt-0.5">
                                                {{ $cartItem->product->gameCategory->game->name }} &middot; <span class="font-mono-tabular">{{ $cartItem->product->sku_code }}</span>
                                            </div>
                                            @if(! empty($cartItem->buyer_game_inputs))
                                                <div class="mt-1.5 flex flex-wrap gap-1 font-mono-tabular text-[10px]">
                                                    @foreach($cartItem->buyer_game_inputs as $k => $v)
                                                        <span class="px-1.5 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563]">{{ $k }}: {{ $v }}</span>
                                                    @endforeach
                                                    @if($cartItem->verified_game_nickname)
                                                        <span class="px-1.5 py-0.5 rounded bg-[#ECFDF5] text-[#047857] font-semibold">{{ $cartItem->verified_game_nickname }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between sm:justify-end gap-3 shrink-0">
                                        <form method="POST" action="{{ route('cart.update', $cartItem) }}" class="flex items-center gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <input type="number" name="quantity" value="{{ $cartItem->quantity }}" min="1" max="{{ max(1, $cartItem->product->max_order) }}"
                                                   class="w-14 h-8 px-2 font-mono-tabular text-xs text-center border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                            <button type="submit" class="btn-tactile h-8 px-2.5 text-xs font-semibold bg-[#F8FAFC] hover:bg-[#EFF6FF] border border-[#E4E8EE] hover:border-[#BFDBFE] text-[#0068E1] rounded-lg cursor-pointer">
                                                Ubah
                                            </button>
                                        </form>

                                        <div class="text-right font-mono-tabular min-w-[96px]">
                                            <div class="text-sm font-bold text-[#111827]">
                                                Rp {{ number_format((float) $cartItem->product->price * $cartItem->quantity, 0, ',', '.') }}
                                            </div>
                                            <div class="text-[10px] text-[#6B7280]">@ Rp {{ number_format((float) $cartItem->product->price, 0, ',', '.') }}</div>
                                        </div>

                                        <form method="POST" action="{{ route('cart.remove', $cartItem) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="Hapus item" class="btn-tactile p-1.5 text-[#6B7280] hover:text-[#DC2626] hover:bg-[#FEF2F2] rounded-lg cursor-pointer">
                                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="px-4 py-2.5 bg-[#F8FAFC] border-t border-[#E4E8EE] flex flex-wrap items-center justify-between gap-2 text-[11px] font-mono-tabular text-[#4B5563]">
                            <span>Subtotal Toko: <strong class="text-[#111827]">Rp {{ number_format($group['gross_amount'], 0, ',', '.') }}</strong></span>
                            <span>Escrow Penjual: <strong class="text-[#047857]">Rp {{ number_format($group['seller_escrow_amount'], 0, ',', '.') }}</strong> (Fee Rp {{ number_format($group['platform_commission'], 0, ',', '.') }})</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- RIGHT (5/12, STICKY): SPLIT-ORDER SUMMARY & PAYMENT MODE SELECTOR --}}
            <div class="lg:col-span-5 animate-enter" style="--stagger-index: 1">
                <form method="POST" action="{{ route('checkout.process') }}" @submit="submittingCheckout = true"
                      class="bg-white border border-[#E4E8EE] rounded-xl p-5 sticky top-20 space-y-4">
                    @csrf
                    <div class="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
                        <div>
                            <h2 class="text-sm font-bold text-[#111827]">Ringkasan &amp; Pembayaran</h2>
                            <p class="text-[11px] text-[#6B7280]">1 Invoice &rarr; {{ count($groupedPreview) }} Pesanan Escrow</p>
                        </div>
                        <span class="px-2 py-0.5 rounded-md bg-[#ECFDF5] text-[#047857] text-[11px] font-semibold">Split-Order</span>
                    </div>

                    {{-- Radio-Card Payment Mode Selector --}}
                    <div class="space-y-2.5">
                        {{-- 1. Wallet Full --}}
                        <label class="block p-3 rounded-xl border cursor-pointer transition-all"
                               :class="paymentMode === 'wallet_full' ? 'border-[#0068E1] bg-[#EFF6FF]/60 ring-1 ring-[#0068E1]' : 'border-[#E4E8EE] bg-white hover:border-[#CBD5E1]'">
                            <div class="flex items-start gap-2.5">
                                <input type="radio" name="payment_mode" value="wallet_full" x-model="paymentMode" class="mt-0.5 accent-[#0068E1]">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#111827]">Bayar Penuh Saldo Dompet</span>
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-[#ECFDF5] text-[#047857]">Bebas Biaya</span>
                                    </div>
                                    <p class="text-[11px] text-[#4B5563] mt-0.5">Lunas instan 0 detik. Brankas AES-256 langsung terbuka otomatis.</p>
                                    <div class="font-mono-tabular text-[11px] mt-1 font-semibold" :class="walletAvailable >= subtotal ? 'text-[#047857]' : 'text-[#DC2626]'">
                                        Saldo Aktif: Rp {{ number_format($walletAvailable, 0, ',', '.') }}
                                    </div>
                                </div>
                            </div>
                        </label>

                        {{-- 2. Hybrid Wallet + Midtrans --}}
                        <label class="block p-3 rounded-xl border cursor-pointer transition-all"
                               :class="paymentMode === 'hybrid_wallet_gateway' ? 'border-[#0068E1] bg-[#EFF6FF]/60 ring-1 ring-[#0068E1]' : 'border-[#E4E8EE] bg-white hover:border-[#CBD5E1]'">
                            <div class="flex items-start gap-2.5">
                                <input type="radio" name="payment_mode" value="hybrid_wallet_gateway" x-model="paymentMode" class="mt-0.5 accent-[#0068E1]">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#111827]">Bayar Hibrida (Saldo + Midtrans)</span>
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-[#FFF4ED] text-[#C2410C]">Auto-Rollback</span>
                                    </div>
                                    <p class="text-[11px] text-[#4B5563] mt-0.5">Potong sebagian saldo + bayar sisanya via QRIS/VA. Refund otomatis jika expired.</p>

                                    <div x-show="paymentMode === 'hybrid_wallet_gateway'" x-cloak class="mt-2.5 pt-2.5 border-t border-[#BFDBFE]/60">
                                        <label class="block text-[11px] font-semibold text-[#374151] mb-1">Nominal Saldo Dipakai (Rp)</label>
                                        <input type="number" name="wallet_amount" x-model.number="walletUsedInput" min="1000" step="1000"
                                               class="w-full h-9 px-3 font-mono-tabular text-xs bg-white border border-[#0068E1] rounded-lg focus:outline-none">
                                    </div>
                                </div>
                            </div>
                        </label>

                        {{-- 3. Midtrans Full --}}
                        <label class="block p-3 rounded-xl border cursor-pointer transition-all"
                               :class="paymentMode === 'gateway_only' ? 'border-[#0068E1] bg-[#EFF6FF]/60 ring-1 ring-[#0068E1]' : 'border-[#E4E8EE] bg-white hover:border-[#CBD5E1]'">
                            <div class="flex items-start gap-2.5">
                                <input type="radio" name="payment_mode" value="gateway_only" x-model="paymentMode" class="mt-0.5 accent-[#0068E1]">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#111827]">Midtrans Snap Gateway</span>
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563]">QRIS / VA</span>
                                    </div>
                                    <p class="text-[11px] text-[#4B5563] mt-0.5">Verifikasi SHA-512 dan proteksi pemulihan pembayaran terlambat.</p>
                                </div>
                            </div>
                        </label>
                    </div>

                    {{-- Midtrans Channel Selector --}}
                    <div x-show="paymentMode !== 'wallet_full'" x-cloak class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[11px] font-semibold text-[#4B5563] mb-2">Pilih Kanal Pembayaran</div>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            @foreach(['qris' => 'QRIS (+Rp 1.500)', 'bca_va' => 'BCA VA (+Rp 4.000)', 'bri_va' => 'BRI VA (+Rp 4.000)', 'gopay' => 'GoPay (+Rp 2.000)'] as $chKey => $chLabel)
                                <label class="btn-tactile flex items-center gap-2 p-2 rounded-lg border bg-white cursor-pointer"
                                       :class="paymentChannel === '{{ $chKey }}' ? 'border-[#0068E1] text-[#0068E1] font-semibold' : 'border-[#E4E8EE] text-[#4B5563]'">
                                    <input type="radio" name="payment_channel" value="{{ $chKey }}" x-model="paymentChannel" class="accent-[#0068E1]">
                                    <span class="text-[11px]">{{ $chLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Price Breakdown --}}
                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2 text-xs">
                        <div class="flex justify-between text-[#4B5563]">
                            <span>Subtotal ({{ count($groupedPreview) }} Toko)</span>
                            <span class="font-mono-tabular font-semibold text-[#111827]" x-text="window.formatRupiah(subtotal)"></span>
                        </div>
                        <div class="flex justify-between text-[#047857]">
                            <span>Potongan Saldo Dompet</span>
                            <span class="font-mono-tabular font-semibold" x-text="'- ' + window.formatRupiah(effectiveWalletUsed)"></span>
                        </div>
                        <div class="flex justify-between text-[#4B5563]">
                            <span>Biaya Layanan Gateway</span>
                            <span class="font-mono-tabular font-semibold text-[#111827]" x-text="'+ ' + window.formatRupiah(gatewayFee)"></span>
                        </div>
                        <div class="pt-2.5 border-t border-[#E4E8EE] flex justify-between items-baseline">
                            <span class="font-bold text-[#111827]">Total Tagihan</span>
                            <span class="font-mono-tabular text-lg font-extrabold text-[#F05A28]"
                                  x-text="paymentMode === 'wallet_full' ? window.formatRupiah(subtotal) : window.formatRupiah(grandTotal)"></span>
                        </div>
                    </div>

                    <button type="submit" :disabled="submittingCheckout"
                            class="btn-tactile w-full h-11 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                        <svg x-show="submittingCheckout" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        <span x-text="submittingCheckout ? 'Memproses Checkout...' : 'Bayar Sekarang'"></span>
                    </button>
                </form>
            </div>
        </div>
    @endif
@endsection
