@extends('layouts.app')

@section('title', 'Master Katalog CMS — Admin NexaTrade')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar />

        <div class="flex-1 min-w-0 space-y-5 w-full"
             x-data="{
                activeTab: 'games',
                schemaFields: [
                    { key: 'user_id', label: 'User ID', type: 'number', required: true, placeholder: 'Contoh: 12345678' }
                ],
                addSchemaField() {
                    this.schemaFields.push({ key: '', label: '', type: 'text', required: true, placeholder: '' });
                },
                removeSchemaField(index) {
                    this.schemaFields.splice(index, 1);
                },
                get schemaJson() {
                    return JSON.stringify(this.schemaFields.filter((f) => f.key && f.label));
                }
             }">
            {{-- Header + Tab Switcher --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Master Katalog CMS</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Kelola ekosistem Game, Kategori (Komisi, Durasi Escrow, Garansi Anti-Hackback, Skema Form Checkout), serta Atribut Filter Dinamis — seluruh perubahan tercatat ke Audit Log.</p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <button type="button" @click="activeTab = 'games'"
                            :class="activeTab === 'games' ? 'bg-[#0068E1] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]'"
                            class="btn-tactile px-3.5 py-2 rounded-lg font-bold cursor-pointer">
                        Master Game ({{ $games->count() }})
                    </button>
                    <button type="button" @click="activeTab = 'categories'"
                            :class="activeTab === 'categories' ? 'bg-[#0068E1] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]'"
                            class="btn-tactile px-3.5 py-2 rounded-lg font-bold cursor-pointer">
                        Kategori &amp; Skema ({{ $allCategories->count() }})
                    </button>
                    <button type="button" @click="activeTab = 'attributes'"
                            :class="activeTab === 'attributes' ? 'bg-[#0068E1] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]'"
                            class="btn-tactile px-3.5 py-2 rounded-lg font-bold cursor-pointer">
                        Atribut Filter
                    </button>
                </div>
            </div>

            {{-- TAB 1: MASTER GAMES --}}
            <div x-show="activeTab === 'games'" class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                <form method="POST" action="{{ route('admin.cms.games.store') }}"
                      class="xl:col-span-4 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs">
                    @csrf
                    <h2 class="text-sm font-bold text-[#111827] pb-2 border-b border-[#F1F5F9]">Tambah Game Baru</h2>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Nama Game *</label>
                        <input type="text" name="name" required placeholder="Honkai: Star Rail"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Publisher *</label>
                        <input type="text" name="publisher" required placeholder="HoYoverse"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">URL Thumbnail (SVG/PNG)</label>
                        <input type="text" name="thumbnail_url" placeholder="/images/games/mlbb.svg"
                               class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <label class="flex items-center gap-2 p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] cursor-pointer">
                            <input type="checkbox" name="is_popular" value="1" class="accent-[#0068E1]">
                            <span class="font-semibold text-[#374151]">Populer</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] cursor-pointer">
                            <input type="checkbox" name="requires_nickname_check" value="1" class="accent-[#0068E1]">
                            <span class="font-semibold text-[#374151]">Cek Nickname</span>
                        </label>
                    </div>
                    <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                        Simpan Game
                    </button>
                </form>

                <div class="xl:col-span-8 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">
                        Daftar Ekosistem Game
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @foreach($games as $g)
                            <div class="row-interactive p-4 flex flex-wrap items-center justify-between gap-3 text-xs">
                                <div class="flex items-center gap-3 min-w-0">
                                    <img src="{{ $g->thumbnail_url }}" alt="{{ $g->name }}" class="w-14 h-9 rounded-lg object-cover border border-[#E4E8EE] shrink-0">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-[#111827] truncate">{{ $g->name }}</span>
                                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold {{ $g->is_active ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#F1F5F9] text-[#6B7280]' }}">
                                                {{ $g->is_active ? 'AKTIF' : 'NONAKTIF' }}
                                            </span>
                                        </div>
                                        <div class="text-[11px] text-[#6B7280] font-mono-tabular">
                                            {{ $g->publisher }} &middot; slug: {{ $g->slug }} &middot; {{ $g->categories_count }} kategori
                                        </div>
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('admin.cms.games.toggle', $g->id) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-tactile px-3 py-1.5 rounded-lg border border-[#E4E8EE] hover:border-[#0068E1] bg-white text-[#374151] hover:text-[#0068E1] font-semibold cursor-pointer">
                                        {{ $g->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- TAB 2: CATEGORIES + VISUAL CHECKOUT SCHEMA BUILDER --}}
            <div x-show="activeTab === 'categories'" x-cloak class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                <form method="POST" action="{{ route('admin.cms.categories.store') }}"
                      class="xl:col-span-5 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs">
                    @csrf
                    <input type="hidden" name="required_inputs_schema" :value="schemaJson">

                    <h2 class="text-sm font-bold text-[#111827] pb-2 border-b border-[#F1F5F9]">Tambah Kategori &amp; Aturan Escrow</h2>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Pilih Game *</label>
                        <select name="game_id" required class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            @foreach($games as $g)
                                <option value="{{ $g->id }}">{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Nama Kategori *</label>
                            <input type="text" name="name" required placeholder="Akun High-Rank"
                                   class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Metode Pengiriman *</label>
                            <select name="delivery_method" required class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                <option value="instant_vault">Instant Vault (AES-256)</option>
                                <option value="api_h2h">Top Up Otomatis H2H</option>
                                <option value="manual_trade">Manual Trade + SLA</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Fee Platform (%)</label>
                            <input type="number" name="platform_fee_percent" value="5" step="0.5" min="0" max="35" required
                                   class="w-full h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Escrow Hold (Jam)</label>
                            <input type="number" name="escrow_hold_hours" value="24" min="1" max="720" required
                                   class="w-full h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Garansi (Hari)</label>
                            <input type="number" name="warranty_days" value="0" min="0" max="365" required
                                   class="w-full h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    {{-- Visual Alpine.js Checkout Input Schema Builder --}}
                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2.5">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-[#111827]">Checkout Input Schema Builder</span>
                            <button type="button" @click="addSchemaField()"
                                    class="btn-tactile px-2 py-1 rounded bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] text-[10px] font-bold cursor-pointer">
                                + Tambah Field
                            </button>
                        </div>
                        <template x-for="(field, fIdx) in schemaFields" :key="fIdx">
                            <div class="p-2.5 rounded-lg bg-white border border-[#E4E8EE] space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="text" x-model="field.key" placeholder="key (user_id)"
                                           class="h-8 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                    <input type="text" x-model="field.label" placeholder="Label (User ID)"
                                           class="h-8 px-2 text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                </div>
                                <div class="flex items-center gap-2">
                                    <select x-model="field.type" class="h-8 px-2 text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                    </select>
                                    <input type="text" x-model="field.placeholder" placeholder="Placeholder..."
                                           class="flex-1 h-8 px-2 text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                    <label class="inline-flex items-center gap-1 text-[10px] font-semibold">
                                        <input type="checkbox" x-model="field.required" class="accent-[#0068E1]"> Wajib
                                    </label>
                                    <button type="button" @click="removeSchemaField(fIdx)" class="text-[#DC2626] font-bold px-1.5 cursor-pointer">Hapus</button>
                                </div>
                            </div>
                        </template>
                        <div class="text-[10px] font-mono-tabular text-[#6B7280] break-all bg-white p-2 rounded border border-[#E4E8EE]" x-text="schemaJson"></div>
                    </div>

                    <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                        Simpan Kategori Baru
                    </button>
                </form>

                <div class="xl:col-span-7 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">
                        Konfigurasi Kategori &amp; Kebijakan Garansi
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @foreach($allCategories as $cat)
                            <div class="p-4 space-y-2.5 text-xs">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span class="font-bold text-[#111827]">{{ $cat->game?->name }} &mdash; {{ $cat->name }}</span>
                                        <span class="ml-1.5 px-1.5 py-0.2 rounded bg-[#F1F5F9] text-[#4B5563] text-[10px] font-mono-tabular uppercase">{{ $cat->delivery_method }}</span>
                                    </div>
                                    <div class="flex items-center gap-1.5 font-mono-tabular text-[10px]">
                                        <span class="px-2 py-0.5 rounded bg-[#EFF6FF] text-[#0068E1] font-bold">Fee {{ number_format((float) $cat->platform_fee_percent, 1) }}%</span>
                                        <span class="px-2 py-0.5 rounded bg-[#FFF4ED] text-[#C2410C] font-bold">Escrow {{ $cat->escrow_hold_hours ?? 24 }}j</span>
                                        <span class="px-2 py-0.5 rounded {{ ($cat->warranty_days ?? 0) > 0 ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#F1F5F9] text-[#6B7280]' }} font-bold">
                                            Garansi {{ $cat->warranty_days ?? 0 }}h
                                        </span>
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('admin.cms.categories.update', $cat->id) }}" class="grid grid-cols-2 sm:grid-cols-5 gap-2 pt-1">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="name" value="{{ $cat->name }}">
                                    <input type="hidden" name="delivery_method" value="{{ $cat->delivery_method }}">
                                    <div>
                                        <label class="block text-[10px] text-[#6B7280]">Fee (%)</label>
                                        <input type="number" name="platform_fee_percent" value="{{ (float) $cat->platform_fee_percent }}" step="0.5" min="0" max="35"
                                               class="w-full h-8 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-[#6B7280]">Hold (Jam)</label>
                                        <input type="number" name="escrow_hold_hours" value="{{ (int) ($cat->escrow_hold_hours ?? 24) }}" min="1" max="720"
                                               class="w-full h-8 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-[#6B7280]">Garansi (Hari)</label>
                                        <input type="number" name="warranty_days" value="{{ (int) ($cat->warranty_days ?? 0) }}" min="0" max="365"
                                               class="w-full h-8 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded">
                                    </div>
                                    <div class="col-span-2 flex items-end">
                                        <button type="submit" class="btn-tactile w-full h-8 px-3 rounded bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-[11px] font-bold cursor-pointer">
                                            Simpan Kebijakan
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- TAB 3: CATEGORY ATTRIBUTES & OPTIONS --}}
            <div x-show="activeTab === 'attributes'" x-cloak class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                <form method="POST" action="{{ route('admin.cms.attributes.store') }}"
                      class="xl:col-span-4 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs">
                    @csrf
                    <h2 class="text-sm font-bold text-[#111827] pb-2 border-b border-[#F1F5F9]">Tambah Atribut Filter</h2>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Kategori Game *</label>
                        <select name="game_category_id" required class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            @foreach($allCategories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->game?->name }} — {{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Nama Atribut *</label>
                        <input type="text" name="name" required placeholder="Contoh: Rank Tertinggi"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Tipe Input *</label>
                            <select name="input_type" class="w-full h-9 px-2.5 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                <option value="select">Select Dropdown</option>
                                <option value="number">Number (Range)</option>
                                <option value="boolean">Boolean (Ya/Tidak)</option>
                                <option value="text">Text</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Unit (Opsional)</label>
                            <input type="text" name="unit_label" placeholder="Skin / AR / Hero"
                                   class="w-full h-9 px-2.5 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Opsi Awal (Pisahkan koma untuk Select)</label>
                        <input type="text" name="options_csv" placeholder="Radiant, Immortal, Ascendant, Diamond"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    </div>
                    <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                        Simpan Atribut
                    </button>
                </form>

                <div class="xl:col-span-8 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">
                        Atribut Filter Terpasang per Kategori
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @foreach($allCategories->filter(fn ($c) => $c->attributes->isNotEmpty()) as $cat)
                            <div class="p-4 space-y-3 text-xs">
                                <div class="font-bold text-[#0068E1]">{{ $cat->game?->name }} &mdash; {{ $cat->name }}</div>
                                @foreach($cat->attributes as $attr)
                                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="font-bold text-[#111827]">{{ $attr->name }}</span>
                                            <span class="font-mono-tabular text-[10px] px-2 py-0.5 rounded bg-white border border-[#E4E8EE] text-[#4B5563]">
                                                {{ strtoupper($attr->input_type) }} {{ $attr->unit_label ? '('.$attr->unit_label.')' : '' }}
                                            </span>
                                        </div>
                                        @if($attr->input_type === 'select')
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                @foreach($attr->options as $opt)
                                                    <form method="POST" action="{{ route('admin.cms.attributes.options.delete', $opt->id) }}" class="inline-flex">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" title="Hapus opsi"
                                                                class="px-2 py-0.5 rounded bg-white border border-[#E4E8EE] hover:border-[#FECACA] hover:text-[#DC2626] text-[11px] font-medium cursor-pointer">
                                                            {{ $opt->label }} &times;
                                                        </button>
                                                    </form>
                                                @endforeach
                                            </div>
                                            <form method="POST" action="{{ route('admin.cms.attributes.options.store', $attr->id) }}" class="flex items-center gap-2 pt-1">
                                                @csrf
                                                <input type="text" name="label" required placeholder="Tambah opsi baru..."
                                                       class="h-8 flex-1 px-2.5 text-[11px] bg-white border border-[#D1D5DB] rounded-lg">
                                                <button type="submit" class="btn-tactile h-8 px-3 rounded-lg bg-[#0068E1] text-white text-[11px] font-bold cursor-pointer">Tambah</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
