@extends('layouts.app')

@section('title', $store->store_name . ' — Etalase Toko NexaTrade')

@section('content')
    @php
        $isOpenNow = $store->isCurrentlyOpen();
        $todayDow = (int) now()->timezone('Asia/Jakarta')->dayOfWeek;
        $hoursByDay = $store->operatingHours->keyBy('day_of_week');
        $dayNames = \App\Models\StoreOperatingHour::DAY_NAMES;
        $isOwnStore = auth()->check() && (int) auth()->id() === (int) $store->user_id;
    @endphp

    <div class="space-y-6">
        {{-- Breadcrumb --}}
        <nav class="flex items-center gap-1.5 text-xs text-[#6B7280] animate-enter" style="--stagger-index: 0">
            <a href="{{ route('marketplace.index') }}" class="hover:text-[#0068E1] font-medium">Beranda</a>
            <svg class="w-3.5 h-3.5 text-[#9CA3AF]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m9 18 6-6-6-6"/></svg>
            <span class="text-[#111827] font-semibold">{{ $store->store_name }}</span>
        </nav>

        {{-- Storefront Hero Banner, 4 KPI Pills & Expandable 7-Day Operating Schedule Popover --}}
        <section class="bg-white border border-[#E4E8EE] rounded-xl overflow-visible animate-enter" style="--stagger-index: 1"
                 x-data="{
                     following: {{ $isFollowing ? 'true' : 'false' }},
                     followersCount: {{ (int) $store->followers_count }},
                     loadingFollow: false,
                     followError: '',
                     scheduleOpen: false,
                     async toggleFollow() {
                         this.loadingFollow = true;
                         this.followError = '';
                         try {
                             const res = await fetch('{{ route('stores.follow', $store->user_id) }}', {
                                 method: 'POST',
                                 headers: {
                                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                     'Accept': 'application/json',
                                     'Content-Type': 'application/json'
                                 }
                             });
                             const data = await res.json();
                             if (!res.ok || !data.ok) {
                                 this.followError = data.message || 'Gagal memperbarui status ikuti toko.';
                                 return;
                             }
                             this.following = data.following;
                             this.followersCount = data.followers_count;
                         } catch (e) {
                             this.followError = 'Koneksi terganggu. Silakan coba lagi.';
                         } finally {
                             this.loadingFollow = false;
                         }
                     }
                 }">
            {{-- Store Banner --}}
            <div class="relative h-36 sm:h-48 bg-[#EFF6FF] border-b border-[#E4E8EE] rounded-t-xl overflow-hidden">
                @if($store->bannerPublicUrl())
                    <img src="{{ $store->bannerPublicUrl() }}" alt="Banner {{ $store->store_name }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full bg-gradient-to-r from-[#EFF6FF] via-[#F8FAFC] to-[#ECFDF5] flex items-center justify-center">
                        <span class="text-xs font-semibold text-[#6B7280] uppercase tracking-widest">{{ $store->store_name }} Official Storefront</span>
                    </div>
                @endif
            </div>

            {{-- Store Profile Body --}}
            <div class="p-5 sm:p-6 space-y-5">
                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-5">
                    <div class="flex items-start gap-4 min-w-0">
                        <div class="w-16 h-16 rounded-xl bg-[#0068E1] text-white font-extrabold text-xl flex items-center justify-center shrink-0 border-4 border-white shadow-sm -mt-12 relative z-10">
                            {{ strtoupper(substr($store->store_name, 0, 2)) }}
                        </div>

                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl font-extrabold text-[#111827] tracking-tight">{{ $store->store_name }}</h1>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] text-[10px] font-bold">
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                                    <span>Verified Lv.{{ $store->store_level }}</span>
                                </span>
                                @if($store->is_sla_10m_badge)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold">
                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        <span>10 Menit Kirim</span>
                                    </span>
                                @endif

                                {{-- Live Open/Close Status Pill + Expandable 7-Day Operating Hours Popover --}}
                                <div class="relative">
                                    <button type="button" @click="scheduleOpen = !scheduleOpen"
                                            class="btn-tactile inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold border cursor-pointer {{ $isOpenNow ? 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]' : 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $isOpenNow ? 'bg-[#00AA5B]' : 'bg-[#DC2626]' }}"></span>
                                        <span>{{ $isOpenNow ? 'Buka Sekarang' : 'Manual Trade Tutup' }}</span>
                                        <svg class="w-3 h-3 transition-transform" :class="scheduleOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>

                                    <div x-show="scheduleOpen" x-cloak @click.outside="scheduleOpen = false"
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                         class="absolute left-0 mt-2 w-72 bg-white border border-[#E4E8EE] rounded-xl shadow-xl p-4 z-40 space-y-2.5">
                                        <div class="flex items-center justify-between pb-2 border-b border-[#F1F5F9]">
                                            <span class="text-xs font-bold text-[#111827]">Jadwal Operasional 7 Hari</span>
                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-[#EFF6FF] text-[#0068E1]">
                                                {{ $store->auto_schedule_enabled ? 'Otomatis WIB' : 'Manual' }}
                                            </span>
                                        </div>
                                        <div class="space-y-1 text-xs">
                                            @foreach($dayNames as $dow => $dayLabel)
                                                @php $row = $hoursByDay->get($dow); @endphp
                                                <div class="flex items-center justify-between px-2 py-1 rounded {{ $dow === $todayDow ? 'bg-[#EFF6FF] font-bold text-[#0068E1]' : 'text-[#4B5563]' }}">
                                                    <span>{{ $dayLabel }}</span>
                                                    <span class="font-mono-tabular text-[11px] {{ $row?->is_closed ? 'text-[#DC2626]' : '' }}">
                                                        {{ $row ? $row->formattedWindow() : '08:00 - 22:00 WIB' }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                        <p class="text-[10px] text-[#047857] bg-[#ECFDF5] border border-[#A7F3D0] rounded-lg p-2">
                                            Brankas Instan AES-256 &amp; Top-Up H2H tetap dapat dibeli 24 jam nonstop.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            @if($store->store_tagline)
                                <p class="text-xs font-semibold text-[#374151]">{{ $store->store_tagline }}</p>
                            @endif

                            @if($store->store_bio)
                                <p class="text-xs text-[#6B7280] leading-relaxed max-w-2xl">{{ $store->store_bio }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex flex-col items-start lg:items-end gap-2 shrink-0">
                        @auth
                            @if($isOwnStore)
                                <a href="{{ route('seller.settings') }}"
                                   class="btn-tactile px-4 py-2 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-xs font-bold inline-flex items-center gap-1.5">
                                    <span>Edit Profil &amp; Jam Buka</span>
                                </a>
                            @else
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" @click="toggleFollow()" :disabled="loadingFollow"
                                            :class="following ? 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857] hover:bg-[#D1FAE5]' : 'bg-[#0068E1] border-[#0068E1] text-white hover:bg-[#0055B8]'"
                                            class="btn-tactile px-4 py-2 rounded-lg border text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-60">
                                        <svg x-show="!following" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                        <svg x-show="following" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span x-text="following ? 'Mengikuti' : 'Ikuti Toko'"></span>
                                    </button>

                                    <form method="POST" action="{{ route('inbox.start') }}">
                                        @csrf
                                        <input type="hidden" name="seller_id" value="{{ $store->user_id }}">
                                        <button type="submit"
                                                class="btn-tactile px-4 py-2 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <span>Tanya Penjual</span>
                                        </button>
                                    </form>
                                </div>
                                <p x-show="followError" x-cloak x-text="followError" class="text-[11px] text-[#DC2626] font-medium"></p>
                            @endif
                        @endauth
                    </div>
                </div>

                {{-- Vacation Mode Subtle Amber Info Banner --}}
                @if($store->is_on_vacation)
                    <div class="p-3.5 rounded-xl bg-[#FFFBEB] border border-[#FDE68A] text-[#92400E] text-xs flex items-start gap-2.5">
                        <svg class="w-4 h-4 text-[#F59E0B] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                        <div>
                            <div class="font-bold text-[#92400E]">Toko Sedang Libur Sementara (Vacation Mode)</div>
                            <p class="text-[11px] text-[#B45309] mt-0.5">
                                {{ $store->vacation_note ?: 'Pesanan Manual Trade dijeda sementara selama toko libur.' }}
                                <strong class="text-[#047857]">Produk Brankas Instan AES-256 &amp; Top-Up H2H tetap dapat dibeli 24 jam nonstop!</strong>
                            </p>
                        </div>
                    </div>
                @endif

                {{-- 4 Store KPI Pills --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 pt-4 border-t border-[#F1F5F9]">
                    <div class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-[#FFF4ED] border border-[#FED7AA] text-[#F59E0B] flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 fill-[#F59E0B]" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase text-[#6B7280]">Rating Toko</div>
                            <div class="font-mono-tabular text-sm font-extrabold text-[#111827]">
                                {{ number_format((float) $ratingSummary['average'], 2) }} <span class="text-[11px] font-normal text-[#6B7280]">({{ $ratingSummary['total'] }})</span>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-[#00AA5B] flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase text-[#6B7280]">Pesanan Selesai</div>
                            <div class="font-mono-tabular text-sm font-extrabold text-[#047857]">{{ number_format((int) $store->total_completed_orders) }}</div>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase text-[#6B7280]">Kecepatan Kirim</div>
                            <div class="font-mono-tabular text-sm font-extrabold text-[#111827]">{{ number_format((float) $store->avg_delivery_minutes, 1) }} mnt</div>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase text-[#6B7280]">Pengikut Toko</div>
                            <div class="font-mono-tabular text-sm font-extrabold text-[#111827]" x-text="followersCount">{{ (int) $store->followers_count }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Storefront Tabs: Etalase Produk vs Ulasan Pembeli --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-2 flex flex-wrap items-center justify-between gap-2 animate-enter" style="--stagger-index: 2">
            <div class="flex items-center gap-1.5">
                <a href="{{ route('stores.toko', ['slug' => $store->slug, 'tab' => 'products']) }}"
                   class="btn-tactile px-4 py-2 rounded-lg text-xs font-bold inline-flex items-center gap-2 {{ $activeTab === 'products' ? 'bg-[#0068E1] text-white' : 'text-[#4B5563] hover:bg-[#F8FAFC]' }}">
                    <span>Etalase Produk</span>
                    <span class="font-mono-tabular text-[10px] px-1.5 py-0.2 rounded-full {{ $activeTab === 'products' ? 'bg-white/20 text-white' : 'bg-[#F1F5F9] text-[#4B5563]' }}">{{ $products->total() }}</span>
                </a>
                <a href="{{ route('stores.toko', ['slug' => $store->slug, 'tab' => 'reviews']) }}"
                   class="btn-tactile px-4 py-2 rounded-lg text-xs font-bold inline-flex items-center gap-2 {{ $activeTab === 'reviews' ? 'bg-[#0068E1] text-white' : 'text-[#4B5563] hover:bg-[#F8FAFC]' }}">
                    <span>Ulasan Pembeli</span>
                    <span class="font-mono-tabular text-[10px] px-1.5 py-0.2 rounded-full {{ $activeTab === 'reviews' ? 'bg-white/20 text-white' : 'bg-[#FFF4ED] text-[#C2410C]' }}">{{ $ratingSummary['total'] }}</span>
                </a>
            </div>
        </div>

        {{-- TAB 1: ETALASE PRODUK --}}
        @if($activeTab === 'products')
            <div class="space-y-4">
                {{-- Filter, Search & Sort Bar --}}
                <form method="GET" action="{{ route('stores.toko', $store->slug) }}"
                      class="bg-white border border-[#E4E8EE] rounded-xl p-3.5 flex flex-wrap items-center justify-between gap-3">
                    <input type="hidden" name="tab" value="products">
                    <div class="flex flex-wrap items-center gap-2">
                        @php
                            $delivTabs = [
                                '' => 'Semua',
                                'instant_vault' => 'Brankas Instan',
                                'api_h2h' => 'Top Up H2H',
                                'manual_trade' => 'Manual Trade',
                            ];
                        @endphp
                        @foreach($delivTabs as $key => $label)
                            <a href="{{ route('stores.toko', array_filter(['slug' => $store->slug, 'tab' => 'products', 'delivery' => $key, 'q' => $search, 'sort' => $selectedSort])) }}"
                               class="btn-tactile px-3 py-1.5 rounded-lg text-xs font-semibold border {{ $deliveryFilter === $key ? 'bg-[#0068E1] text-white border-[#0068E1]' : 'bg-[#F8FAFC] text-[#4B5563] border-[#E4E8EE] hover:bg-[#F1F5F9]' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" name="q" value="{{ $search }}" placeholder="Cari di toko ini..."
                               class="h-8 px-3 text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        <select name="sort" onchange="this.form.submit()"
                                class="h-8 px-2.5 text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg">
                            <option value="recommended" @selected($selectedSort === 'recommended')>Paling Sesuai</option>
                            <option value="bestseller" @selected($selectedSort === 'bestseller')>Paling Laris</option>
                            <option value="price_asc" @selected($selectedSort === 'price_asc')>Harga Terendah</option>
                            <option value="price_desc" @selected($selectedSort === 'price_desc')>Harga Tertinggi</option>
                            <option value="fastest" @selected($selectedSort === 'fastest')>Tercepat</option>
                        </select>
                        <button type="submit" class="btn-tactile h-8 px-3 rounded-lg bg-[#0068E1] text-white text-xs font-semibold cursor-pointer">Cari</button>
                    </div>
                </form>

                {{-- Dense 4-Column Product Grid --}}
                @if($products->isEmpty())
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-10 text-center text-xs text-[#6B7280]">
                        Belum ada produk aktif yang sesuai dengan filter pencarian Anda di toko ini.
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        @foreach($products as $idx => $product)
                            @php
                                $method = $product->gameCategory->delivery_method;
                                $isClosedManual = $method === 'manual_trade' && ! $isOpenNow;
                                $cornerPill = match ($method) {
                                    'instant_vault' => ['text' => 'Instan', 'class' => 'bg-[#00AA5B] text-white'],
                                    'api_h2h' => ['text' => 'H2H Auto', 'class' => 'bg-[#0068E1] text-white'],
                                    default => ['text' => 'Manual', 'class' => 'bg-[#111827]/80 text-white'],
                                };
                                $inWishlist = in_array((int) $product->id, $wishlistedIds, true);
                            @endphp
                            <div style="--stagger-index: {{ min($idx, 8) }}"
                                 x-data="{
                                     saved: {{ $inWishlist ? 'true' : 'false' }},
                                     count: {{ (int) ($product->wishlists_count ?? $product->wishlisted_by_count ?? 0) }},
                                     async toggleHeart() {
                                         @auth
                                         try {
                                             const res = await fetch('{{ route('wishlist.toggle.alt', $product) }}', {
                                                 method: 'POST',
                                                 headers: {
                                                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                     'Accept': 'application/json',
                                                 },
                                             });
                                             const data = await res.json();
                                             if (data.ok) {
                                                 this.saved = data.wishlisted;
                                                 this.count = data.wishlist_count;
                                             }
                                         } catch (e) {}
                                         @endauth
                                     }
                                 }"
                                 class="group bg-white border border-[#E4E8EE] rounded-xl overflow-hidden flex flex-col justify-between card-elevate animate-enter">
                                <div>
                                    <div class="relative aspect-[16/10] bg-[#F1F5F9] overflow-hidden border-b border-[#F1F5F9]">
                                        <a href="{{ route('marketplace.show', $product->slug) }}" class="block w-full h-full">
                                            <img src="{{ $product->primaryThumbnailUrl() }}" alt="{{ $product->title }}"
                                                 class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]">
                                        </a>
                                        <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md text-[10px] font-bold pointer-events-none {{ $cornerPill['class'] }}">
                                            {{ $cornerPill['text'] }}
                                        </span>
                                        @auth
                                            <button type="button" @click.stop="toggleHeart()" title="Simpan ke Wishlist"
                                                    class="absolute top-2 right-2 w-7 h-7 rounded-full bg-white/95 border border-[#E4E8EE] shadow-xs flex items-center justify-center cursor-pointer">
                                                <svg class="w-3.5 h-3.5" :class="saved ? 'text-[#DC2626] fill-[#DC2626]' : 'text-[#6B7280]'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                                            </button>
                                        @endauth
                                        @if($product->images->count() > 1)
                                            <span class="absolute bottom-2 right-2 px-1.5 py-0.5 rounded bg-[#111827]/75 text-white font-mono-tabular text-[10px] font-semibold">
                                                {{ $product->images->count() }} Foto
                                            </span>
                                        @endif
                                        @if($isClosedManual)
                                            <span class="absolute bottom-2 left-2 px-2 py-0.5 rounded bg-[#DC2626]/90 text-white text-[10px] font-bold">
                                                Manual Tutup
                                            </span>
                                        @endif
                                    </div>

                                    <div class="p-3.5 space-y-2">
                                        <div class="text-[11px] font-medium text-[#6B7280] truncate">
                                            {{ $product->gameCategory->game->name }} &middot; {{ $product->gameCategory->name }}
                                        </div>

                                        <a href="{{ route('marketplace.show', $product->slug) }}"
                                           class="block text-[13px] font-bold text-[#111827] leading-snug line-clamp-2 group-hover:text-[#0068E1]">
                                            {{ $product->title }}
                                        </a>

                                        @if($product->attributeValues->isNotEmpty())
                                            <div class="flex flex-wrap gap-1 pt-0.5">
                                                @foreach($product->attributeValues->take(3) as $av)
                                                    <span class="px-1.5 py-0.5 rounded bg-[#F8FAFC] border border-[#E4E8EE] text-[10px] font-medium text-[#374151]">
                                                        {{ $av->formattedValue() }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="px-3.5 pb-3.5 pt-2 border-t border-[#F1F5F9] flex items-center justify-between gap-2">
                                    <div>
                                        <div class="font-mono-tabular text-[15px] font-extrabold text-[#F05A28]">
                                            Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                                        </div>
                                        <div class="text-[10px] text-[#6B7280] font-mono-tabular">
                                            Stok {{ $product->stock }} &middot; Terjual {{ $product->sold_count }}
                                        </div>
                                    </div>
                                    <span class="font-mono-tabular text-[11px] text-[#6B7280]" x-text="count + ' suka'"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($products->hasPages())
                        <div class="pt-2">{{ $products->links() }}</div>
                    @endif
                @endif
            </div>

        {{-- TAB 2: ULASAN PEMBELI (5-Star to 1-Star Histogram + Review Cards + Seller Reply) --}}
        @else
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                {{-- Left: Rating Histogram --}}
                <aside class="lg:col-span-4 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4">
                    <div class="text-center pb-4 border-b border-[#F1F5F9]">
                        <div class="font-mono-tabular text-4xl font-extrabold text-[#111827]">{{ number_format((float) $ratingSummary['average'], 2) }}</div>
                        <div class="flex items-center justify-center gap-1 mt-1.5">
                            @for($s = 1; $s <= 5; $s++)
                                <svg class="w-4 h-4 {{ $s <= round((float) $ratingSummary['average']) ? 'text-[#F59E0B] fill-[#F59E0B]' : 'text-[#E5E7EB]' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            @endfor
                        </div>
                        <div class="text-xs text-[#6B7280] mt-1 font-mono-tabular">Berdasarkan {{ $ratingSummary['total'] }} ulasan terverifikasi</div>
                    </div>

                    <div class="space-y-2 text-xs">
                        @foreach($ratingSummary['histogram'] as $star => $meta)
                            <div class="flex items-center gap-2.5">
                                <span class="w-9 font-mono-tabular font-bold text-[#374151] shrink-0">{{ $star }} Btg</span>
                                <div class="flex-1 h-2 rounded-full bg-[#F1F5F9] overflow-hidden">
                                    <div class="h-full rounded-full bg-[#F59E0B]" style="width: {{ $meta['percent'] }}%"></div>
                                </div>
                                <span class="w-8 text-right font-mono-tabular text-[#6B7280] shrink-0">{{ $meta['count'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </aside>

                {{-- Right: Review Feed + Inline Seller Reply --}}
                <div class="lg:col-span-8 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-sm font-bold text-[#111827]">Ulasan Pembeli Terverifikasi</h2>
                        <span class="font-mono-tabular text-xs text-[#6B7280]">{{ $reviews->total() }} ulasan</span>
                    </div>

                    @if($reviews->isEmpty())
                        <div class="p-10 text-center text-xs text-[#6B7280]">Belum ada ulasan untuk toko ini.</div>
                    @else
                        <div class="divide-y divide-[#F1F5F9]">
                            @foreach($reviews as $rev)
                                <div class="p-5 space-y-2.5 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-[#111827]">{{ $rev->buyer?->name ?? 'Pembeli' }}</span>
                                            <div class="flex items-center gap-0.5">
                                                @for($s = 1; $s <= 5; $s++)
                                                    <svg class="w-3.5 h-3.5 {{ $s <= (int) $rev->rating ? 'text-[#F59E0B] fill-[#F59E0B]' : 'text-[#E5E7EB]' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                @endfor
                                            </div>
                                        </div>
                                        <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $rev->created_at?->format('d M Y') }}</span>
                                    </div>

                                    @if($rev->product)
                                        <div class="text-[11px] text-[#6B7280]">
                                            Produk: <strong class="text-[#374151]">{{ $rev->product->title }}</strong>
                                        </div>
                                    @endif

                                    <p class="text-[#1F2937] leading-relaxed">{{ $rev->comment ?: 'Transaksi cepat dan aman.' }}</p>

                                    @if($rev->seller_reply)
                                        <div class="ml-4 pl-3.5 border-l-2 border-[#0068E1] bg-[#EFF6FF]/60 rounded-r-lg p-3 space-y-1">
                                            <div class="flex items-center justify-between">
                                                <span class="font-bold text-[#0068E1]">Balasan {{ $store->store_name }}</span>
                                                <span class="font-mono-tabular text-[10px] text-[#6B7280]">{{ $rev->replied_at?->format('d M Y, H:i') }}</span>
                                            </div>
                                            <p class="text-[#1F2937]">{{ $rev->seller_reply }}</p>
                                        </div>
                                    @endif

                                    @if($isOwnStore)
                                        <form method="POST" action="{{ route('seller.reviews.reply', $rev->id) }}" class="pt-1 flex gap-2">
                                            @csrf
                                            <input type="text" name="seller_reply" value="{{ $rev->seller_reply }}" required minlength="3" maxlength="1000"
                                                   placeholder="Tulis balasan resmi toko..."
                                                   class="flex-1 h-8 px-3 text-xs bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                            <button type="submit" class="btn-tactile px-3 h-8 rounded-lg bg-[#00AA5B] text-white text-xs font-bold cursor-pointer">
                                                {{ $rev->seller_reply ? 'Update' : 'Balas' }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if($reviews->hasPages())
                            <div class="p-4 border-t border-[#F1F5F9]">{{ $reviews->links() }}</div>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection
