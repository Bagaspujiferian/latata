@extends('layouts.app')

@section('title', 'Keranjang Belanja & Split-Order Checkout — NexaTrade')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-extrabold text-[#111827]">Keranjang Multi-Toko</h1>
            <p class="text-xs text-[#6B7280] mt-0.5">1 Tagihan Induk otomatis dipecah ke Escrow masing-masing toko beserta pembagian voucher pro-rata.</p>
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
            <p class="text-xs text-[#6B7280] mt-1">Tambahkan produk dari berbagai toko untuk mencoba Split-Order Escrow &amp; Voucher Engine otomatis.</p>
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

                // Phase 3 Voucher Engine state
                platformVoucherInput: '{{ old('platform_voucher_code', '') }}',
                appliedPlatformCode: '',
                appliedPlatformTitle: '',
                platformDiscountTotal: 0,
                validatingPlatform: false,
                platformVoucherError: '',

                sellerVoucherInputs: {},
                appliedSellerCodes: {},
                appliedSellerDiscounts: {},
                sellerVoucherErrors: {},
                validatingSeller: {},

                get sellerDiscountTotal() {
                    return Object.values(this.appliedSellerDiscounts).reduce((sum, val) => sum + Number(val || 0), 0);
                },
                get payableSubtotal() {
                    return Math.max(0, this.subtotal - this.sellerDiscountTotal - this.platformDiscountTotal);
                },
                get gatewayFee() {
                    if (this.paymentMode === 'wallet_full' || this.payableSubtotal <= 0) return 0;
                    const key = this.paymentMode === 'hybrid_wallet_gateway'
                        ? 'hybrid_wallet_' + this.paymentChannel
                        : this.paymentChannel;
                    return Number(this.fees[key] ?? this.fees[this.paymentChannel] ?? 1500);
                },
                get effectiveWalletUsed() {
                    if (this.paymentMode === 'wallet_full') return this.payableSubtotal;
                    if (this.paymentMode === 'gateway_only') return 0;
                    const maxAllowed = Math.max(0, Math.min(this.walletAvailable, Math.max(0, this.payableSubtotal - 1000)));
                    return Math.max(0, Math.min(Number(this.walletUsedInput) || 0, maxAllowed));
                },
                get grandTotal() {
                    if (this.paymentMode === 'wallet_full') return 0;
                    return Math.max(0, (this.payableSubtotal - this.effectiveWalletUsed) + this.gatewayFee);
                },

                async applyPlatformVoucher(presetCode = null) {
                    const code = (presetCode ?? this.platformVoucherInput ?? '').trim().toUpperCase();
                    if (!code) {
                        this.platformVoucherError = 'Masukkan kode voucher platform terlebih dahulu.';
                        return;
                    }
                    this.platformVoucherInput = code;
                    this.validatingPlatform = true;
                    this.platformVoucherError = '';

                    try {
                        const minBeat = new Promise((r) => setTimeout(r, 260));
                        const req = fetch('{{ route('checkout.voucher-preview') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({
                                code: code,
                                seller_voucher_codes: this.appliedSellerCodes,
                            }),
                        }).then(async (res) => ({ status: res.status, data: await res.json() }));

                        const [res] = await Promise.all([req, minBeat]);
                        if (res.data.ok && res.data.preview) {
                            if (res.data.preview.issuer_type !== 'platform') {
                                this.platformVoucherError = 'Kode ini adalah Voucher Toko. Gunakan pada kartu toko di sebelah kiri.';
                                return;
                            }
                            this.appliedPlatformCode = res.data.preview.code;
                            this.appliedPlatformTitle = res.data.preview.title;
                            this.platformDiscountTotal = Number(res.data.preview.discount_amount || 0);
                            window.nexaToast('success', 'Voucher Platform Aktif', res.data.preview.code + ' hemat ' + window.formatRupiah(this.platformDiscountTotal) + ' (ditanggung NexaTrade).');
                        } else {
                            this.appliedPlatformCode = '';
                            this.appliedPlatformTitle = '';
                            this.platformDiscountTotal = 0;
                            this.platformVoucherError = res.data.message || 'Kode voucher tidak berlaku.';
                        }
                    } catch (e) {
                        this.platformVoucherError = 'Gagal menghubungi layanan validasi voucher.';
                    } finally {
                        this.validatingPlatform = false;
                    }
                },

                removePlatformVoucher() {
                    this.appliedPlatformCode = '';
                    this.appliedPlatformTitle = '';
                    this.platformDiscountTotal = 0;
                    this.platformVoucherInput = '';
                    this.platformVoucherError = '';
                },

                async applySellerVoucher(sellerId, presetCode = null) {
                    const code = (presetCode ?? this.sellerVoucherInputs[sellerId] ?? '').trim().toUpperCase();
                    if (!code) {
                        this.sellerVoucherErrors[sellerId] = 'Masukkan kode voucher toko.';
                        return;
                    }
                    this.sellerVoucherInputs[sellerId] = code;
                    this.validatingSeller[sellerId] = true;
                    this.sellerVoucherErrors[sellerId] = '';

                    try {
                        const res = await fetch('{{ route('checkout.voucher-preview') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ code: code }),
                        });
                        const data = await res.json();
                        if (data.ok && data.preview) {
                            if (data.preview.issuer_type !== 'seller' || Number(data.preview.seller_id) !== Number(sellerId)) {
                                this.sellerVoucherErrors[sellerId] = 'Kode voucher ini bukan milik toko ini.';
                                return;
                            }
                            this.appliedSellerCodes[sellerId] = data.preview.code;
                            this.appliedSellerDiscounts[sellerId] = Number(data.preview.discount_amount || 0);
                            window.nexaToast('success', 'Voucher Toko Aktif', data.preview.code + ' hemat ' + window.formatRupiah(this.appliedSellerDiscounts[sellerId]) + '.');
                            if (this.appliedPlatformCode) {
                                await this.applyPlatformVoucher(this.appliedPlatformCode);
                            }
                        } else {
                            delete this.appliedSellerCodes[sellerId];
                            delete this.appliedSellerDiscounts[sellerId];
                            this.sellerVoucherErrors[sellerId] = data.message || 'Voucher toko tidak valid.';
                        }
                    } catch (e) {
                        this.sellerVoucherErrors[sellerId] = 'Gagal memeriksa voucher toko.';
                    } finally {
                        this.validatingSeller[sellerId] = false;
                    }
                },

                async removeSellerVoucher(sellerId) {
                    delete this.appliedSellerCodes[sellerId];
                    delete this.appliedSellerDiscounts[sellerId];
                    this.sellerVoucherInputs[sellerId] = '';
                    this.sellerVoucherErrors[sellerId] = '';
                    if (this.appliedPlatformCode) {
                        await this.applyPlatformVoucher(this.appliedPlatformCode);
                    }
                }
             }">

            {{-- LEFT (7/12): PER-STORE CART CARDS --}}
            <div class="lg:col-span-7 space-y-4">
                @php $renderedSellerVoucherBoxes = []; @endphp
                @foreach($groupedPreview as $idx => $group)
                    @php
                        $sid = (int) $group['seller_id'];
                        $sellerVouchersForStore = $availableSellerVouchers->get($sid, collect());
                        $showSellerVoucherBar = ! in_array($sid, $renderedSellerVoucherBoxes, true);
                        $renderedSellerVoucherBoxes[] = $sid;
                    @endphp
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
                                @if($group['is_suspended'] ?? false)
                                    <span class="px-1.5 py-0.5 rounded bg-[#FEF2F2] text-[#B91C1C] border border-[#FECACA] text-[10px] font-bold uppercase">Toko Ditangguhkan</span>
                                @endif
                            </div>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-md bg-white border border-[#E4E8EE] text-[#4B5563]">
                                Sub-Order #{{ $idx + 1 }} &middot; {{ strtoupper($group['delivery_method']) }}
                            </span>
                        </div>

                        <div class="divide-y divide-[#F1F5F9]">
                            @foreach($group['items'] as $cartItem)
                                @php
                                    $unitEff = (float) ($cartItem->getAttribute('preview_unit_price') ?? $cartItem->product->price);
                                    $unitOrig = (float) ($cartItem->getAttribute('preview_original_price') ?? $cartItem->product->price);
                                    $isFlash = (bool) ($cartItem->getAttribute('preview_is_flash') ?? false);
                                @endphp
                                <div class="row-interactive p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-start gap-3 min-w-0">
                                        <img src="{{ $cartItem->product->gameCategory->game->thumbnail_url }}" alt="" class="w-16 h-11 rounded-lg object-cover border border-[#E4E8EE] shrink-0">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <a href="{{ route('marketplace.show', $cartItem->product->slug) }}" class="text-xs font-bold text-[#111827] hover:text-[#0068E1] line-clamp-2">
                                                    {{ $cartItem->product->title }}
                                                </a>
                                                @if($isFlash)
                                                    <span class="px-1.5 py-0.2 rounded bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[9px] font-extrabold uppercase">Flash Sale</span>
                                                @endif
                                            </div>
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

                                        <div class="text-right font-mono-tabular min-w-[100px]">
                                            <div class="text-sm font-bold {{ $isFlash ? 'text-[#F05A28]' : 'text-[#111827]' }}">
                                                Rp {{ number_format($unitEff * $cartItem->quantity, 0, ',', '.') }}
                                            </div>
                                            @if($isFlash)
                                                <div class="text-[10px] text-[#6B7280] line-through">Rp {{ number_format($unitOrig * $cartItem->quantity, 0, ',', '.') }}</div>
                                            @else
                                                <div class="text-[10px] text-[#6B7280]">@ Rp {{ number_format($unitEff, 0, ',', '.') }}</div>
                                            @endif
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

                        {{-- Per-Store Seller Voucher Selector --}}
                        @if($showSellerVoucherBar)
                            <div class="px-4 py-3 bg-white border-t border-[#F1F5F9] space-y-2 text-xs">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="inline-flex items-center gap-1.5 font-semibold text-[#374151]">
                                        <svg class="w-3.5 h-3.5 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/></svg>
                                        <span>Voucher Toko {{ $group['store_name'] }}</span>
                                    </span>

                                    <div class="flex items-center gap-1.5">
                                        <input type="text" x-model="sellerVoucherInputs[{{ $sid }}]" placeholder="Kode voucher toko..."
                                               class="h-8 w-36 px-2.5 text-[11px] font-mono-tabular uppercase bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                        <button type="button" @click="applySellerVoucher({{ $sid }})" :disabled="validatingSeller[{{ $sid }}]"
                                                class="btn-tactile h-8 px-3 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-[11px] font-bold cursor-pointer">
                                            Pakai
                                        </button>
                                    </div>
                                </div>

                                @if($sellerVouchersForStore->isNotEmpty())
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach($sellerVouchersForStore as $sv)
                                            <button type="button" @click="applySellerVoucher({{ $sid }}, '{{ $sv->code }}')"
                                                    class="btn-tactile px-2 py-1 rounded-md border border-[#FED7AA] bg-[#FFF4ED] hover:bg-[#FFEDD5] text-[#C2410C] text-[10px] font-bold font-mono-tabular cursor-pointer">
                                                {{ $sv->code }} ({{ $sv->discount_type === 'fixed' ? 'Rp '.number_format((float) $sv->discount_value, 0, ',', '.') : (int) $sv->discount_value.'%' }})
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                <template x-if="appliedSellerCodes[{{ $sid }}]">
                                    <div class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[11px]">
                                        <span>
                                            Voucher <strong class="font-mono-tabular" x-text="appliedSellerCodes[{{ $sid }}]"></strong> aktif:
                                            <strong class="font-mono-tabular" x-text="'- ' + window.formatRupiah(appliedSellerDiscounts[{{ $sid }}])"></strong>
                                        </span>
                                        <button type="button" @click="removeSellerVoucher({{ $sid }})" class="font-bold text-[#DC2626] hover:underline cursor-pointer">Hapus</button>
                                    </div>
                                </template>

                                <template x-if="sellerVoucherErrors[{{ $sid }}]">
                                    <div class="text-[11px] text-[#DC2626] font-medium" x-text="sellerVoucherErrors[{{ $sid }}]"></div>
                                </template>
                            </div>
                        @endif

                        <div class="px-4 py-2.5 bg-[#F8FAFC] border-t border-[#E4E8EE] flex flex-wrap items-center justify-between gap-2 text-[11px] font-mono-tabular text-[#4B5563]">
                            <span>Subtotal Toko: <strong class="text-[#111827]">Rp {{ number_format($group['gross_amount'], 0, ',', '.') }}</strong></span>
                            <span>Escrow Penjual: <strong class="text-[#047857]">Rp {{ number_format($group['seller_escrow_amount'], 0, ',', '.') }}</strong> (Fee Rp {{ number_format($group['platform_commission'], 0, ',', '.') }})</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- RIGHT (5/12, STICKY): VOUCHER ENGINE, SPLIT-ORDER SUMMARY & PAYMENT MODE SELECTOR --}}
            <div class="lg:col-span-5 animate-enter" style="--stagger-index: 1">
                <form method="POST" action="{{ route('checkout.process') }}" @submit="submittingCheckout = true"
                      class="bg-white border border-[#E4E8EE] rounded-xl p-5 sticky top-20 space-y-4">
                    @csrf
                    <input type="hidden" name="_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="platform_voucher_code" :value="appliedPlatformCode">
                    <template x-for="[sid, scode] in Object.entries(appliedSellerCodes)" :key="sid">
                        <input type="hidden" :name="'seller_voucher_codes[' + sid + ']'" :value="scode">
                    </template>

                    <div class="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
                        <div>
                            <h2 class="text-sm font-bold text-[#111827]">Ringkasan &amp; Pembayaran</h2>
                            <p class="text-[11px] text-[#6B7280]">1 Invoice &rarr; {{ count($groupedPreview) }} Pesanan Escrow</p>
                        </div>
                        <span class="px-2 py-0.5 rounded-md bg-[#ECFDF5] text-[#047857] text-[11px] font-semibold">Split-Order</span>
                    </div>

                    {{-- MAKIN HEMAT PAKAI PROMO (Platform Voucher Panel) --}}
                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-[#111827]">
                                <svg class="w-4 h-4 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
                                <span>Makin Hemat Pakai Promo</span>
                            </span>
                            <span class="text-[10px] font-semibold text-[#0068E1]">Subsidi Platform</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="text" x-model="platformVoucherInput" placeholder="Masukkan kode (cth: NEXAHEMAT20)"
                                   class="flex-1 h-9 px-3 text-xs font-mono-tabular uppercase bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                            <button type="button" @click="applyPlatformVoucher()" :disabled="validatingPlatform"
                                    class="btn-tactile h-9 px-3.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold cursor-pointer disabled:opacity-60">
                                Terapkan
                            </button>
                        </div>

                        {{-- Light Mode Shimmer while validating voucher --}}
                        <div x-show="validatingPlatform" x-cloak class="space-y-1.5 pt-1" aria-busy="true">
                            <div class="h-3.5 w-3/4 rounded skeleton-shimmer"></div>
                            <div class="h-3 w-1/2 rounded skeleton-shimmer"></div>
                        </div>

                        {{-- Active Platform Voucher Pill --}}
                        <div x-show="!validatingPlatform && appliedPlatformCode" x-cloak
                             class="p-2.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] flex items-center justify-between gap-2 text-xs text-[#047857]">
                            <div class="min-w-0">
                                <div class="font-bold font-mono-tabular" x-text="appliedPlatformCode + ' (- ' + window.formatRupiah(platformDiscountTotal) + ')'"></div>
                                <div class="text-[10px] truncate" x-text="appliedPlatformTitle"></div>
                            </div>
                            <button type="button" @click="removePlatformVoucher()" class="text-[11px] font-bold text-[#DC2626] hover:underline shrink-0 cursor-pointer">Hapus</button>
                        </div>

                        {{-- Inline Validation Error --}}
                        <div x-show="!validatingPlatform && platformVoucherError" x-cloak
                             class="p-2 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[11px] text-[#B91C1C] font-medium"
                             x-text="platformVoucherError"></div>

                        {{-- Clickable Available Platform Vouchers --}}
                        @if($availablePlatformVouchers->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                                @foreach($availablePlatformVouchers->take(3) as $pv)
                                    <button type="button" @click="applyPlatformVoucher('{{ $pv->code }}')"
                                            class="btn-tactile px-2 py-1 rounded-md bg-white hover:bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] text-[10px] font-bold font-mono-tabular cursor-pointer">
                                        {{ $pv->code }} &middot; {{ $pv->discount_type === 'fixed' ? 'Hemat Rp '.number_format((float) $pv->discount_value, 0, ',', '.') : 'Diskon '.(int) $pv->discount_value.'%' }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
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
                                    <div class="font-mono-tabular text-[11px] mt-1 font-semibold" :class="walletAvailable >= payableSubtotal ? 'text-[#047857]' : 'text-[#DC2626]'">
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

                    {{-- Itemized Accounting Breakdown --}}
                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2 text-xs">
                        <div class="flex justify-between text-[#4B5563]">
                            <span>Subtotal Produk ({{ count($groupedPreview) }} Toko)</span>
                            <span class="font-mono-tabular font-semibold text-[#111827]" x-text="window.formatRupiah(subtotal)"></span>
                        </div>
                        <div x-show="sellerDiscountTotal > 0" x-cloak class="flex justify-between text-[#C2410C]">
                            <span>Diskon Voucher Toko (Penjual)</span>
                            <span class="font-mono-tabular font-semibold" x-text="'- ' + window.formatRupiah(sellerDiscountTotal)"></span>
                        </div>
                        <div x-show="platformDiscountTotal > 0" x-cloak class="flex justify-between text-[#0068E1]">
                            <span>Diskon Subsidi Platform (NexaTrade)</span>
                            <span class="font-mono-tabular font-semibold" x-text="'- ' + window.formatRupiah(platformDiscountTotal)"></span>
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
                            <span class="font-bold text-[#111827]">Total Bayar Pembeli</span>
                            <span class="font-mono-tabular text-lg font-extrabold text-[#F05A28]"
                                  x-text="paymentMode === 'wallet_full' ? window.formatRupiah(payableSubtotal) : window.formatRupiah(grandTotal)"></span>
                        </div>
                    </div>

                    {{-- 6-Digit Wallet PIN Verification (when PIN is enabled and payment uses Wallet) --}}
                    @if(auth()->user()?->hasWalletPin())
                        <div x-show="paymentMode !== 'gateway_only'" x-cloak class="p-3.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] space-y-1.5 text-xs">
                            <label class="block font-bold text-[#1E40AF]">Masukkan PIN Dompet 6-Digit *</label>
                            <input type="password" name="wallet_pin" :required="paymentMode !== 'gateway_only'" inputmode="numeric" maxlength="6" placeholder="••••••"
                                   class="w-full h-10 px-3 font-mono-tabular text-center tracking-[0.3em] text-sm bg-white border border-[#0068E1] rounded-lg focus:outline-none">
                        </div>
                    @else
                        <div x-show="paymentMode !== 'gateway_only'" x-cloak class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] flex items-center justify-between gap-2 text-[11px] text-[#4B5563]">
                            <span>Aktifkan PIN Dompet 6-Digit untuk proteksi saldo ekstra.</span>
                            <a href="{{ route('account.security') }}" class="font-bold text-[#0068E1] hover:underline shrink-0">Atur PIN</a>
                        </div>
                    @endif

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
