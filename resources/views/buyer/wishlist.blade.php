@extends('layouts.app')

@section('title', 'Wishlist & Toko Diikuti — NexaTrade')

@section('content')
    <div class="space-y-5">
        {{-- Header Bar --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#FEF2F2] border border-[#FECACA] text-[#DC2626] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                </div>
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Wishlist &amp; Toko yang Diikuti</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        <strong class="font-mono-tabular text-[#111827]">{{ $products->count() }}</strong> produk favorit &middot;
                        <strong class="font-mono-tabular text-[#0068E1]">{{ $followedStores->count() }}</strong> toko diikuti.
                    </p>
                </div>
            </div>

            <a href="{{ route('marketplace.index') }}"
               class="btn-tactile px-4 py-2 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-xs font-bold inline-flex items-center gap-1.5">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m15 18-6-6 6-6"/></svg>
                <span>Jelajahi Katalog</span>
            </a>
        </div>

        {{-- Tabs: Wishlist Produk | Toko yang Diikuti --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-2 flex flex-wrap gap-1.5 animate-enter" style="--stagger-index: 1">
            <a href="{{ route('buyer.wishlist', ['tab' => 'wishlist']) }}"
               class="btn-tactile px-4 py-2 rounded-lg text-xs font-bold inline-flex items-center gap-2 {{ $tab === 'wishlist' ? 'bg-[#0068E1] text-white' : 'text-[#4B5563] hover:bg-[#F8FAFC]' }}">
                <span>Wishlist Produk</span>
                <span class="font-mono-tabular text-[10px] px-1.5 py-0.2 rounded-full {{ $tab === 'wishlist' ? 'bg-white/20 text-white' : 'bg-[#FEF2F2] text-[#DC2626]' }}">{{ $products->count() }}</span>
            </a>
            <a href="{{ route('buyer.wishlist', ['tab' => 'following']) }}"
               class="btn-tactile px-4 py-2 rounded-lg text-xs font-bold inline-flex items-center gap-2 {{ $tab === 'following' ? 'bg-[#0068E1] text-white' : 'text-[#4B5563] hover:bg-[#F8FAFC]' }}">
                <span>Toko yang Diikuti</span>
                <span class="font-mono-tabular text-[10px] px-1.5 py-0.2 rounded-full {{ $tab === 'following' ? 'bg-white/20 text-white' : 'bg-[#EFF6FF] text-[#0068E1]' }}">{{ $followedStores->count() }}</span>
            </a>
        </div>

        {{-- TAB 1: Saved Wishlist Products --}}
        @if($tab === 'wishlist')
            @if($products->isEmpty())
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter" style="--stagger-index: 2">
                    <div class="w-12 h-12 rounded-full bg-[#FEF2F2] text-[#DC2626] mx-auto flex items-center justify-center mb-3">
                        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                    </div>
                    <h2 class="text-base font-bold text-[#111827]">Belum ada produk di Wishlist</h2>
                    <p class="text-xs text-[#6B7280] mt-1 max-w-md mx-auto">
                        Klik ikon hati pada kartu produk atau halaman detail untuk memantau harga dan ketersediaan stok.
                    </p>
                    <a href="{{ route('marketplace.index') }}"
                       class="btn-tactile inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold">
                        <span>Cari Produk Digital</span>
                    </a>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    @foreach($products as $idx => $product)
                        @php
                            $method = $product->gameCategory->delivery_method;
                            $hasRequiredInputs = ! empty($product->gameCategory->required_inputs_schema);
                            $cornerPill = match ($method) {
                                'instant_vault' => ['text' => 'Instan', 'class' => 'bg-[#00AA5B] text-white'],
                                'api_h2h' => ['text' => 'H2H Auto', 'class' => 'bg-[#0068E1] text-white'],
                                default => ['text' => 'Manual', 'class' => 'bg-[#111827]/80 text-white'],
                            };
                        @endphp
                        <div style="--stagger-index: {{ min($idx, 8) }}"
                             class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden flex flex-col justify-between card-elevate animate-enter">
                            <div>
                                <a href="{{ route('marketplace.show', $product->slug) }}" class="block relative aspect-[16/10] bg-[#F1F5F9] overflow-hidden border-b border-[#F1F5F9] group">
                                    <img src="{{ $product->primaryThumbnailUrl() }}" alt="{{ $product->title }}"
                                         class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]">
                                    <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md text-[10px] font-bold {{ $cornerPill['class'] }}">
                                        {{ $cornerPill['text'] }}
                                    </span>
                                </a>

                                <div class="p-3.5 space-y-1.5">
                                    <div class="text-[11px] font-medium text-[#6B7280] truncate">
                                        {{ $product->gameCategory->game->name }} &middot; {{ $product->gameCategory->name }}
                                    </div>
                                    <a href="{{ route('marketplace.show', $product->slug) }}"
                                       class="block text-[13px] font-bold text-[#111827] hover:text-[#0068E1] leading-snug line-clamp-2">
                                        {{ $product->title }}
                                    </a>
                                    <div class="font-mono-tabular text-[16px] font-extrabold text-[#F05A28] pt-0.5">
                                        Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                                    </div>
                                    <div class="flex items-center justify-between text-[11px] text-[#6B7280]">
                                        @if($product->sellerProfile)
                                            <a href="{{ route('stores.toko', $product->sellerProfile->slug) }}" class="hover:text-[#0068E1] truncate font-medium">
                                                {{ $product->sellerProfile->store_name }}
                                            </a>
                                        @endif
                                        <span class="font-mono-tabular shrink-0 {{ $product->stock > 0 ? 'text-[#047857]' : 'text-[#DC2626]' }} font-semibold">
                                            Stok {{ $product->stock }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="px-3.5 pb-3.5 pt-2.5 border-t border-[#F1F5F9] flex items-center gap-2">
                                @if(! $hasRequiredInputs && $product->stock > 0 && (int) $product->seller_id !== (int) auth()->id())
                                    <form method="POST" action="{{ route('cart.add') }}" class="flex-1">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <input type="hidden" name="action_mode" value="add_to_cart">
                                        <button type="submit"
                                                class="btn-tactile w-full py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold cursor-pointer">
                                            + Keranjang
                                        </button>
                                    </form>
                                @else
                                    <a href="{{ route('marketplace.show', $product->slug) }}"
                                       class="btn-tactile flex-1 text-center py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold">
                                        Lihat &amp; Beli
                                    </a>
                                @endif

                                <form method="POST" action="{{ route('wishlist.toggle.alt', $product) }}">
                                    @csrf
                                    <button type="submit" title="Hapus dari Wishlist"
                                            class="btn-tactile px-2.5 py-2 rounded-lg bg-[#FEF2F2] hover:bg-[#FEE2E2] border border-[#FECACA] text-[#DC2626] cursor-pointer">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

        {{-- TAB 2: Followed Stores --}}
        @else
            @if($followedStores->isEmpty())
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter" style="--stagger-index: 2">
                    <h2 class="text-base font-bold text-[#111827]">Belum ada toko yang diikuti</h2>
                    <p class="text-xs text-[#6B7280] mt-1">Ikuti toko terpercaya favorit Anda untuk akses cepat ke etalase produknya.</p>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($followedStores as $idx => $store)
                        @php $isOpen = $store->isCurrentlyOpen(); @endphp
                        <div style="--stagger-index: {{ min($idx, 8) }}"
                             class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden flex flex-col justify-between card-elevate animate-enter">
                            <div class="p-4 space-y-3">
                                <div class="flex items-start gap-3">
                                    <div class="w-11 h-11 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-extrabold text-sm flex items-center justify-center shrink-0">
                                        {{ strtoupper(substr($store->store_name, 0, 2)) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('stores.toko', $store->slug) }}" class="text-sm font-bold text-[#111827] hover:text-[#0068E1] truncate block">
                                            {{ $store->store_name }}
                                        </a>
                                        <div class="flex items-center gap-2 text-[11px] mt-0.5">
                                            <span class="inline-flex items-center gap-1 font-semibold {{ $isOpen ? 'text-[#047857]' : 'text-[#DC2626]' }}">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $isOpen ? 'bg-[#00AA5B]' : 'bg-[#DC2626]' }}"></span>
                                                {{ $isOpen ? 'Buka' : 'Tutup' }}
                                            </span>
                                            <span class="text-[#6B7280] font-mono-tabular">&middot; {{ $store->followers_count }} Pengikut</span>
                                            <span class="text-[#6B7280] font-mono-tabular">&middot; {{ $store->products_count }} Produk</span>
                                        </div>
                                    </div>
                                </div>
                                @if($store->store_tagline)
                                    <p class="text-xs text-[#6B7280] line-clamp-2">{{ $store->store_tagline }}</p>
                                @endif
                            </div>

                            <div class="px-4 pb-4 pt-3 border-t border-[#F1F5F9] flex items-center gap-2">
                                <a href="{{ route('stores.toko', $store->slug) }}"
                                   class="btn-tactile flex-1 text-center py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold">
                                    Kunjungi Toko
                                </a>
                                <form method="POST" action="{{ route('stores.follow', $store->user_id) }}">
                                    @csrf
                                    <button type="submit" class="btn-tactile px-3 py-2 rounded-lg bg-white hover:bg-[#FEF2F2] border border-[#E4E8EE] hover:border-[#FECACA] text-[#4B5563] hover:text-[#DC2626] text-xs font-semibold cursor-pointer">
                                        Berhenti
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
@endsection
