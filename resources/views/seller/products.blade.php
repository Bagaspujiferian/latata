@extends('layouts.app')

@section('title', 'Produk, Galeri & Brankas AES-256 — TokoKu')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start" x-data="{ showCreateModal: false }">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Produk, Galeri Multi-Foto &amp; Brankas AES-256</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        Kredit Sundul: <strong class="font-mono-tabular text-[#111827]">{{ $user->sellerProfile?->ad_credits ?? 0 }} kredit</strong>.
                        Kelola foto produk, atribut terindeks kategori, brankas AES-256, serta arsip soft-delete.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ route('seller.products.create') }}"
                       class="btn-tactile px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        <span>Tambah Produk Baru</span>
                    </a>
                </div>
            </div>

            {{-- Live HMAC-SHA256 Deduplication Alert Banner --}}
            @if(session('vault_ingest_report'))
                @php $report = session('vault_ingest_report'); @endphp
                <div class="bg-[#ECFDF5] border border-[#A7F3D0] rounded-xl p-4 space-y-2.5 animate-enter">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span class="font-bold text-[#065F46] inline-flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                            <span>Hasil Ingesti Brankas AES-256 &amp; Deduplikasi Blind-Index</span>
                        </span>
                        <div class="font-mono-tabular flex items-center gap-3 text-[11px]">
                            <span class="text-[#047857]">Masuk: <strong>{{ $report['inserted_count'] }}</strong></span>
                            <span class="text-[#DC2626]">Duplikat Diblokir: <strong>{{ $report['duplicate_count'] }}</strong></span>
                            <span class="text-[#111827]">Stok Tersedia: <strong>{{ $report['current_available_stock'] }}</strong></span>
                        </div>
                    </div>

                    @if(! empty($report['rejected_duplicates']))
                        <div class="space-y-1 font-mono-tabular text-[11px]">
                            @foreach($report['rejected_duplicates'] as $dup)
                                <div class="px-3 py-1.5 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C]">
                                    Diblokir: {{ $dup['masked_preview'] }} &middot; Hash {{ substr($dup['blind_hash'], 0, 20) }}... ({{ $dup['reason'] }})
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Active Products List --}}
            <div class="space-y-4">
                @foreach($products as $idx => $product)
                    @php
                        $isVault = $product->gameCategory->delivery_method === 'instant_vault';
                        $categoryAttrs = $product->gameCategory->attributes;
                        $attrValMap = $product->attributeValues->keyBy('category_attribute_id');
                    @endphp
                    <div style="--stagger-index: {{ min($idx, 8) }}"
                         class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter"
                         x-data="{ vaultOpen: false, editOpen: false, galleryOpen: false, attrsOpen: false, uploadingVault: false }">
                        <div class="p-4 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                            <div class="flex items-start gap-3.5 flex-1 min-w-0">
                                <img src="{{ $product->primaryThumbnailUrl() }}" alt="{{ $product->title }}"
                                     class="w-20 h-14 rounded-lg object-cover border border-[#E4E8EE] shrink-0 hidden sm:block">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                                        <span class="font-mono-tabular font-bold text-[#111827]">{{ $product->sku_code }}</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $isVault ? 'bg-[#ECFDF5] text-[#047857]' : ($product->gameCategory->delivery_method === 'api_h2h' ? 'bg-[#EFF6FF] text-[#0068E1]' : 'bg-[#F1F5F9] text-[#4B5563]') }}">
                                            {{ strtoupper($product->gameCategory->delivery_method) }}
                                        </span>
                                        @if($product->is_featured)
                                            <span class="px-2 py-0.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold font-mono-tabular">
                                                {{ $product->featured_until ? 'Featured hingga '.$product->featured_until->format('d M Y') : 'FEATURED' }}
                                            </span>
                                        @endif
                                        <span class="text-[#6B7280]">{{ $product->gameCategory->game->name }} &middot; {{ $product->gameCategory->name }}</span>
                                    </div>

                                    <h2 class="text-sm font-bold text-[#111827] leading-snug">{{ $product->title }}</h2>

                                    <div class="flex flex-wrap items-center gap-3 text-xs text-[#4B5563] pt-0.5">
                                        <span class="font-mono-tabular font-bold text-[#F05A28]">Rp {{ number_format((float) $product->price, 0, ',', '.') }}</span>
                                        <span>Stok: <strong class="font-mono-tabular {{ $product->stock > 0 ? 'text-[#047857]' : 'text-[#DC2626]' }}">{{ $product->stock }}</strong></span>
                                        <span>Terjual: <strong class="font-mono-tabular text-[#111827]">{{ $product->sold_count }}</strong></span>
                                        <span>SLA: <strong class="font-mono-tabular">{{ $product->sla_target_minutes }}m</strong></span>

                                        @if($isVault)
                                            <div class="inline-flex items-center gap-1.5 font-mono-tabular text-[10px]">
                                                <span class="px-1.5 py-0.5 rounded bg-[#ECFDF5] text-[#047857] font-semibold">Available: {{ $product->vault_available_count }}</span>
                                                <span class="px-1.5 py-0.5 rounded bg-[#FFF4ED] text-[#C2410C] font-semibold">Reserved: {{ $product->vault_reserved_count }}</span>
                                                <span class="px-1.5 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563] font-semibold">Sold: {{ $product->vault_sold_count }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                                @if($isVault)
                                    <button type="button" @click="vaultOpen = !vaultOpen; editOpen = false; galleryOpen = false; attrsOpen = false"
                                            class="btn-tactile px-2.5 py-1.5 rounded-lg bg-[#ECFDF5] hover:bg-[#D1FAE5] border border-[#A7F3D0] text-[#047857] text-xs font-semibold inline-flex items-center gap-1 cursor-pointer">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <span>Brankas</span>
                                    </button>
                                @endif

                                <button type="button" @click="galleryOpen = !galleryOpen; vaultOpen = false; editOpen = false; attrsOpen = false"
                                        class="btn-tactile px-2.5 py-1.5 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-xs font-semibold inline-flex items-center gap-1 cursor-pointer">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                                    <span>Galeri ({{ $product->images->count() }})</span>
                                </button>

                                @if($categoryAttrs->isNotEmpty())
                                    <button type="button" @click="attrsOpen = !attrsOpen; vaultOpen = false; editOpen = false; galleryOpen = false"
                                            class="btn-tactile px-2.5 py-1.5 rounded-lg bg-[#F8FAFC] hover:bg-[#F1F5F9] border border-[#D1D5DB] text-[#111827] text-xs font-semibold inline-flex items-center gap-1 cursor-pointer">
                                        <svg class="w-3.5 h-3.5 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/></svg>
                                        <span>Atribut ({{ $product->attributeValues->count() }})</span>
                                    </button>
                                @endif

                                <a href="{{ route('seller.products.edit', $product) }}"
                                   class="btn-tactile px-2.5 py-1.5 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#E4E8EE] text-[#111827] text-xs font-semibold">
                                    Edit
                                </a>

                                <form method="POST" action="{{ route('seller.products.toggle-active', $product) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="btn-tactile px-2.5 py-1.5 rounded-lg border text-xs font-semibold cursor-pointer {{ $product->is_active ? 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857]' : 'bg-[#F1F5F9] border-[#D1D5DB] text-[#4B5563]' }}">
                                        {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('seller.products.bump', $product->id) }}">
                                    @csrf
                                    <button type="submit" class="btn-tactile px-2.5 py-1.5 rounded-lg bg-[#FFF4ED] hover:bg-[#FFEDD5] border border-[#FED7AA] text-[#C2410C] text-xs font-semibold inline-flex items-center gap-1 cursor-pointer">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m18 15-6-6-6 6"/></svg>
                                        <span>Sundul (-1)</span>
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('seller.products.archive', $product->id) }}"
                                      onsubmit="return confirm('Arsipkan produk ini dari etalase? Riwayat pesanan lama tetap aman dan produk dapat dipulihkan kapan saja.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Arsipkan (Soft Delete)"
                                            class="btn-tactile px-2.5 py-1.5 rounded-lg bg-[#FEF2F2] hover:bg-[#FEE2E2] border border-[#FECACA] text-[#DC2626] text-xs font-semibold inline-flex items-center gap-1 cursor-pointer">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                                        <span>Arsipkan</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Inline Edit Panel --}}
                        <div x-show="editOpen" x-cloak class="px-4 py-4 bg-[#F8FAFC] border-t border-[#E4E8EE]">
                            <form method="POST" action="{{ route('seller.products.update', $product->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end text-xs">
                                @csrf
                                @method('PATCH')
                                <div class="md:col-span-4">
                                    <label class="block font-semibold text-[#374151] mb-1">Judul Produk</label>
                                    <input type="text" name="title" value="{{ $product->title }}" required class="w-full h-9 px-3 bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block font-semibold text-[#374151] mb-1">Harga (Rp)</label>
                                    <input type="number" name="price" value="{{ (int) $product->price }}" required min="1000" class="w-full h-9 px-3 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block font-semibold text-[#374151] mb-1">Stok {{ $isVault ? '(Auto Vault)' : '' }}</label>
                                    <input type="number" name="stock" value="{{ $product->stock }}" @disabled($isVault)
                                           class="w-full h-9 px-3 font-mono-tabular border rounded-lg {{ $isVault ? 'bg-[#F1F5F9] text-[#6B7280] border-[#E4E8EE] cursor-not-allowed' : 'bg-white border-[#D1D5DB]' }}">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block font-semibold text-[#374151] mb-1">SLA (Menit)</label>
                                    <input type="number" name="sla_target_minutes" value="{{ $product->sla_target_minutes }}" required min="1" class="w-full h-9 px-3 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg">
                                </div>
                                <div class="md:col-span-2">
                                    <button type="submit" class="btn-tactile w-full h-9 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-semibold cursor-pointer">Simpan</button>
                                </div>
                            </form>
                        </div>

                        {{-- Multi-Photo Gallery Manager Panel (product_images) --}}
                        <div x-show="galleryOpen" x-cloak class="p-5 bg-[#F8FAFC] border-t border-[#E4E8EE] space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-xs font-bold text-[#111827]">Galeri Multi-Foto Produk</h3>
                                    <p class="text-[11px] text-[#6B7280]">Unggah screenshot locker skin, rank, atau poster item. Foto bertanda Utama tampil sebagai thumbnail katalog.</p>
                                </div>
                                <span class="font-mono-tabular text-[11px] font-semibold text-[#6B7280]">{{ $product->images->count() }} Foto</span>
                            </div>

                            @if($product->images->isNotEmpty())
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                    @foreach($product->images as $img)
                                        <div class="bg-white border {{ $img->is_primary ? 'border-[#0068E1] ring-1 ring-[#0068E1]/30' : 'border-[#E4E8EE]' }} rounded-xl overflow-hidden flex flex-col">
                                            <div class="relative aspect-[16/10] bg-[#F1F5F9]">
                                                <img src="{{ $img->publicUrl() }}" alt="{{ $img->alt_text ?? $product->title }}" class="w-full h-full object-cover">
                                                @if($img->is_primary)
                                                    <span class="absolute top-1.5 left-1.5 px-1.5 py-0.5 rounded bg-[#0068E1] text-white text-[10px] font-bold">Utama</span>
                                                @endif
                                            </div>
                                            <div class="p-2 flex items-center justify-between gap-1 text-[11px]">
                                                @if(! $img->is_primary)
                                                    <form method="POST" action="{{ route('seller.products.images.primary', [$product->id, $img->id]) }}">
                                                        @csrf
                                                        <button type="submit" class="font-semibold text-[#0068E1] hover:underline cursor-pointer">Jadikan Utama</button>
                                                    </form>
                                                @else
                                                    <span class="text-[#047857] font-semibold">Thumbnail Aktif</span>
                                                @endif
                                                <form method="POST" action="{{ route('seller.products.images.delete', [$product->id, $img->id]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-[#DC2626] hover:underline font-semibold cursor-pointer">Hapus</button>
                                                </form>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <form method="POST" action="{{ route('seller.products.images.store', $product->id) }}" enctype="multipart/form-data"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end pt-3 border-t border-[#E4E8EE] text-xs">
                                @csrf
                                <div class="md:col-span-3">
                                    <label class="block font-semibold text-[#374151] mb-1">Unggah File Foto</label>
                                    <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.svg"
                                           class="w-full text-[11px] text-[#4B5563] file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border file:border-[#D1D5DB] file:bg-white file:text-[11px] file:font-semibold">
                                </div>
                                <div class="md:col-span-4">
                                    <label class="block font-semibold text-[#374151] mb-1">Atau URL/Path Gambar</label>
                                    <input type="text" name="image_url" placeholder="/images/products/val-kuronami-locker.svg"
                                           class="w-full h-9 px-3 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block font-semibold text-[#374151] mb-1">Keterangan Alt</label>
                                    <input type="text" name="alt_text" placeholder="Screenshot skin"
                                           class="w-full h-9 px-3 bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                </div>
                                <div class="md:col-span-1 flex items-center h-9">
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer text-[11px] font-semibold text-[#374151]">
                                        <input type="checkbox" name="is_primary" value="1" class="accent-[#0068E1]">
                                        <span>Utama</span>
                                    </label>
                                </div>
                                <div class="md:col-span-2">
                                    <button type="submit" class="btn-tactile w-full h-9 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">Tambah Foto</button>
                                </div>
                            </form>
                        </div>

                        {{-- Dynamic Category Attributes Editor Panel (category_attributes + product_attribute_values) --}}
                        @if($categoryAttrs->isNotEmpty())
                            <div x-show="attrsOpen" x-cloak class="p-5 bg-[#F8FAFC] border-t border-[#E4E8EE] space-y-3">
                                <div>
                                    <h3 class="text-xs font-bold text-[#111827]">Spesifikasi Terindeks Kategori ({{ $product->gameCategory->name }})</h3>
                                    <p class="text-[11px] text-[#6B7280]">Nilai atribut disimpan dalam kolom terindeks tipe data (select/number/boolean/text) agar muncul pada filter katalog pembeli.</p>
                                </div>

                                <form method="POST" action="{{ route('seller.products.attributes.sync', $product->id) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end text-xs">
                                    @csrf
                                    @foreach($categoryAttrs as $attr)
                                        @php $existingVal = $attrValMap->get($attr->id); @endphp
                                        <div>
                                            <label class="block font-semibold text-[#374151] mb-1">
                                                {{ $attr->name }}
                                                @if($attr->unit_label)
                                                    <span class="text-[#6B7280] font-normal">({{ $attr->unit_label }})</span>
                                                @endif
                                                @if($attr->is_required)
                                                    <span class="text-[#DC2626]">*</span>
                                                @endif
                                            </label>

                                            @if($attr->input_type === 'select')
                                                <select name="attributes[{{ $attr->id }}]" @required($attr->is_required)
                                                        class="w-full h-9 px-3 bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                                    <option value="">-- Pilih {{ $attr->name }} --</option>
                                                    @foreach($attr->options as $opt)
                                                        <option value="{{ $opt->id }}" @selected((int) $existingVal?->attribute_option_id === (int) $opt->id)>{{ $opt->label }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif($attr->input_type === 'number')
                                                <input type="number" name="attributes[{{ $attr->id }}]" value="{{ $existingVal?->numeric_value }}" @required($attr->is_required)
                                                       class="w-full h-9 px-3 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                            @elseif($attr->input_type === 'boolean')
                                                <input type="hidden" name="attributes[{{ $attr->id }}]" value="0">
                                                <label class="inline-flex items-center gap-2 h-9 px-3 w-full bg-white border border-[#D1D5DB] rounded-lg cursor-pointer">
                                                    <input type="checkbox" name="attributes[{{ $attr->id }}]" value="1" @checked((bool) $existingVal?->boolean_value) class="accent-[#00AA5B]">
                                                    <span class="font-medium text-[#111827]">Ya / Tersedia</span>
                                                </label>
                                            @else
                                                <input type="text" name="attributes[{{ $attr->id }}]" value="{{ $existingVal?->text_value }}" maxlength="150" @required($attr->is_required)
                                                       class="w-full h-9 px-3 bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                            @endif
                                        </div>
                                    @endforeach

                                    <div class="sm:col-span-2 lg:col-span-4 flex justify-end pt-1">
                                        <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                                            Simpan Spesifikasi Produk
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endif

                        {{-- Light-Mode Bulk AES-256 Vault Manager Panel --}}
                        @if($isVault)
                            <div x-show="vaultOpen" x-cloak class="p-5 bg-[#F8FAFC] border-t border-[#E4E8EE]">
                                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                                    <div class="lg:col-span-7">
                                        <div class="text-xs font-bold text-[#111827]">Bulk Import Kode Brankas AES-256 (Paste Multiline / Upload .TXT &amp; .CSV)</div>
                                        <p class="text-[11px] text-[#6B7280] mt-0.5">
                                            Masukkan 1 kode voucher atau `email:password | recovery` per baris (maks. 500 baris per transaksi) atau unggah file `.txt`/`.csv` (maks. 512KB). Duplikat internal batch &amp; DB otomatis dilewati via HMAC-SHA256 Blind Index.
                                        </p>
                                        <form method="POST" action="{{ route('seller.products.vault', $product->id) }}" enctype="multipart/form-data" @submit="uploadingVault = true" class="mt-3 space-y-2.5">
                                            @csrf
                                            <textarea name="vault_codes_text" rows="4"
                                                      placeholder="STEAM-CODE-NEW-0001&#10;STEAM-CODE-NEW-0002&#10;STEAM-CODE-NEW-0003"
                                                      class="w-full px-3 py-2.5 font-mono-tabular text-xs bg-white text-[#111827] border border-[#D1D5DB] rounded-lg focus:border-[#00AA5B] focus:outline-none"></textarea>
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <input type="file" name="vault_codes_file" accept=".txt,.csv"
                                                       class="text-[11px] text-[#4B5563] file:mr-2.5 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-[#D1D5DB] file:bg-white file:text-[11px] file:font-semibold file:text-[#111827] hover:file:bg-[#F8FAFC]">
                                                <button type="submit" :disabled="uploadingVault"
                                                        class="btn-tactile px-4 py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-semibold inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-65">
                                                    <svg x-show="uploadingVault" x-cloak class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                                    <span x-text="uploadingVault ? 'Mengenkripsi AES-256...' : 'Enkripsi & Simpan ke Brankas'"></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="lg:col-span-5 grid grid-cols-3 gap-2.5 text-center">
                                        <div class="p-3 rounded-xl bg-white border border-[#A7F3D0]">
                                            <div class="text-[10px] font-semibold text-[#047857] uppercase">Available</div>
                                            <div class="font-mono-tabular text-xl font-extrabold text-[#047857] mt-1">{{ $product->vault_available_count }}</div>
                                        </div>
                                        <div class="p-3 rounded-xl bg-white border border-[#FED7AA]">
                                            <div class="text-[10px] font-semibold text-[#C2410C] uppercase">Reserved</div>
                                            <div class="font-mono-tabular text-xl font-extrabold text-[#C2410C] mt-1">{{ $product->vault_reserved_count }}</div>
                                        </div>
                                        <div class="p-3 rounded-xl bg-white border border-[#E4E8EE]">
                                            <div class="text-[10px] font-semibold text-[#6B7280] uppercase">Sold</div>
                                            <div class="font-mono-tabular text-xl font-extrabold text-[#111827] mt-1">{{ $product->vault_sold_count }}</div>
                                        </div>
                                        <div class="col-span-3 p-3 rounded-xl bg-white border border-[#E4E8EE] text-left text-[11px] text-[#4B5563]">
                                            <strong class="text-[#111827]">Sinkronisasi Stok Otomatis:</strong> `products.stock` terkunci = <span class="font-mono-tabular font-bold text-[#047857]">{{ $product->vault_available_count }}</span> (anti stock-drift).
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Archived (Soft-Deleted) Products Section --}}
            @if($archivedProducts->isNotEmpty())
                <section class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter">
                    <div class="px-5 py-3.5 border-b border-[#F1F5F9] flex items-center justify-between gap-2 bg-[#F8FAFC]">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-[#374151]">Produk Diarsipkan (Soft-Delete)</h2>
                        </div>
                        <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $archivedProducts->count() }} item</span>
                    </div>

                    <div class="divide-y divide-[#F1F5F9]">
                        @foreach($archivedProducts as $archived)
                            <div class="px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 text-xs">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono-tabular text-[11px] font-bold text-[#6B7280]">{{ $archived->sku_code }}</span>
                                        <span class="font-bold text-[#374151]">{{ $archived->title }}</span>
                                    </div>
                                    <div class="text-[11px] text-[#6B7280] mt-0.5">
                                        {{ $archived->gameCategory->game->name }} &middot; Diarsipkan {{ $archived->deleted_at?->format('d M Y, H:i') }}
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('seller.products.restore', $archived->id) }}">
                                    @csrf
                                    <button type="submit"
                                            class="btn-tactile px-3 py-1.5 rounded-lg bg-[#ECFDF5] hover:bg-[#D1FAE5] border border-[#A7F3D0] text-[#047857] text-xs font-bold inline-flex items-center gap-1 cursor-pointer">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                        <span>Pulihkan ke Etalase</span>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        {{-- Create Product Modal --}}
        <div x-show="showCreateModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-[2px] p-4">
            <div @click.outside="showCreateModal = false" class="bg-white border border-[#E4E8EE] shadow-2xl max-w-xl w-full p-6 rounded-xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
                    <h2 class="text-base font-bold text-[#111827]">Tambah Produk Digital Baru</h2>
                    <button type="button" @click="showCreateModal = false" class="p-1 text-[#6B7280] hover:text-[#111827] rounded-lg cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('seller.products.store') }}" class="mt-4 space-y-3 text-xs">
                    @csrf
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Kategori Game &amp; Metode Kirim *</label>
                        <select name="game_category_id" required class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->game->name }} — {{ $cat->name }} ({{ strtoupper($cat->delivery_method) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Judul Produk *</label>
                        <input type="text" name="title" required placeholder="Steam Wallet IDR 250.000 — Auto AES-256"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Deskripsi *</label>
                        <textarea name="description" rows="3" required placeholder="Jelaskan garansi, region, dan ketentuan pengiriman..."
                                  class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Harga (Rp) *</label>
                            <input type="number" name="price" required min="1000" step="500" value="50000" class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Stok (Non-Vault)</label>
                            <input type="number" name="stock" min="0" value="25" class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Target SLA (m) *</label>
                            <input type="number" name="sla_target_minutes" required min="1" value="10" class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">SKU Provider Digiflazz H2H (khusus api_h2h)</label>
                        <input type="text" name="h2h_provider_sku" placeholder="Contoh: ML257" class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-[#F1F5F9]">
                        <button type="button" @click="showCreateModal = false" class="btn-tactile px-4 py-2 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold cursor-pointer">Batal</button>
                        <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">Simpan Produk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
