@extends('layouts.app')

@section('title', 'NexaTrade — Marketplace Top Up, Voucher & Akun Game')

@section('content')
    <div x-data="{
            isFiltering: false,
            triggerCatalogSkeleton() {
                this.isFiltering = true;
                window.dispatchEvent(new CustomEvent('nexa-progress-start'));
            }
         }">

        {{-- ===================================================================== --}}
        {{-- COMPACT SLIM CORPORATE PROMO BANNER (max-h-[160px])                   --}}
        {{-- ===================================================================== --}}
        <section class="relative overflow-hidden rounded-xl bg-gradient-to-r from-[#0068E1] to-[#004C9E] promo-banner-grid text-white px-5 py-4 sm:px-7 sm:py-5 mb-5 max-h-[160px] flex items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
            <div class="min-w-0">
                <div class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider bg-white/15 border border-white/25 px-2.5 py-0.5 rounded-full mb-2">
                    <svg class="w-3.5 h-3.5 text-[#A7F3D0]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                    <span>Safe Trading Escrow Otomatis</span>
                </div>
                <h1 class="text-lg sm:text-2xl font-extrabold tracking-tight leading-tight">
                    Transaksi Dijamin Escrow &amp; Pengiriman 10 Menit
                </h1>
                <p class="text-xs sm:text-[13px] text-white/85 mt-1 truncate">
                    Brankas AES-256 instan 0 detik, top-up H2H terverifikasi nickname, dan garansi refund otomatis lewat SLA.
                </p>
            </div>

            <div class="hidden md:flex items-center gap-2.5 shrink-0">
                <div class="px-3.5 py-2 rounded-lg bg-white/12 border border-white/20 text-right">
                    <div class="text-[10px] text-white/75 uppercase font-semibold">Brankas Instan</div>
                    <div class="font-mono-tabular text-sm font-bold">AES-256 Auto</div>
                </div>
                <div class="px-3.5 py-2 rounded-lg bg-white/12 border border-white/20 text-right">
                    <div class="text-[10px] text-white/75 uppercase font-semibold">Jaminan SLA</div>
                    <div class="font-mono-tabular text-sm font-bold">10 Menit Kirim</div>
                </div>
            </div>
        </section>

        {{-- ===================================================================== --}}
        {{-- FLASH SALE STRIP (LIVE COUNTDOWN + QUOTA BAR)                         --}}
        {{-- ===================================================================== --}}
        @if(isset($activeFlashSale) && $activeFlashSale && $activeFlashSale->items->isNotEmpty())
            <section class="mb-5 bg-white border border-[#FED7AA] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 0">
                <div class="px-4 py-3 bg-[#FFF4ED] border-b border-[#FED7AA] flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="w-8 h-8 rounded-lg bg-[#F05A28] text-white flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="text-sm font-extrabold text-[#111827] truncate">{{ $activeFlashSale->title }}</h2>
                                <span class="badge-live-pulse px-2 py-0.5 rounded-full bg-[#F05A28] text-white text-[10px] font-bold uppercase tracking-wide shrink-0">Flash Sale</span>
                            </div>
                            @if($activeFlashSale->banner_subtitle)
                                <p class="text-[11px] text-[#9A3412] truncate">{{ $activeFlashSale->banner_subtitle }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0"
                         x-data="liveCountdown('{{ $activeFlashSale->ends_at->toIso8601String() }}')">
                        <span class="text-[11px] font-semibold text-[#9A3412]">Berakhir dalam</span>
                        <span class="font-mono-tabular px-2.5 py-1 rounded-lg bg-[#F05A28] text-white text-xs font-extrabold tracking-wide"
                              x-text="formatted">--:--:--</span>
                    </div>
                </div>

                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
                    @foreach($activeFlashSale->items as $fItem)
                        @php
                            $fProd = $fItem->product;
                            if (! $fProd || ! $fProd->is_active) {
                                continue;
                            }
                            $origPrice = (float) $fProd->price;
                            $flashPrice = (float) $fItem->flash_price;
                            $discPct = $origPrice > 0 ? (int) round((($origPrice - $flashPrice) / $origPrice) * 100) : 0;
                            $remaining = $fItem->remainingQuota();
                            $usedPct = $fItem->quotaProgressPercent();
                            $thumbUrl = $fProd->images->first()?->publicUrl() ?? $fProd->gameCategory?->game?->thumbnail_url;
                        @endphp
                        <a href="{{ route('marketplace.show', $fProd->slug) }}"
                           class="card-elevate group bg-white border border-[#E4E8EE] hover:border-[#F05A28] rounded-xl p-3 flex items-center gap-3.5 transition-colors">
                            <div class="relative w-24 h-20 rounded-lg overflow-hidden bg-[#F1F5F9] border border-[#E4E8EE] shrink-0">
                                <img src="{{ $thumbUrl }}" alt="{{ $fProd->title }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                @if($discPct > 0)
                                    <span class="absolute top-1 left-1 px-1.5 py-0.5 rounded bg-[#DC2626] text-white font-mono-tabular text-[10px] font-extrabold">-{{ $discPct }}%</span>
                                @endif
                            </div>

                            <div class="min-w-0 flex-1 space-y-1.5">
                                <div class="text-[10px] font-semibold text-[#6B7280] truncate">
                                    {{ $fProd->gameCategory?->game?->name }} &middot; {{ $fProd->sellerProfile?->store_name }}
                                </div>
                                <div class="text-xs font-bold text-[#111827] group-hover:text-[#0068E1] line-clamp-1">
                                    {{ $fProd->title }}
                                </div>

                                <div class="flex items-baseline gap-2 font-mono-tabular">
                                    <span class="text-sm font-extrabold text-[#F05A28]">
                                        Rp {{ number_format($flashPrice, 0, ',', '.') }}
                                    </span>
                                    <span class="text-[11px] text-[#6B7280] line-through">
                                        Rp {{ number_format($origPrice, 0, ',', '.') }}
                                    </span>
                                </div>

                                <div class="space-y-1">
                                    <div class="h-1.5 w-full rounded-full bg-[#F1F5F9] overflow-hidden">
                                        <div class="h-full rounded-full {{ $remaining > 0 ? 'bg-[#F05A28]' : 'bg-[#9CA3AF]' }}"
                                             style="width: {{ min(100, max(8, $usedPct)) }}%"></div>
                                    </div>
                                    <div class="flex items-center justify-between text-[10px] font-mono-tabular">
                                        <span class="{{ $remaining > 0 ? 'text-[#C2410C] font-bold' : 'text-[#6B7280]' }}">
                                            {{ $remaining > 0 ? "Tersisa {$remaining} dari {$fItem->flash_quota}" : 'Kuota Habis' }}
                                        </span>
                                        <span class="text-[#6B7280]">Maks {{ $fItem->max_qty_per_user }}/akun</span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ===================================================================== --}}
        {{-- COMPACT GAME SILO SELECTOR ROW                                        --}}
        {{-- ===================================================================== --}}
        <section class="mb-4 animate-enter" style="--stagger-index: 1">
            <div class="flex items-center gap-2.5 overflow-x-auto pb-1">
                <a href="{{ route('marketplace.index', array_filter(['delivery' => $selectedDelivery ?: null, 'sort' => $selectedSort])) }}"
                   @click="triggerCatalogSkeleton()"
                   class="btn-tactile shrink-0 flex items-center gap-2.5 px-3.5 py-2 rounded-xl bg-white border {{ $selectedGameSlug === '' ? 'border-[#0068E1] ring-2 ring-[#0068E1]/15 text-[#0068E1]' : 'border-[#E4E8EE] hover:border-[#CBD5E1] text-[#111827]' }}">
                    <span class="w-9 h-9 rounded-lg flex items-center justify-center {{ $selectedGameSlug === '' ? 'bg-[#EFF6FF] text-[#0068E1]' : 'bg-[#F1F5F9] text-[#4B5563]' }}">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
                    </span>
                    <div class="leading-tight">
                        <div class="text-xs font-bold">Semua Game</div>
                        <div class="text-[10px] text-[#6B7280]">{{ $games->count() }} Ekosistem</div>
                    </div>
                </a>

                @foreach($games as $game)
                    @php $activeGame = $selectedGameSlug === $game->slug; @endphp
                    <a href="{{ route('marketplace.index', array_filter(['game' => $activeGame ? null : $game->slug, 'delivery' => $selectedDelivery ?: null, 'sort' => $selectedSort])) }}"
                       @click="triggerCatalogSkeleton()"
                       class="btn-tactile shrink-0 flex items-center gap-2.5 pr-3.5 pl-2 py-2 rounded-xl bg-white border {{ $activeGame ? 'border-[#0068E1] ring-2 ring-[#0068E1]/15' : 'border-[#E4E8EE] hover:border-[#CBD5E1]' }}">
                        <img src="{{ $game->thumbnail_url }}" alt="{{ $game->name }}" class="w-14 h-9 rounded-lg object-cover border border-[#E4E8EE] shrink-0">
                        <div class="leading-tight">
                            <div class="text-xs font-bold {{ $activeGame ? 'text-[#0068E1]' : 'text-[#111827]' }} whitespace-nowrap">{{ $game->name }}</div>
                            <div class="text-[10px] text-[#6B7280]">{{ $game->publisher }}</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ===================================================================== --}}
        {{-- STICKY FILTER & CATEGORY STRIP                                        --}}
        {{-- ===================================================================== --}}
        <section class="bg-white border border-[#E4E8EE] rounded-xl p-3.5 mb-5 space-y-3 animate-enter" style="--stagger-index: 2">
            {{-- Category Tabs Row --}}
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 border-b border-[#F1F5F9]">
                <a href="{{ route('marketplace.index', array_filter(['game' => $selectedGameSlug ?: null, 'delivery' => $selectedDelivery ?: null, 'sort' => $selectedSort, 'q' => $search ?: null])) }}"
                   @click="triggerCatalogSkeleton()"
                   class="btn-tactile shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold {{ $selectedCategoryId === 0 ? 'bg-[#EFF6FF] text-[#0068E1] border border-[#BFDBFE]' : 'text-[#4B5563] hover:bg-[#F8FAFC] border border-transparent' }}">
                    Semua Kategori
                </a>
                @foreach($categoriesForSelectedGame as $cat)
                    @php $activeCat = $selectedCategoryId === $cat->id; @endphp
                    <a href="{{ route('marketplace.index', array_filter(['game' => $selectedGameSlug ?: null, 'category' => $cat->id, 'delivery' => $selectedDelivery ?: null, 'sort' => $selectedSort, 'q' => $search ?: null])) }}"
                       @click="triggerCatalogSkeleton()"
                       class="btn-tactile shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium {{ $activeCat ? 'bg-[#EFF6FF] text-[#0068E1] font-semibold border border-[#BFDBFE]' : 'text-[#4B5563] hover:bg-[#F8FAFC] border border-transparent' }}">
                        {{ $cat->name }}
                        @if($selectedGameSlug === '')
                            <span class="text-[10px] text-[#6B7280] ml-1">({{ Str::before($cat->game->name, ':') }})</span>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- Delivery Method Filter Pills + SLA Toggle + Sort --}}
            <form method="GET" action="{{ route('marketplace.index') }}" @submit="triggerCatalogSkeleton()"
                  class="flex flex-wrap items-center justify-between gap-3">
                @if($selectedGameSlug !== '')
                    <input type="hidden" name="game" value="{{ $selectedGameSlug }}">
                @endif
                @if($selectedCategoryId > 0)
                    <input type="hidden" name="category" value="{{ $selectedCategoryId }}">
                @endif
                @if($search !== '')
                    <input type="hidden" name="q" value="{{ $search }}">
                @endif

                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach([
                        '' => 'Semua Metode',
                        'instant_vault' => 'Brankas Instan',
                        'api_h2h' => 'Top Up H2H',
                        'manual_trade' => 'Manual Trade',
                    ] as $delKey => $delLabel)
                        @php $isDel = $selectedDelivery === $delKey; @endphp
                        <a href="{{ route('marketplace.index', array_filter(['game' => $selectedGameSlug ?: null, 'category' => $selectedCategoryId ?: null, 'delivery' => $delKey ?: null, 'sort' => $selectedSort, 'q' => $search ?: null, 'sla_10m' => $onlySla10m ? 1 : null])) }}"
                           @click="triggerCatalogSkeleton()"
                           class="btn-tactile px-2.5 py-1.5 rounded-lg text-xs font-medium border {{ $isDel ? 'bg-[#0068E1] text-white border-[#0068E1] font-semibold' : 'bg-[#F8FAFC] text-[#4B5563] border-[#E4E8EE] hover:border-[#CBD5E1]' }}">
                            {{ $delLabel }}
                        </a>
                    @endforeach

                    <label class="btn-tactile inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border cursor-pointer {{ $onlySla10m ? 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]' : 'bg-white text-[#4B5563] border-[#E4E8EE] hover:border-[#CBD5E1]' }}">
                        <input type="checkbox" name="sla_10m" value="1" @checked($onlySla10m) @change="triggerCatalogSkeleton(); $el.form.submit()" class="sr-only">
                        <svg class="w-3.5 h-3.5 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>10 Menit Kirim</span>
                    </label>
                </div>

                <div class="flex items-center gap-2.5 ml-auto">
                    <span class="text-xs text-[#6B7280] hidden sm:inline">
                        Menampilkan <strong class="text-[#111827] tabular-nums-clean">{{ $products->count() }}</strong> produk
                    </span>
                    <select name="sort" @change="triggerCatalogSkeleton(); $el.form.submit()"
                            class="h-8 px-2.5 text-xs font-medium bg-[#F8FAFC] text-[#111827] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        <option value="recommended" @selected($selectedSort === 'recommended')>Urutkan: Paling Sesuai</option>
                        <option value="price_asc" @selected($selectedSort === 'price_asc')>Harga Terendah</option>
                        <option value="price_desc" @selected($selectedSort === 'price_desc')>Harga Tertinggi</option>
                        <option value="bestseller" @selected($selectedSort === 'bestseller')>Paling Laris</option>
                        <option value="fastest" @selected($selectedSort === 'fastest')>Pengiriman Tercepat</option>
                    </select>

                    @if($selectedGameSlug !== '' || $selectedCategoryId > 0 || $selectedDelivery !== '' || $search !== '' || $onlySla10m || $minPrice !== '' || $maxPrice !== '' || ! empty($attrFilters) || ! empty($attrsById))
                        <a href="{{ route('marketplace.index') }}" @click="triggerCatalogSkeleton()"
                           class="btn-tactile text-xs font-semibold text-[#0068E1] hover:underline">
                            Reset Filter
                        </a>
                    @endif
                </div>

                {{-- Price Range & Dynamic Indexed Category Attribute Filters --}}
                <div class="w-full pt-3 mt-1 border-t border-[#F1F5F9] flex flex-wrap items-end gap-3">
                    <div class="flex items-end gap-1.5">
                        <div>
                            <label class="block text-[10px] font-semibold text-[#6B7280] mb-1">Harga Min (Rp)</label>
                            <input type="number" name="min_price" value="{{ $minPrice }}" min="0" step="5000" placeholder="Min..."
                                   class="w-28 h-8 px-2.5 font-mono-tabular text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <span class="text-[#9CA3AF] pb-1.5 text-xs">-</span>
                        <div>
                            <label class="block text-[10px] font-semibold text-[#6B7280] mb-1">Harga Maks (Rp)</label>
                            <input type="number" name="max_price" value="{{ $maxPrice }}" min="0" step="5000" placeholder="Maks..."
                                   class="w-28 h-8 px-2.5 font-mono-tabular text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    @if($filterableAttributes->isNotEmpty())
                        <div class="h-6 w-px bg-[#E4E8EE] hidden sm:block self-end mb-1"></div>
                        @foreach($filterableAttributes as $attr)
                            @php $curVal = $attrsById[$attr->id] ?? $attrFilters[$attr->slug] ?? ''; @endphp
                            <div class="min-w-[135px]">
                                @if($attr->input_type === 'select')
                                    <label class="block text-[10px] font-semibold text-[#6B7280] mb-1">{{ $attr->name }}</label>
                                    <select name="attrs[{{ $attr->id }}]" @change="triggerCatalogSkeleton(); $el.form.submit()"
                                            class="w-full h-8 px-2.5 text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                        <option value="">Semua {{ $attr->name }}</option>
                                        @foreach($attr->options as $opt)
                                            <option value="{{ $opt->id }}" @selected((string) $curVal === (string) $opt->id || (string) $curVal === $opt->value_slug)>{{ $opt->label }}</option>
                                        @endforeach
                                    </select>
                                @elseif($attr->input_type === 'number')
                                    <label class="block text-[10px] font-semibold text-[#6B7280] mb-1">Min. {{ $attr->name }} {{ $attr->unit_label ? '(' . $attr->unit_label . ')' : '' }}</label>
                                    <input type="number" name="attrs[{{ $attr->id }}]" value="{{ $curVal }}" placeholder="Min..."
                                           class="w-32 h-8 px-2.5 font-mono-tabular text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                @elseif($attr->input_type === 'boolean')
                                    <label class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border text-xs font-medium cursor-pointer {{ $curVal ? 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857]' : 'bg-[#F8FAFC] border-[#E4E8EE] text-[#4B5563]' }}">
                                        <input type="checkbox" name="attrs[{{ $attr->id }}]" value="1" @checked((bool) $curVal) @change="triggerCatalogSkeleton(); $el.form.submit()" class="accent-[#00AA5B]">
                                        <span>{{ $attr->name }}</span>
                                    </label>
                                @else
                                    <label class="block text-[10px] font-semibold text-[#6B7280] mb-1">{{ $attr->name }}</label>
                                    <input type="text" name="attrs[{{ $attr->id }}]" value="{{ $curVal }}" placeholder="Cari..."
                                           class="w-36 h-8 px-2.5 text-xs bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                @endif
                            </div>
                        @endforeach
                    @endif

                    <button type="submit" class="btn-tactile h-8 px-3.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold cursor-pointer">
                        Terapkan
                    </button>
                </div>
            </form>
        </section>

        {{-- ===================================================================== --}}
        {{-- SHIMMER SKELETON GRID (SHOWN IMMEDIATELY ON FILTER / SORT)            --}}
        {{-- ===================================================================== --}}
        <div x-show="isFiltering" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">
            <x-skeleton.product-grid :count="10" />
        </div>

        {{-- ===================================================================== --}}
        {{-- HIGH-DENSITY TOKOPEDIA / ITEMKU PRODUCT GRID                          --}}
        {{-- ===================================================================== --}}
        <section x-show="!isFiltering" id="catalog-grid">
            @if($products->isEmpty())
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter">
                    <div class="w-12 h-12 rounded-full bg-[#F1F5F9] text-[#6B7280] mx-auto flex items-center justify-center mb-3">
                        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </div>
                    <h2 class="text-base font-bold text-[#111827]">Produk tidak ditemukan</h2>
                    <p class="text-xs text-[#6B7280] mt-1">Coba ubah filter game, metode pengiriman, atau kata kunci pencarian.</p>
                    <a href="{{ route('marketplace.index') }}" @click="triggerCatalogSkeleton()"
                       class="btn-tactile inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold">
                        Tampilkan Semua Produk
                    </a>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3.5">
                    @foreach($products as $idx => $product)
                        @php
                            $method = $product->gameCategory->delivery_method;
                            $isClosedManual = $method === 'manual_trade' && $product->sellerProfile && ! $product->sellerProfile->isCurrentlyOpen();
                            $cornerPill = match ($method) {
                                'instant_vault' => ['text' => 'Instan', 'class' => 'bg-[#00AA5B] text-white'],
                                'api_h2h' => ['text' => 'H2H Auto', 'class' => 'bg-[#0068E1] text-white'],
                                default => ['text' => 'Manual', 'class' => 'bg-[#111827]/80 text-white'],
                            };
                            $inWishlist = in_array((int) $product->id, $wishlistedProductIds, true);
                        @endphp
                        <div style="--stagger-index: {{ min($idx, 10) }}"
                             x-data="{
                                 saved: {{ $inWishlist ? 'true' : 'false' }},
                                 bouncing: false,
                                 async toggleHeart() {
                                     @auth
                                     this.bouncing = true;
                                     setTimeout(() => this.bouncing = false, 220);
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
                                         }
                                     } catch (e) {}
                                     @endauth
                                 }
                             }"
                             class="group bg-white border border-[#E4E8EE] rounded-xl overflow-hidden flex flex-col justify-between card-elevate animate-enter">
                            <div>
                                {{-- 16:10 Clean Thumbnail + Compact Heart Toggle --}}
                                <div class="relative aspect-[16/10] bg-[#F1F5F9] overflow-hidden border-b border-[#F1F5F9]">
                                    <a href="{{ route('marketplace.show', $product->slug) }}" class="block w-full h-full">
                                        <img src="{{ $product->primaryThumbnailUrl() }}" alt="{{ $product->title }}"
                                             class="w-full h-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.03]">
                                    </a>
                                    <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md text-[10px] font-bold tracking-wide pointer-events-none {{ $cornerPill['class'] }}">
                                        {{ $cornerPill['text'] }}
                                    </span>

                                    @auth
                                        <button type="button" @click.stop="toggleHeart()" title="Simpan ke Wishlist"
                                                :class="bouncing ? 'scale-125' : 'scale-100'"
                                                class="absolute top-2 right-2 w-7 h-7 rounded-full bg-white/95 border border-[#E4E8EE] shadow-xs flex items-center justify-center transition-transform duration-150 cursor-pointer hover:border-[#FECACA]">
                                            <svg class="w-3.5 h-3.5 transition-colors" :class="saved ? 'text-[#DC2626] fill-[#DC2626]' : 'text-[#6B7280]'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                                        </button>
                                    @endauth

                                    @if($product->images->count() > 1)
                                        <span class="absolute bottom-2 right-2 px-1.5 py-0.5 rounded bg-[#111827]/75 text-white font-mono-tabular text-[10px] font-semibold pointer-events-none">
                                            {{ $product->images->count() }} Foto
                                        </span>
                                    @endif
                                    @if($isClosedManual)
                                        <span class="absolute bottom-2 left-2 px-2 py-0.5 rounded-md text-[10px] font-bold bg-[#DC2626] text-white pointer-events-none">
                                            Tutup
                                        </span>
                                    @endif
                                </div>

                                {{-- Compact Card Body --}}
                                <div class="p-3">
                                    <a href="{{ route('marketplace.show', $product->slug) }}"
                                       class="block text-[13px] font-medium text-[#111827] leading-snug line-clamp-2 min-h-[36px] group-hover:text-[#0068E1] transition-colors">
                                        {{ $product->title }}
                                    </a>

                                    <div class="mt-1.5 text-[15px] font-extrabold text-[#F05A28] tabular-nums-clean">
                                        Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                                    </div>

                                    {{-- Compact Badge & Typed Attribute Pills --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-1">
                                        @if($product->sellerProfile?->is_sla_10m_badge)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-[#FFF4ED] text-[#C2410C] border border-[#FED7AA] text-[10px] font-semibold">
                                                <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                                <span>10 Menit Kirim</span>
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-md bg-[#F8FAFC] text-[#4B5563] border border-[#E4E8EE] text-[10px] font-medium">
                                                SLA {{ $product->sla_target_minutes }}m
                                            </span>
                                        @endif

                                        @foreach($product->attributeValues->take(2) as $av)
                                            @if($av->categoryAttribute && $av->categoryAttribute->input_type !== 'boolean')
                                                <span class="px-1.5 py-0.5 rounded-md bg-[#EFF6FF] text-[#0068E1] border border-[#BFDBFE] text-[10px] font-semibold truncate max-w-[110px]">
                                                    {{ $av->formattedValue() }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Store Link (/toko/{slug}) & Sales Footer --}}
                            <div class="px-3 pb-3 pt-2 border-t border-[#F1F5F9] space-y-1">
                                @if($product->sellerProfile)
                                    <a href="{{ route('stores.toko', $product->sellerProfile->slug) }}"
                                       class="flex items-center gap-1 text-[11px] text-[#4B5563] hover:text-[#0068E1] min-w-0 transition-colors">
                                        <svg class="w-3.5 h-3.5 text-[#0068E1] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/></svg>
                                        <span class="truncate font-medium">{{ $product->sellerProfile->store_name }}</span>
                                    </a>
                                @endif
                                <div class="flex items-center justify-between text-[11px] text-[#6B7280] tabular-nums-clean">
                                    <span class="inline-flex items-center gap-0.5 font-medium text-[#111827]">
                                        <svg class="w-3 h-3 text-[#F59E0B] fill-[#F59E0B]" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                        <span>{{ number_format((float) $product->rating_avg, 1) }}</span>
                                    </span>
                                    <span>Terjual {{ number_format($product->sold_count) }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
