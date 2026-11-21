@extends('layouts.app')

@section('title', 'Edit Produk — ' . $product->title)

@section('content')
    @php
        $isVault = $product->gameCategory->delivery_method === 'instant_vault';
        $attrValMap = $product->attributeValues->keyBy('category_attribute_id');
        $currentPrimaryId = $product->images->firstWhere('is_primary', true)?->id ?? $product->images->first()?->id;
    @endphp

    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <div class="flex items-center gap-2 text-[11px] font-mono-tabular text-[#6B7280]">
                        <span class="font-bold text-[#111827]">{{ $product->sku_code }}</span>
                        <span>&middot;</span>
                        <span>{{ $product->gameCategory->game->name }} — {{ $product->gameCategory->name }}</span>
                    </div>
                    <h1 class="text-xl font-extrabold text-[#111827] mt-0.5">Edit Produk &amp; Galeri Foto</h1>
                </div>

                <a href="{{ route('seller.products') }}"
                   class="btn-tactile px-3.5 py-2 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#E4E8EE] text-xs font-semibold text-[#111827]">
                    Kembali ke Produk
                </a>
            </div>

            <form method="POST" action="{{ route('seller.products.put', $product) }}" enctype="multipart/form-data"
                  class="space-y-5 animate-enter" style="--stagger-index: 1">
                @csrf
                @method('PUT')

                {{-- Core Information --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4 text-xs">
                    <h2 class="text-sm font-bold text-[#111827] pb-2.5 border-b border-[#F1F5F9]">1. Detail Produk &amp; Harga</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Judul Produk *</label>
                            <input type="text" name="title" value="{{ old('title', $product->title) }}" required minlength="5" maxlength="200"
                                   class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Deskripsi Produk *</label>
                            <textarea name="description" rows="4" required minlength="10" maxlength="3000"
                                      class="w-full px-3 py-2.5 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">{{ old('description', $product->description) }}</textarea>
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Harga (Rp) *</label>
                            <input type="number" name="price" value="{{ old('price', (int) $product->price) }}" required min="1000" step="500"
                                   class="w-full h-10 px-3 font-mono-tabular font-bold bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Target SLA (Menit) *</label>
                            <input type="number" name="sla_target_minutes" value="{{ old('sla_target_minutes', $product->sla_target_minutes) }}" required min="1"
                                   class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Stok {{ $isVault ? '(Auto Vault)' : '' }}</label>
                            @if($isVault)
                                <div class="p-2.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-[11px] text-[#047857] font-medium">
                                    Stok terkunci otomatis: <strong class="font-mono-tabular">{{ $product->stock }}</strong> (sinkronisasi Brankas AES-256).
                                </div>
                            @else
                                <input type="number" name="stock" value="{{ old('stock', $product->stock) }}" min="0"
                                       class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Min. Beli</label>
                                <input type="number" name="min_order" value="{{ old('min_order', $product->min_order) }}" min="1"
                                       class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            </div>
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Maks. Beli</label>
                                <input type="number" name="max_order" value="{{ old('max_order', $product->max_order) }}" min="1"
                                       class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Category Attributes --}}
                @if($product->gameCategory->attributes->isNotEmpty())
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4 text-xs">
                        <h2 class="text-sm font-bold text-[#111827] pb-2.5 border-b border-[#F1F5F9]">2. Spesifikasi Atribut Kategori</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($product->gameCategory->attributes as $attr)
                                @php $val = $attrValMap->get($attr->id); @endphp
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
                                                class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                            <option value="">-- Pilih Opsi --</option>
                                            @foreach($attr->options as $opt)
                                                <option value="{{ $opt->id }}" @selected((int) $val?->attribute_option_id === (int) $opt->id)>{{ $opt->label }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($attr->input_type === 'number')
                                        <input type="number" name="attributes[{{ $attr->id }}]" value="{{ $val?->numeric_value }}" @required($attr->is_required)
                                               class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                    @elseif($attr->input_type === 'boolean')
                                        <input type="hidden" name="attributes[{{ $attr->id }}]" value="0">
                                        <label class="inline-flex items-center gap-2 h-10 px-3 w-full bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg cursor-pointer">
                                            <input type="checkbox" name="attributes[{{ $attr->id }}]" value="1" @checked((bool) $val?->boolean_value) class="w-4 h-4 accent-[#00AA5B]">
                                            <span class="font-medium text-[#111827]">Ya / Tersedia</span>
                                        </label>
                                    @else
                                        <input type="text" name="attributes[{{ $attr->id }}]" value="{{ $val?->text_value }}" maxlength="150" @required($attr->is_required)
                                               class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Existing + Additional Gallery Images --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4 text-xs">
                    <div class="flex items-center justify-between pb-2.5 border-b border-[#F1F5F9]">
                        <div>
                            <h2 class="text-sm font-bold text-[#111827]">3. Kelola Galeri Foto (Maks. 6 Total)</h2>
                            <p class="text-[11px] text-[#6B7280] mt-0.5">Pilih foto utama, centang foto yang ingin dihapus, atau unggah foto tambahan.</p>
                        </div>
                        <span class="font-mono-tabular text-[11px] font-bold text-[#0068E1]">{{ $product->images->count() }} / 6 Foto</span>
                    </div>

                    @if($product->images->isNotEmpty())
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($product->images as $img)
                                <div class="border {{ $img->is_primary ? 'border-[#0068E1] ring-1 ring-[#0068E1]/25' : 'border-[#E4E8EE]' }} rounded-xl overflow-hidden bg-[#F8FAFC]">
                                    <div class="aspect-[16/10] bg-white overflow-hidden">
                                        <img src="{{ $img->publicUrl() }}" alt="{{ $img->alt_text ?? $product->title }}" class="w-full h-full object-cover">
                                    </div>
                                    <div class="p-2.5 space-y-1.5 text-[11px]">
                                        <label class="flex items-center gap-1.5 cursor-pointer font-semibold text-[#0068E1]">
                                            <input type="radio" name="primary_image_id" value="{{ $img->id }}" @checked((int) $currentPrimaryId === (int) $img->id) class="accent-[#0068E1]">
                                            <span>Jadikan Utama</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer font-semibold text-[#DC2626]">
                                            <input type="checkbox" name="delete_image_ids[]" value="{{ $img->id }}" class="accent-[#DC2626]">
                                            <span>Hapus Foto Ini</span>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($product->images->count() < 6)
                        <div class="pt-2">
                            <label class="block font-semibold text-[#374151] mb-1">Unggah Foto Tambahan (Sisa slot: {{ 6 - $product->images->count() }})</label>
                            <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp"
                                   class="w-full text-xs text-[#4B5563] file:mr-3 file:py-2 file:px-3.5 file:rounded-lg file:border file:border-[#D1D5DB] file:bg-white file:text-xs file:font-semibold">
                        </div>
                    @endif

                    <div class="flex justify-end gap-2.5 pt-3 border-t border-[#F1F5F9]">
                        <a href="{{ route('seller.products') }}" class="btn-tactile px-4 py-2.5 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold">Batal</a>
                        <button type="submit" class="btn-tactile px-6 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                            Simpan Perubahan Produk
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
