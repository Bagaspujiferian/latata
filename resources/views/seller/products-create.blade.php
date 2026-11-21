@extends('layouts.app')

@section('title', 'Tambah Produk Baru — TokoKu Studio')

@section('content')
    @php
        $firstCategory = $categories->first();
        $initialCategoryId = (int) old('game_category_id', $firstCategory?->id ?? 0);
        $initialCategory = $categories->firstWhere('id', $initialCategoryId) ?? $firstCategory;
        $initialAttrs = $initialCategory ? $initialCategory->attributes->map(fn ($a): array => [
            'id' => $a->id,
            'name' => $a->name,
            'slug' => $a->slug,
            'input_type' => $a->input_type,
            'unit_label' => $a->unit_label,
            'is_required' => (bool) $a->is_required,
            'options' => $a->options->map(fn ($o): array => ['id' => $o->id, 'label' => $o->label, 'value_slug' => $o->value_slug])->values()->all(),
        ])->values()->all() : [];
    @endphp

    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-5 w-full"
             x-data="{
                 categoryId: {{ $initialCategoryId }},
                 deliveryMethod: @js($initialCategory?->delivery_method ?? 'manual_trade'),
                 attributes: @js($initialAttrs),
                 loadingAttrs: false,
                 previews: [],
                 primaryIndex: 0,
                 submitting: false,

                 async onCategoryChange() {
                     if (!this.categoryId) return;
                     this.loadingAttrs = true;
                     try {
                         const res = await fetch('/seller/categories/' + this.categoryId + '/attributes', {
                             headers: { 'Accept': 'application/json' },
                         });
                         const data = await res.json();
                         if (data.ok) {
                             this.deliveryMethod = data.delivery_method;
                             this.attributes = data.attributes || [];
                         }
                     } catch (e) {} finally {
                         this.loadingAttrs = false;
                     }
                 },

                 handleImages(event) {
                     const files = Array.from(event.target.files || []).slice(0, 6);
                     this.previews = [];
                     this.primaryIndex = 0;
                     files.forEach((file, idx) => {
                         const reader = new FileReader();
                         reader.onload = (e) => {
                             this.previews.push({ idx, name: file.name, url: e.target.result });
                         };
                         reader.readAsDataURL(file);
                     });
                 }
             }">

            {{-- Header Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Tambah Produk Digital Baru</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        Lengkapi detail produk, spesifikasi dinamis kategori, dan unggah hingga 6 foto galeri.
                    </p>
                </div>
                <a href="{{ route('seller.products') }}"
                   class="btn-tactile px-3.5 py-2 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#E4E8EE] text-xs font-semibold text-[#111827]">
                    Kembali ke Daftar Produk
                </a>
            </div>

            <form method="POST" action="{{ route('seller.products.store') }}" enctype="multipart/form-data"
                  @submit="submitting = true"
                  class="space-y-5 animate-enter" style="--stagger-index: 1">
                @csrf

                {{-- Card 1: Core Details --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4 text-xs">
                    <h2 class="text-sm font-bold text-[#111827] pb-2.5 border-b border-[#F1F5F9]">1. Informasi Dasar &amp; Metode Pengiriman</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Kategori Game &amp; Metode Kirim *</label>
                            <select name="game_category_id" x-model.number="categoryId" @change="onCategoryChange()" required
                                    class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}">
                                        {{ $cat->game->name }} — {{ $cat->name }} ({{ strtoupper($cat->delivery_method) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Judul Produk *</label>
                            <input type="text" name="title" value="{{ old('title') }}" required minlength="5" maxlength="200"
                                   placeholder="Contoh: Akun MLBB Mythical Immortal • 245 Skin All Unbind"
                                   class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Deskripsi Lengkap *</label>
                            <textarea name="description" rows="4" required minlength="10" maxlength="3000"
                                      placeholder="Jelaskan detail akun, skin, garansi, region, dan langkah serah terima..."
                                      class="w-full px-3 py-2.5 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">{{ old('description') }}</textarea>
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Harga Satuan (Rp) *</label>
                            <input type="number" name="price" value="{{ old('price', 150000) }}" required min="1000" max="50000000" step="500"
                                   class="w-full h-10 px-3 font-mono-tabular font-bold bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Target SLA Pengiriman (Menit) *</label>
                            <input type="number" name="sla_target_minutes" value="{{ old('sla_target_minutes', 10) }}" required min="1" max="1440"
                                   class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Stok Tersedia</label>
                            <template x-if="deliveryMethod === 'instant_vault'">
                                <div class="p-2.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-[11px] text-[#047857] font-medium">
                                    Stok otomatis disinkronkan dari Brankas AES-256 (dimulai dari 0 hingga kode/akun diunggah ke Brankas).
                                </div>
                            </template>
                            <template x-if="deliveryMethod !== 'instant_vault'">
                                <input type="number" name="stock" value="{{ old('stock', 10) }}" min="0" max="99999"
                                       class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            </template>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Min. Beli</label>
                                <input type="number" name="min_order" value="{{ old('min_order', 1) }}" min="1" max="50"
                                       class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            </div>
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Maks. Beli</label>
                                <input type="number" name="max_order" value="{{ old('max_order', 10) }}" min="1" max="100"
                                       class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            </div>
                        </div>

                        <div x-show="deliveryMethod === 'api_h2h'" x-cloak class="md:col-span-2">
                            <label class="block font-semibold text-[#0068E1] mb-1">SKU Provider Digiflazz H2H</label>
                            <input type="text" name="h2h_provider_sku" value="{{ old('h2h_provider_sku') }}" placeholder="Contoh: ML257"
                                   class="w-full h-10 px-3 font-mono-tabular bg-[#EFF6FF] border border-[#BFDBFE] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>
                </div>

                {{-- Card 2: Dynamic Category Attributes (fetched via Alpine JSON endpoint) --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4 text-xs">
                    <div class="flex items-center justify-between pb-2.5 border-b border-[#F1F5F9]">
                        <div>
                            <h2 class="text-sm font-bold text-[#111827]">2. Spesifikasi Dinamis Kategori</h2>
                            <p class="text-[11px] text-[#6B7280] mt-0.5">Nilai atribut ini terindeks langsung di filter katalog pembeli.</p>
                        </div>
                        <span x-show="loadingAttrs" x-cloak class="text-[11px] font-semibold text-[#0068E1]">Memuat atribut...</span>
                    </div>

                    <div x-show="loadingAttrs" x-cloak class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="h-12 rounded-lg skeleton-shimmer"></div>
                        <div class="h-12 rounded-lg skeleton-shimmer"></div>
                    </div>

                    <div x-show="!loadingAttrs && attributes.length === 0" x-cloak class="p-4 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] text-[#6B7280]">
                        Kategori ini tidak memiliki atribut spesifikasi khusus tambahan.
                    </div>

                    <div x-show="!loadingAttrs && attributes.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-for="attr in attributes" :key="attr.id">
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">
                                    <span x-text="attr.name"></span>
                                    <span x-show="attr.unit_label" class="text-[#6B7280] font-normal" x-text="'(' + attr.unit_label + ')'"></span>
                                    <span x-show="attr.is_required" class="text-[#DC2626]">*</span>
                                </label>

                                <template x-if="attr.input_type === 'select'">
                                    <select :name="'attributes[' + attr.id + ']'" :required="attr.is_required"
                                            class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                        <option value="">-- Pilih Opsi --</option>
                                        <template x-for="opt in attr.options" :key="opt.id">
                                            <option :value="opt.id" x-text="opt.label"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="attr.input_type === 'number'">
                                    <input type="number" :name="'attributes[' + attr.id + ']'" :required="attr.is_required" min="0"
                                           class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                </template>

                                <template x-if="attr.input_type === 'boolean'">
                                    <div>
                                        <input type="hidden" :name="'attributes[' + attr.id + ']'" value="0">
                                        <label class="inline-flex items-center gap-2 h-10 px-3 w-full bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg cursor-pointer">
                                            <input type="checkbox" :name="'attributes[' + attr.id + ']'" value="1" class="w-4 h-4 accent-[#00AA5B]">
                                            <span class="font-medium text-[#111827]">Ya / Tersedia</span>
                                        </label>
                                    </div>
                                </template>

                                <template x-if="attr.input_type === 'text'">
                                    <input type="text" :name="'attributes[' + attr.id + ']'" :required="attr.is_required" maxlength="150"
                                           class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Card 3: Multi-Image Uploader (up to 6 images + primary selector) --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4 text-xs">
                    <div class="pb-2.5 border-b border-[#F1F5F9]">
                        <h2 class="text-sm font-bold text-[#111827]">3. Galeri Multi-Foto Produk (Maks. 6 Gambar)</h2>
                        <p class="text-[11px] text-[#6B7280] mt-0.5">Unggah screenshot profil, koleksi skin, atau emblem akun (JPG/PNG/WEBP, maks 2MB per foto).</p>
                    </div>

                    <input type="hidden" name="primary_upload_index" :value="primaryIndex">
                    <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" @change="handleImages($event)"
                           class="w-full text-xs text-[#4B5563] file:mr-3 file:py-2 file:px-3.5 file:rounded-lg file:border file:border-[#D1D5DB] file:bg-white file:text-xs file:font-semibold file:text-[#111827] hover:file:bg-[#F8FAFC]">

                    <div x-show="previews.length > 0" x-cloak class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2">
                        <template x-for="item in previews" :key="item.idx">
                            <div :class="primaryIndex === item.idx ? 'border-[#0068E1] ring-2 ring-[#0068E1]/25' : 'border-[#E4E8EE]'"
                                 class="border rounded-xl overflow-hidden bg-[#F8FAFC]">
                                <div class="aspect-[16/10] bg-white overflow-hidden">
                                    <img :src="item.url" :alt="item.name" class="w-full h-full object-cover">
                                </div>
                                <div class="p-2 flex items-center justify-between gap-2 text-[11px]">
                                    <span class="truncate text-[#4B5563]" x-text="item.name"></span>
                                    <button type="button" @click="primaryIndex = item.idx"
                                            :class="primaryIndex === item.idx ? 'text-[#047857] font-bold' : 'text-[#0068E1] font-semibold hover:underline'"
                                            class="shrink-0 cursor-pointer"
                                            x-text="primaryIndex === item.idx ? 'Utama' : 'Jadikan Utama'"></button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="flex justify-end gap-2.5 pt-3 border-t border-[#F1F5F9]">
                        <a href="{{ route('seller.products') }}" class="btn-tactile px-4 py-2.5 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold">Batal</a>
                        <button type="submit" :disabled="submitting"
                                class="btn-tactile px-6 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer disabled:opacity-60">
                            <span x-text="submitting ? 'Menyimpan Produk...' : 'Terbitkan Produk'"></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
