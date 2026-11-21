@extends('layouts.app')

@section('title', $product->title . ' — NexaTrade')

@section('content')
    @php
        $category = $product->gameCategory;
        $game = $category->game;
        $schema = $category->required_inputs_schema ?? [];
        $deliveryMethod = $category->delivery_method;
        $isOwnProduct = auth()->check() && (int) $product->seller_id === (int) auth()->id();
        $storeEffectiveOpen = $product->sellerProfile ? $product->sellerProfile->isOpenForManualTradeNow() : true;
        $isClosedManual = $deliveryMethod === 'manual_trade' && ! $storeEffectiveOpen;
        $hasActiveFlash = isset($activeFlashItem) && $activeFlashItem && $activeFlashItem->remainingQuota() > 0;
        $effectiveUnitPrice = $hasActiveFlash ? (float) $activeFlashItem->flash_price : (float) $product->price;

        $galleryUrls = $product->images->isNotEmpty()
            ? $product->images->map(fn ($img) => ['url' => $img->publicUrl(), 'alt' => $img->alt_text ?? $product->title])->values()->all()
            : [['url' => $game->thumbnail_url, 'alt' => $product->title]];
    @endphp

    <div x-data="{
            navigatingRelated: false,
            activeTab: 'desc',
            gallery: @js($galleryUrls),
            activePhotoIdx: 0,
            lightboxOpen: false,
            reportModalOpen: false,
            wishlisted: {{ $isWishlisted ? 'true' : 'false' }},
            wishlistCount: {{ (int) ($product->wishlisted_by_count ?? 0) }},
            followingStore: {{ $isFollowingStore ? 'true' : 'false' }},
            followersCount: {{ (int) $followersCount }},
            qty: {{ max(1, (int) $product->min_order) }},
            unitPrice: {{ $effectiveUnitPrice }},
            actionMode: 'instant_buy',
            submittingAction: null,
            paymentMode: 'wallet_full',
            paymentChannel: 'qris',
            walletAmount: {{ min(25000, max(0, floor($effectiveUnitPrice / 2))) }},
            gameInputs: {
                @foreach($schema as $f)
                    '{{ $f['key'] }}': '{{ old('game_inputs.' . $f['key'], '') }}',
                @endforeach
            },
            verifying: false,
            verifyResult: null,
            verifiedNickname: '{{ old('verified_game_nickname', '') }}',

            async toggleWishlist() {
                try {
                    const res = await fetch('{{ route('wishlist.toggle', $product) }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json();
                    if (data.ok) {
                        this.wishlisted = data.wishlisted;
                        this.wishlistCount = data.wishlist_count;
                        window.nexaToast('success', data.wishlisted ? 'Disimpan ke Wishlist' : 'Dihapus dari Wishlist', data.wishlisted ? 'Produk ditambahkan ke daftar favorit Anda.' : 'Produk dihapus dari daftar favorit.');
                    }
                } catch (e) {}
            },

            async toggleFollowStore() {
                @if($product->sellerProfile)
                try {
                    const res = await fetch('{{ route('stores.follow', $product->sellerProfile->slug) }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json();
                    if (data.ok) {
                        this.followingStore = data.following;
                        this.followersCount = data.followers_count;
                        window.nexaToast('success', data.following ? 'Mengikuti Toko' : 'Berhenti Mengikuti', data.following ? 'Anda kini mengikuti {{ $product->sellerProfile->store_name }}.' : 'Berhenti mengikuti toko.');
                    } else if (data.message) {
                        window.nexaToast('error', 'Tidak Diizinkan', data.message);
                    }
                } catch (e) {}
                @endif
            },

            async checkNickname() {
                this.verifying = true;
                this.verifyResult = null;
                try {
                    const minBeat = new Promise((r) => setTimeout(r, 340));
                    const req = fetch('{{ route('api.game.verify-nickname') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            game_slug: '{{ $game->slug }}',
                            inputs: this.gameInputs,
                        }),
                    }).then((res) => res.json());

                    const [data] = await Promise.all([req, minBeat]);
                    this.verifyResult = data;
                    if (data.valid && data.nickname) {
                        this.verifiedNickname = data.nickname;
                        window.nexaToast('success', 'Nickname Terverifikasi', data.nickname + ' terkonfirmasi dari server ' + data.provider + ' (' + data.latency_ms + 'ms).');
                    } else {
                        this.verifiedNickname = '';
                    }
                } catch (e) {
                    this.verifyResult = { valid: false, message: 'Gagal menghubungi endpoint verifikasi nickname.' };
                } finally {
                    this.verifying = false;
                }
            }
         }">

        {{-- Breadcrumb --}}
        <nav class="text-xs text-[#6B7280] mb-4 flex flex-wrap items-center gap-1.5">
            <a href="{{ route('marketplace.index') }}" class="hover:text-[#0068E1]">Beranda</a>
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m9 18 6-6-6-6"/></svg>
            <a href="{{ route('marketplace.index', ['game' => $game->slug]) }}" class="hover:text-[#0068E1]">{{ $game->name }}</a>
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m9 18 6-6-6-6"/></svg>
            <a href="{{ route('marketplace.index', ['game' => $game->slug, 'category' => $category->id]) }}" class="hover:text-[#0068E1]">{{ $category->name }}</a>
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m9 18 6-6-6-6"/></svg>
            <span class="font-mono-tabular text-[#111827] font-medium">{{ $product->sku_code }}</span>
        </nav>

        {{-- Light-Mode Detail Skeleton when switching related SKU --}}
        <div x-show="navigatingRelated" x-cloak>
            <x-skeleton.product-detail />
        </div>

        {{-- ===================================================================== --}}
        {{-- 3-COLUMN TOKOPEDIA / ITEMKU PRODUCT DETAIL LAYOUT                     --}}
        {{-- ===================================================================== --}}
        <form method="POST" action="{{ route('cart.add') }}" @submit="submittingAction = actionMode"
              x-show="!navigatingRelated"
              class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">
            <input type="hidden" name="action_mode" x-model="actionMode">
            <input type="hidden" name="verified_game_nickname" x-model="verifiedNickname">
            <input type="hidden" name="payment_mode" x-model="paymentMode">
            <input type="hidden" name="payment_channel" x-model="paymentChannel">

            {{-- LEFT COLUMN (3/12): MULTI-PHOTO GALLERY + STORE PROFILE + SAFE TRADING --}}
            <div class="lg:col-span-3 space-y-4 animate-enter" style="--stagger-index: 0">
                {{-- Interactive Multi-Photo Gallery Card with Lightbox Zoom --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <button type="button" @click="lightboxOpen = true" title="Perbesar Foto"
                            class="relative block w-full aspect-[16/10] bg-[#F1F5F9] overflow-hidden border-b border-[#F1F5F9] group cursor-zoom-in">
                        <img :src="gallery[activePhotoIdx].url" :alt="gallery[activePhotoIdx].alt"
                             class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.02]">
                        <span class="absolute top-2 right-2 p-1.5 rounded-lg bg-white/90 border border-[#E4E8EE] text-[#111827] opacity-85 group-hover:opacity-100">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><line x1="11" x2="11" y1="8" y2="14"/><line x1="8" x2="14" y1="11" y2="11"/></svg>
                        </span>
                        <span x-show="gallery.length > 1" x-cloak
                              class="absolute bottom-2 right-2 px-2 py-0.5 rounded-md bg-[#111827]/75 text-white font-mono-tabular text-[10px] font-semibold"
                              x-text="(activePhotoIdx + 1) + ' / ' + gallery.length"></span>
                    </button>

                    {{-- Clickable Thumbnail Strip --}}
                    <div x-show="gallery.length > 1" x-cloak class="p-2.5 grid grid-cols-4 gap-1.5 border-b border-[#F1F5F9] bg-[#F8FAFC]">
                        <template x-for="(photo, pIdx) in gallery" :key="pIdx">
                            <button type="button" @click="activePhotoIdx = pIdx"
                                    :class="activePhotoIdx === pIdx ? 'ring-2 ring-[#0068E1] border-[#0068E1]' : 'border-[#E4E8EE] opacity-75 hover:opacity-100'"
                                    class="aspect-[16/10] rounded-md overflow-hidden border cursor-pointer transition-all">
                                <img :src="photo.url" :alt="photo.alt" class="w-full h-full object-cover">
                            </button>
                        </template>
                    </div>

                    <div class="p-3.5 space-y-1">
                        <div class="flex items-center justify-between text-[11px] text-[#6B7280]">
                            <span>{{ $game->publisher }}</span>
                            <span class="font-mono-tabular font-semibold text-[#111827]">{{ $product->sku_code }}</span>
                        </div>
                        <div class="text-xs font-semibold text-[#111827]">{{ $game->name }} — {{ $category->name }}</div>
                    </div>
                </div>

                {{-- Store Profile Card with Follow Toggle & Link to Storefront --}}
                @if($product->sellerProfile)
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 space-y-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-bold text-sm flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($product->sellerProfile->store_name, 0, 2)) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('stores.show', $product->sellerProfile->slug) }}" class="flex items-center gap-1 text-xs font-bold text-[#111827] hover:text-[#0068E1]">
                                    <span class="truncate">{{ $product->sellerProfile->store_name }}</span>
                                    <svg class="w-3.5 h-3.5 text-[#0068E1] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/></svg>
                                </a>
                                <div class="flex items-center gap-1.5 text-[11px] mt-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $storeEffectiveOpen ? 'bg-[#00AA5B]' : 'bg-[#DC2626]' }}"></span>
                                    <span class="{{ $storeEffectiveOpen ? 'text-[#047857]' : 'text-[#DC2626]' }} font-medium">
                                        {{ $storeEffectiveOpen ? 'Buka' : 'Tutup' }}
                                    </span>
                                    <span class="text-[#6B7280]">&middot; <span class="font-mono-tabular" x-text="followersCount"></span> Pengikut</span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-2 border-t border-[#F1F5F9] text-center">
                            <div class="p-2 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                <div class="text-[10px] text-[#6B7280]">Rata-rata Kirim</div>
                                <div class="font-mono-tabular text-xs font-bold text-[#111827] mt-0.5">{{ number_format((float) $product->sellerProfile->avg_delivery_minutes, 1) }} mnt</div>
                            </div>
                            <div class="p-2 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                <div class="text-[10px] text-[#6B7280]">Pesanan Sukses</div>
                                <div class="font-mono-tabular text-xs font-bold text-[#047857] mt-0.5">{{ number_format($product->sellerProfile->total_completed_orders) }}</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            @auth
                                @if(! $isOwnProduct)
                                    <button type="button" @click="toggleFollowStore()"
                                            :class="followingStore ? 'bg-[#EFF6FF] border-[#BFDBFE] text-[#0068E1]' : 'bg-[#0068E1] hover:bg-[#0055B8] border-[#0068E1] text-white'"
                                            class="btn-tactile py-1.5 px-2.5 rounded-lg border text-xs font-semibold cursor-pointer">
                                        <span x-text="followingStore ? 'Mengikuti' : '+ Ikuti Toko'"></span>
                                    </button>
                                @endif
                            @endauth
                            <a href="{{ route('stores.show', $product->sellerProfile->slug) }}"
                               class="btn-tactile py-1.5 px-2.5 rounded-lg border border-[#E4E8EE] hover:border-[#0068E1] text-center text-xs font-semibold text-[#111827] hover:text-[#0068E1] {{ $isOwnProduct || ! auth()->check() ? 'col-span-2' : '' }}">
                                Kunjungi Toko
                            </a>
                            @auth
                                @if(! $isOwnProduct)
                                    <button type="button" @click="document.getElementById('start-seller-chat-form').submit()"
                                            class="col-span-2 btn-tactile py-2 px-3 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] text-xs font-bold inline-flex items-center justify-center gap-1.5 cursor-pointer">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <span>Tanya Penjual</span>
                                    </button>
                                @endif
                            @endauth
                        </div>
                    </div>
                @endif

                {{-- Safe Trading Guarantee Box --}}
                <div class="bg-[#ECFDF5] border border-[#A7F3D0] rounded-xl p-4 space-y-2 text-xs text-[#047857]">
                    <div class="flex items-center gap-1.5 font-bold text-[#065F46]">
                        <svg class="w-4 h-4 text-[#00AA5B] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                        <span>Garansi Safe Trading Escrow</span>
                    </div>
                    <p class="text-[11px] leading-relaxed text-[#047857]">
                        Dana Anda ditahan aman selama <strong class="font-mono-tabular">{{ $category->escrow_hold_hours ?? 24 }} jam</strong> setelah pengiriman sebelum dilepaskan ke penjual.
                    </p>
                    @if(($category->warranty_days ?? 0) > 0)
                        <div class="pt-2 border-t border-[#A7F3D0] flex items-center gap-1.5 text-[11px] font-bold text-[#065F46]">
                            <svg class="w-3.5 h-3.5 text-[#00AA5B] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                            <span>Termasuk Garansi Anti-Hackback {{ $category->warranty_days }} Hari</span>
                        </div>
                    @endif
                </div>

                @auth
                    @if(! $isOwnProduct)
                        <button type="button" @click="reportModalOpen = true"
                                class="btn-tactile w-full py-2 px-3 rounded-lg bg-white hover:bg-[#FEF2F2] border border-[#E4E8EE] hover:border-[#FECACA] text-[#6B7280] hover:text-[#DC2626] text-xs font-semibold inline-flex items-center justify-center gap-1.5 cursor-pointer">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>
                            <span>Laporkan Produk</span>
                        </button>
                    @endif
                @endauth
            </div>

            {{-- CENTER COLUMN (5/12): TITLE, WISHLIST, SPECS, DYNAMIC INPUTS + NICKNAME, REVIEWS --}}
            <div class="lg:col-span-5 space-y-4 animate-enter" style="--stagger-index: 1">
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4">
                    {{-- Delivery & SLA Pills + Wishlist Heart Button --}}
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if($deliveryMethod === 'instant_vault')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#ECFDF5] text-[#047857] border border-[#A7F3D0] text-[11px] font-semibold">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    <span>Brankas Instan AES-256</span>
                                </span>
                            @elseif($deliveryMethod === 'api_h2h')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#EFF6FF] text-[#0068E1] border border-[#BFDBFE] text-[11px] font-semibold">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                    <span>Top Up Otomatis H2H</span>
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#F8FAFC] text-[#4B5563] border border-[#E4E8EE] text-[11px] font-semibold">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span>Manual Trade + SLA</span>
                                </span>
                            @endif

                            @if($product->sellerProfile?->is_sla_10m_badge)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#FFF4ED] text-[#C2410C] border border-[#FED7AA] text-[11px] font-semibold">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    <span>10 Menit Kirim</span>
                                </span>
                            @endif

                            @if(($category->warranty_days ?? 0) > 0)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#ECFDF5] text-[#047857] border border-[#A7F3D0] text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                                    <span>Garansi Anti-Hackback {{ $category->warranty_days }} Hari</span>
                                </span>
                            @endif

                            @if($hasActiveFlash)
                                <span class="badge-live-pulse inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#F05A28] text-white text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    <span>Flash Sale Aktif</span>
                                </span>
                            @endif
                        </div>

                        @auth
                            <button type="button" @click="toggleWishlist()"
                                    :class="wishlisted ? 'bg-[#FEF2F2] border-[#FECACA] text-[#DC2626]' : 'bg-white border-[#E4E8EE] text-[#4B5563] hover:border-[#FECACA] hover:text-[#DC2626]'"
                                    class="btn-tactile inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-semibold cursor-pointer">
                                <svg class="w-3.5 h-3.5" :class="wishlisted ? 'fill-[#DC2626]' : 'fill-none'" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                                <span class="font-mono-tabular" x-text="wishlistCount"></span>
                            </button>
                        @endauth
                    </div>

                    <div>
                        <h1 class="text-xl font-bold text-[#111827] leading-snug">{{ $product->title }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-[#6B7280] tabular-nums-clean">
                            <span class="inline-flex items-center gap-1 font-semibold text-[#111827]">
                                <svg class="w-3.5 h-3.5 text-[#F59E0B] fill-[#F59E0B]" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                {{ number_format((float) $product->rating_avg, 2) }} ({{ $product->reviews->count() }} ulasan)
                            </span>
                            <span>&middot;</span>
                            <span>Terjual <strong class="text-[#111827]">{{ number_format($product->sold_count) }}</strong></span>
                            <span>&middot;</span>
                            <span>Target SLA <strong class="text-[#111827]">{{ $product->sla_target_minutes }} menit</strong></span>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-[#F1F5F9] space-y-2.5">
                        @if($hasActiveFlash)
                            <div class="p-3 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-xs font-extrabold text-[#C2410C] inline-flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        <span>{{ $activeFlashItem->flashSale->title }}</span>
                                    </span>
                                    <span class="font-mono-tabular text-[11px] font-bold px-2 py-0.5 rounded bg-[#F05A28] text-white"
                                          x-data="liveCountdown('{{ $activeFlashItem->flashSale->ends_at->toIso8601String() }}')"
                                          x-text="formatted">--:--:--</span>
                                </div>
                                <div class="flex items-baseline justify-between gap-2">
                                    <div class="flex items-baseline gap-2.5 font-mono-tabular">
                                        <span class="text-2xl font-extrabold text-[#F05A28]">
                                            Rp {{ number_format((float) $activeFlashItem->flash_price, 0, ',', '.') }}
                                        </span>
                                        <span class="text-xs text-[#6B7280] line-through">
                                            Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                                        </span>
                                    </div>
                                    <span class="text-[11px] font-mono-tabular font-bold text-[#C2410C]">
                                        Sisa Kuota {{ $activeFlashItem->remainingQuota() }}/{{ $activeFlashItem->flash_quota }} (Maks {{ $activeFlashItem->max_qty_per_user }}/akun)
                                    </span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-white overflow-hidden">
                                    <div class="h-full rounded-full bg-[#F05A28]" style="width: {{ min(100, max(8, $activeFlashItem->quotaProgressPercent())) }}%"></div>
                                </div>
                            </div>
                        @else
                            <div class="flex items-baseline justify-between">
                                <div>
                                    <div class="text-[11px] text-[#6B7280]">Harga Satuan</div>
                                    <div class="text-2xl font-extrabold text-[#F05A28] tabular-nums-clean">
                                        Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                                    </div>
                                </div>
                                <div class="text-right text-xs">
                                    <div class="text-[#6B7280]">Stok Tersedia</div>
                                    <div class="font-mono-tabular font-bold {{ $product->stock > 0 ? 'text-[#047857]' : 'text-[#DC2626]' }}">
                                        {{ $product->stock }} Unit
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Structured Typed Specifications & Attributes Grid --}}
                    @if($product->attributeValues->isNotEmpty())
                        <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-[#6B7280]">Spesifikasi Terverifikasi</div>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                @foreach($product->attributeValues as $av)
                                    <div class="px-3 py-2 rounded-lg bg-white border border-[#E4E8EE] flex items-center justify-between gap-2">
                                        <span class="text-[#6B7280] text-[11px]">{{ $av->categoryAttribute?->name }}</span>
                                        <span class="font-semibold text-[#111827] font-mono-tabular">{{ $av->formattedValue() }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Vault Stock Transparency Bar (Instant Vault Only) --}}
                    @if($vaultStats !== null)
                        <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] flex items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-2 text-[#111827] font-semibold">
                                <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span>Brankas Terverifikasi</span>
                            </div>
                            <div class="flex items-center gap-2.5 font-mono-tabular text-[11px]">
                                <span class="text-[#047857] font-bold">{{ $vaultStats['available'] }} Siap</span>
                                <span class="text-[#C2410C]">{{ $vaultStats['reserved'] }} Dipesan</span>
                                <span class="text-[#6B7280]">{{ $vaultStats['sold'] }} Terjual</span>
                            </div>
                        </div>
                    @endif

                    {{-- Dynamic Game Input Form + Interactive Nickname Checker --}}
                    @if(! empty($schema))
                        <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-[#111827]">Lengkapi Data Akun {{ $game->name }}</span>
                                @if($game->requires_nickname_check)
                                    <span class="text-[11px] font-medium text-[#0068E1]">Live ID Check</span>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach($schema as $field)
                                    <div class="{{ count($schema) === 1 ? 'sm:col-span-2' : '' }}">
                                        <label class="block text-xs font-semibold text-[#374151] mb-1">
                                            {{ $field['label'] }}
                                            @if($field['required'] ?? false)
                                                <span class="text-[#DC2626]">*</span>
                                            @endif
                                        </label>
                                        <input type="text"
                                               inputmode="{{ ($field['type'] ?? 'text') === 'number' ? 'numeric' : 'text' }}"
                                               name="game_inputs[{{ $field['key'] }}]"
                                               x-model="gameInputs['{{ $field['key'] }}']"
                                               placeholder="{{ $field['placeholder'] ?? '' }}"
                                               @if($field['required'] ?? false) required @endif
                                               class="w-full h-9 px-3 text-xs font-mono-tabular bg-white text-[#111827] border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:ring-2 focus:ring-[#0068E1]/15 focus:outline-none">
                                    </div>
                                @endforeach
                            </div>

                            @if($game->requires_nickname_check)
                                <div class="space-y-2 pt-1">
                                    <button type="button" @click="checkNickname()" :disabled="verifying"
                                            class="btn-tactile inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] text-xs font-semibold cursor-pointer disabled:opacity-60">
                                        <svg x-show="!verifying" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                                        <svg x-show="verifying" x-cloak class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                        <span x-text="verifying ? 'Memverifikasi ID Game...' : 'Cek Nickname Game'"></span>
                                    </button>

                                    <div x-show="verifying" x-cloak class="p-3 bg-white border border-[#E4E8EE] rounded-lg space-y-1.5" aria-busy="true">
                                        <div class="flex items-center justify-between">
                                            <div class="h-3.5 w-40 rounded skeleton-shimmer"></div>
                                            <div class="h-3 w-12 rounded skeleton-shimmer"></div>
                                        </div>
                                        <div class="h-3 w-5/6 rounded skeleton-shimmer"></div>
                                    </div>

                                    <div x-show="!verifying && verifyResult" x-cloak
                                         class="p-3 rounded-lg text-xs border"
                                         :class="verifyResult?.valid ? 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857]' : 'bg-[#FEF2F2] border-[#FECACA] text-[#B91C1C]'">
                                        <div class="flex items-center justify-between gap-2 font-bold">
                                            <span x-text="verifyResult?.valid ? verifyResult.nickname : 'ID Tidak Ditemukan'"></span>
                                            <span class="font-mono-tabular text-[11px] opacity-80" x-text="(verifyResult?.latency_ms ?? 0) + ' ms'"></span>
                                        </div>
                                        <p class="text-[11px] mt-0.5 opacity-90" x-text="verifyResult?.message"></p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="p-3.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-xs text-[#1E40AF] flex items-start gap-2.5">
                            <svg class="w-4 h-4 text-[#0068E1] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                            <div>
                                <strong class="font-semibold">Pengiriman Otomatis Tanpa ID Game:</strong>
                                Kode voucher / kredensial terenkripsi AES-256 langsung dapat dibuka di halaman pesanan dalam 0 detik setelah lunas.
                            </div>
                        </div>
                    @endif

                    {{-- Description & Reviews Tabs (Including Seller Reply!) --}}
                    <div class="pt-2">
                        <div class="flex items-center gap-4 border-b border-[#E4E8EE] text-xs font-semibold">
                            <button type="button" @click="activeTab = 'desc'"
                                    :class="activeTab === 'desc' ? 'text-[#0068E1] border-b-2 border-[#0068E1]' : 'text-[#6B7280] hover:text-[#111827]'"
                                    class="pb-2.5 cursor-pointer transition-colors">Deskripsi Produk</button>
                            <button type="button" @click="activeTab = 'reviews'"
                                    :class="activeTab === 'reviews' ? 'text-[#0068E1] border-b-2 border-[#0068E1]' : 'text-[#6B7280] hover:text-[#111827]'"
                                    class="pb-2.5 cursor-pointer transition-colors">Ulasan Pembeli ({{ $product->reviews->count() }})</button>
                        </div>

                        <div x-show="activeTab === 'desc'" class="pt-3 text-[13px] text-[#4B5563] leading-relaxed">
                            {{ $product->description }}
                        </div>

                        <div x-show="activeTab === 'reviews'" x-cloak class="pt-3 divide-y divide-[#F1F5F9]">
                            @forelse($product->reviews as $review)
                                <div class="py-3 space-y-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-semibold text-[#111827]">{{ $review->buyer->name }}</span>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-[#B45309]">
                                            <svg class="w-3 h-3 text-[#F59E0B] fill-[#F59E0B]" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                            {{ $review->rating }}/5 &middot; {{ $review->delivery_duration_minutes }}m
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#4B5563]">{{ $review->comment }}</p>

                                    @if($review->seller_reply)
                                        <div class="ml-3 pl-3 border-l-2 border-[#0068E1] bg-[#F8FAFC] p-2.5 rounded-r-lg text-xs">
                                            <div class="flex items-center justify-between text-[10px] font-semibold text-[#0068E1] mb-0.5">
                                                <span>Balasan Penjual ({{ $product->sellerProfile?->store_name }})</span>
                                                <span class="font-mono-tabular text-[#6B7280]">{{ $review->replied_at?->format('d M H:i') }}</span>
                                            </div>
                                            <p class="text-[#374151]">{{ $review->seller_reply }}</p>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-[#6B7280] py-3">Belum ada ulasan tertulis untuk produk ini.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- RIGHT COLUMN (4/12, STICKY): ATUR JUMLAH & CHECKOUT CARD --}}
            <div class="lg:col-span-4 animate-enter" style="--stagger-index: 2">
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sticky top-20 space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
                        <h2 class="text-sm font-bold text-[#111827]">Atur Jumlah &amp; Checkout</h2>
                        <span class="text-[11px] font-semibold text-[#047857]">Escrow Aktif</span>
                    </div>

                    @if($isOwnProduct)
                        <div class="p-3 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] text-xs">
                            <strong>Self-Dealing Guard:</strong> Anda tidak dapat membeli produk milik toko Anda sendiri.
                        </div>
                    @endif

                    @if($isClosedManual)
                        <div class="p-3 rounded-lg bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-xs">
                            <strong>Toko Tutup / Di Luar Jam Operasional:</strong> Produk manual trade dijeda sementara. Brankas Instan &amp; H2H tetap aktif 24/7.
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-semibold text-[#111827]">Jumlah</div>
                            <div class="text-[11px] text-[#6B7280]">Min {{ $product->min_order }} &middot; Maks {{ min($product->max_order, max(1, $product->stock)) }}</div>
                        </div>
                        <div class="inline-flex items-center border border-[#D1D5DB] rounded-lg overflow-hidden bg-white">
                            <button type="button" @click="qty = Math.max({{ $product->min_order }}, qty - 1)"
                                    class="btn-tactile w-8 h-8 flex items-center justify-center text-[#111827] hover:bg-[#F8FAFC] cursor-pointer">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>
                            </button>
                            <input type="number" name="quantity" x-model.number="qty" min="{{ $product->min_order }}" max="{{ max(1, min($product->max_order, $product->stock)) }}"
                                   class="w-12 h-8 text-center text-xs font-mono-tabular font-bold text-[#111827] border-x border-[#E4E8EE] focus:outline-none">
                            <button type="button" @click="qty = Math.min({{ max(1, min($product->max_order, $product->stock)) }}, qty + 1)"
                                    class="btn-tactile w-8 h-8 flex items-center justify-center text-[#111827] hover:bg-[#F8FAFC] cursor-pointer">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                        <div class="text-[11px] font-semibold text-[#4B5563]">Metode untuk Beli Instan</div>
                        <div class="grid grid-cols-3 gap-1.5 text-[11px]">
                            <button type="button" @click="paymentMode = 'wallet_full'"
                                    :class="paymentMode === 'wallet_full' ? 'bg-[#EFF6FF] text-[#0068E1] border-[#0068E1] font-semibold' : 'bg-white text-[#4B5563] border-[#E4E8EE]'"
                                    class="btn-tactile py-1.5 px-2 rounded-lg border cursor-pointer">Dompet</button>
                            <button type="button" @click="paymentMode = 'hybrid_wallet_gateway'"
                                    :class="paymentMode === 'hybrid_wallet_gateway' ? 'bg-[#EFF6FF] text-[#0068E1] border-[#0068E1] font-semibold' : 'bg-white text-[#4B5563] border-[#E4E8EE]'"
                                    class="btn-tactile py-1.5 px-2 rounded-lg border cursor-pointer">Hibrida</button>
                            <button type="button" @click="paymentMode = 'gateway_only'"
                                    :class="paymentMode === 'gateway_only' ? 'bg-[#EFF6FF] text-[#0068E1] border-[#0068E1] font-semibold' : 'bg-white text-[#4B5563] border-[#E4E8EE]'"
                                    class="btn-tactile py-1.5 px-2 rounded-lg border cursor-pointer">Midtrans</button>
                        </div>

                        <div x-show="paymentMode === 'hybrid_wallet_gateway'" x-cloak class="pt-1">
                            <label class="block text-[11px] text-[#6B7280] mb-1">Potong Saldo Dompet (Rp)</label>
                            <input type="number" name="wallet_amount" x-model.number="walletAmount" step="1000" min="1000"
                                   class="w-full h-8 px-2.5 font-mono-tabular text-xs bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    <div class="pt-3 border-t border-[#F1F5F9] flex items-center justify-between">
                        <span class="text-xs text-[#6B7280]">Subtotal</span>
                        <span class="font-mono-tabular text-lg font-extrabold text-[#111827]" x-text="window.formatRupiah(qty * unitPrice)"></span>
                    </div>

                    <div class="space-y-2.5 pt-1">
                        <button type="submit" @click="actionMode = 'instant_buy'"
                                @disabled($isOwnProduct || $isClosedManual || $product->stock < 1)
                                class="btn-tactile w-full h-11 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-45 disabled:cursor-not-allowed">
                            <svg x-show="submittingAction === 'instant_buy'" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <svg x-show="submittingAction !== 'instant_buy'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span x-text="submittingAction === 'instant_buy' ? 'Memproses Pesanan...' : 'Beli Instan'"></span>
                        </button>

                        <button type="submit" @click="actionMode = 'cart'"
                                @disabled($isOwnProduct || $isClosedManual || $product->stock < 1)
                                class="btn-tactile w-full h-10 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] text-sm font-semibold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-45 disabled:cursor-not-allowed">
                            <svg x-show="submittingAction === 'cart'" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <svg x-show="submittingAction !== 'cart'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            <span x-text="submittingAction === 'cart' ? 'Menyimpan...' : 'Tambah ke Keranjang'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </form>

        @auth
            @if(! $isOwnProduct && $product->sellerProfile)
                <form id="start-seller-chat-form" method="POST" action="{{ route('inbox.start') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="seller_id" value="{{ $product->seller_id }}">
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                </form>
            @endif
        @endauth

        {{-- Related Products --}}
        @if($relatedProducts->isNotEmpty())
            <section x-show="!navigatingRelated" class="mt-8">
                <h2 class="text-sm font-bold text-[#111827] mb-3">Produk Serupa Lainnya</h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5">
                    @foreach($relatedProducts as $rp)
                        <a href="{{ route('marketplace.show', $rp->slug) }}" @click="navigatingRelated = true"
                           class="bg-white border border-[#E4E8EE] rounded-xl p-3.5 card-elevate flex flex-col justify-between">
                            <div>
                                <div class="text-[11px] text-[#6B7280]">{{ $rp->sellerProfile?->store_name }}</div>
                                <div class="text-xs font-semibold text-[#111827] line-clamp-2 mt-1">{{ $rp->title }}</div>
                            </div>
                            <div class="mt-2.5 text-sm font-extrabold text-[#F05A28] tabular-nums-clean">
                                Rp {{ number_format((float) $rp->price, 0, ',', '.') }}
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
        {{-- Multi-Photo Gallery Lightbox Zoom Modal --}}
        <div x-show="lightboxOpen" x-cloak @keydown.escape.window="lightboxOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 backdrop-blur-[2px] p-4">
            <div @click.outside="lightboxOpen = false" class="bg-white border border-[#E4E8EE] rounded-xl shadow-2xl max-w-3xl w-full overflow-hidden">
                <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between gap-2">
                    <span class="text-xs font-bold text-[#111827] truncate" x-text="gallery[activePhotoIdx].alt"></span>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="font-mono-tabular text-[11px] text-[#6B7280]" x-text="(activePhotoIdx + 1) + ' / ' + gallery.length"></span>
                        <button type="button" @click="lightboxOpen = false" class="p-1 text-[#6B7280] hover:text-[#111827] cursor-pointer">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div class="p-4 bg-[#F4F6F9] relative">
                    <img :src="gallery[activePhotoIdx].url" :alt="gallery[activePhotoIdx].alt"
                         class="w-full max-h-[68vh] object-contain rounded-lg border border-[#E4E8EE] bg-white">
                    <template x-if="gallery.length > 1">
                        <div class="mt-3 flex items-center justify-center gap-2">
                            <template x-for="(photo, idx) in gallery" :key="idx">
                                <button type="button" @click="activePhotoIdx = idx"
                                        :class="activePhotoIdx === idx ? 'ring-2 ring-[#0068E1] border-[#0068E1]' : 'border-[#E4E8EE] opacity-70 hover:opacity-100'"
                                        class="w-16 aspect-[16/10] rounded-md overflow-hidden border bg-white cursor-pointer">
                                    <img :src="photo.url" :alt="photo.alt" class="w-full h-full object-cover">
                                </button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Laporkan Produk Modal (Standalone Outside Cart Form) --}}
        @auth
            @if(! $isOwnProduct)
                <div x-show="reportModalOpen" x-cloak @keydown.escape.window="reportModalOpen = false"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 backdrop-blur-[2px] p-4">
                    <div @click.outside="reportModalOpen = false" class="bg-white border border-[#E4E8EE] shadow-2xl max-w-md w-full rounded-xl overflow-hidden">
                        <div class="px-5 py-3.5 bg-[#FEF2F2] border-b border-[#FECACA] flex items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-bold text-[#991B1B]">Laporkan Produk Ini</h3>
                                <p class="text-[11px] text-[#B91C1C]">Laporan diteruskan langsung ke Tim Trust &amp; Safety NexaTrade.</p>
                            </div>
                            <button type="button" @click="reportModalOpen = false" class="p-1 text-[#B91C1C] hover:text-[#7F1D1D] cursor-pointer">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </button>
                        </div>
                        <form method="POST" action="{{ route('reports.store') }}" class="p-5 space-y-3.5 text-xs">
                            @csrf
                            <input type="hidden" name="target_type" value="product">
                            <input type="hidden" name="target_id" value="{{ $product->id }}">

                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Kategori Pelanggaran *</label>
                                <select name="reason_category" required
                                        class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#DC2626] focus:outline-none">
                                    @foreach(\App\Models\ModerationReport::REASON_LABELS as $rKey => $rLabel)
                                        <option value="{{ $rKey }}">{{ $rLabel }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Detail Laporan *</label>
                                <textarea name="details" rows="3" required minlength="10"
                                          placeholder="Jelaskan indikasi pelanggaran, penipuan, atau ketidaksesuaian produk..."
                                          class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#DC2626] focus:outline-none"></textarea>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-1">
                                <button type="button" @click="reportModalOpen = false" class="btn-tactile px-3.5 py-2 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold cursor-pointer">Batal</button>
                                <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">Kirim Laporan</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endauth
    </div>
@endsection
