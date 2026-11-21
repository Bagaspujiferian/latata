@extends('layouts.app')

@section('title', 'Promo, Voucher Subsidi & Flash Sale — Admin CMS')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar />

        <div class="flex-1 min-w-0 space-y-5 w-full" x-data="{ tab: 'vouchers' }">
            {{-- Header & Subsidy KPI --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Manajemen Promo &amp; Flash Sale</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Terbitkan Voucher Subsidi Platform (terpisah dari margin penjual) dan kampanye Flash Sale berkuota atomik.</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="px-3.5 py-2 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-right font-mono-tabular">
                        <div class="text-[10px] font-semibold uppercase text-[#1E40AF]">Total Subsidi Platform</div>
                        <div class="text-sm font-extrabold text-[#0068E1]">Rp {{ number_format($totalPlatformSubsidy, 0, ',', '.') }}</div>
                    </div>
                    <div class="flex items-center gap-1.5 text-xs">
                        <button type="button" @click="tab = 'vouchers'"
                                :class="tab === 'vouchers' ? 'bg-[#0068E1] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]'"
                                class="btn-tactile px-3.5 py-2 rounded-lg font-bold cursor-pointer">
                            Voucher Platform ({{ $platformVouchers->count() }})
                        </button>
                        <button type="button" @click="tab = 'flash'"
                                :class="tab === 'flash' ? 'bg-[#F05A28] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]'"
                                class="btn-tactile px-3.5 py-2 rounded-lg font-bold cursor-pointer">
                            Flash Sale ({{ $flashSales->count() }})
                        </button>
                    </div>
                </div>
            </div>

            {{-- TAB 1: PLATFORM VOUCHERS --}}
            <div x-show="tab === 'vouchers'" class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                <form method="POST" action="{{ route('admin.cms.vouchers.store') }}"
                      x-data="{ discountType: 'fixed' }"
                      class="xl:col-span-5 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs">
                    @csrf
                    <h2 class="text-sm font-bold text-[#111827] pb-2 border-b border-[#F1F5F9]">Terbitkan Voucher Subsidi Platform</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Kode Voucher *</label>
                            <input type="text" name="code" required placeholder="NEXAPROMO25"
                                   class="w-full h-9 px-3 font-mono-tabular uppercase bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Tipe *</label>
                            <select name="discount_type" x-model="discountType" class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                <option value="fixed">Fixed (Rp)</option>
                                <option value="percent">Persentase (%)</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Judul Kampanye *</label>
                        <input type="text" name="title" required placeholder="Subsidi Payday NexaTrade"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1" x-text="discountType === 'fixed' ? 'Nilai Potongan (Rp) *' : 'Diskon (%) *'"></label>
                            <input type="number" name="discount_value" value="20000" required min="1"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div x-show="discountType === 'percent'" x-cloak>
                            <label class="block font-semibold text-[#374151] mb-1">Maks Potongan (Rp)</label>
                            <input type="number" name="max_discount_amount" value="50000" min="1000"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Min Belanja (Rp)</label>
                            <input type="number" name="min_purchase_amount" value="50000" min="0"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Kuota Total *</label>
                            <input type="number" name="quota_total" value="100" required min="1"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Maks / Pembeli</label>
                            <input type="number" name="max_uses_per_user" value="1" min="1" max="20"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Scope Game (Opsional)</label>
                        <select name="game_id" class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                            <option value="">Seluruh Ekosistem Game</option>
                            @foreach($games as $g)
                                <option value="{{ $g->id }}">{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Mulai *</label>
                            <input type="datetime-local" name="starts_at" value="{{ now()->format('Y-m-d\TH:i') }}" required
                                   class="w-full h-9 px-2.5 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Berakhir *</label>
                            <input type="datetime-local" name="ends_at" value="{{ now()->addDays(14)->format('Y-m-d\TH:i') }}" required
                                   class="w-full h-9 px-2.5 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>
                    <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                        Terbitkan Voucher Platform
                    </button>
                </form>

                <div class="xl:col-span-7 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">
                        Voucher Subsidi Platform Aktif &amp; Riwayat
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @forelse($platformVouchers as $v)
                            @php $pct = $v->quota_total > 0 ? (int) round(($v->quota_used / $v->quota_total) * 100) : 0; @endphp
                            <div class="row-interactive p-4 space-y-2 text-xs">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-0.5 rounded bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-mono-tabular font-extrabold">{{ $v->code }}</span>
                                        <span class="font-bold text-[#111827]">{{ $v->title }}</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $v->is_active ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#F1F5F9] text-[#6B7280]' }}">
                                            {{ $v->is_active ? 'AKTIF' : 'NONAKTIF' }}
                                        </span>
                                    </div>
                                    <form method="POST" action="{{ route('admin.cms.vouchers.toggle', $v->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-tactile px-3 py-1 rounded-lg border border-[#E4E8EE] bg-white text-[11px] font-semibold cursor-pointer">
                                            {{ $v->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    </form>
                                </div>
                                <div class="font-mono-tabular text-[11px] text-[#4B5563]">
                                    {{ $v->discount_type === 'fixed' ? 'Rp '.number_format((float) $v->discount_value, 0, ',', '.') : (int) $v->discount_value.'% (Maks Rp '.number_format((float) ($v->max_discount_amount ?? 0), 0, ',', '.').')' }}
                                    &middot; Min Belanja Rp {{ number_format((float) $v->min_purchase_amount, 0, ',', '.') }}
                                    &middot; Terpakai {{ $v->quota_used }}/{{ $v->quota_total }} ({{ $pct }}%)
                                    &middot; Subsidi: <strong class="text-[#0068E1]">Rp {{ number_format((float) ($v->applied_subsidy_total ?? 0), 0, ',', '.') }}</strong>
                                </div>
                            </div>
                        @empty
                            <p class="p-8 text-center text-xs text-[#6B7280]">Belum ada voucher platform.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- TAB 2: FLASH SALES --}}
            <div x-show="tab === 'flash'" x-cloak class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                <form method="POST" action="{{ route('admin.cms.flash-sales.store') }}"
                      class="xl:col-span-4 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs">
                    @csrf
                    <h2 class="text-sm font-bold text-[#111827] pb-2 border-b border-[#F1F5F9]">Buat Event Flash Sale</h2>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Judul Event *</label>
                        <input type="text" name="title" required placeholder="Flash Sale Midnight Top-Up"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Subjudul Banner</label>
                        <input type="text" name="banner_subtitle" placeholder="Kuota terbatas, rebutan harga miring!"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Mulai *</label>
                            <input type="datetime-local" name="starts_at" value="{{ now()->format('Y-m-d\TH:i') }}" required
                                   class="w-full h-9 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Berakhir *</label>
                            <input type="datetime-local" name="ends_at" value="{{ now()->addHours(12)->format('Y-m-d\TH:i') }}" required
                                   class="w-full h-9 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>
                    <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#F05A28] hover:bg-[#D94A1B] text-white font-bold cursor-pointer">
                        Buat Event Flash Sale
                    </button>
                </form>

                <div class="xl:col-span-8 space-y-4">
                    @forelse($flashSales as $fs)
                        <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden text-xs">
                            <div class="px-4 py-3 bg-[#FFF4ED] border-b border-[#FED7AA] flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-extrabold text-[#111827]">{{ $fs->title }}</div>
                                    <div class="font-mono-tabular text-[11px] text-[#9A3412]">
                                        {{ $fs->starts_at?->format('d M H:i') }} &rarr; {{ $fs->ends_at?->format('d M Y H:i') }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $fs->isRunningNow() ? 'bg-[#F05A28] text-white' : 'bg-white text-[#6B7280] border border-[#E4E8EE]' }}">
                                    {{ $fs->isRunningNow() ? 'SEDANG BERLANGSUNG' : 'TERJADWAL / SELESAI' }}
                                </span>
                            </div>

                            <div class="p-4 space-y-3">
                                @if($fs->items->isNotEmpty())
                                    <div class="divide-y divide-[#F1F5F9] border border-[#E4E8EE] rounded-lg">
                                        @foreach($fs->items as $fi)
                                            <div class="p-2.5 flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <div class="font-bold text-[#111827]">{{ $fi->product?->title }}</div>
                                                    <div class="font-mono-tabular text-[10px] text-[#6B7280]">
                                                        Reguler Rp {{ number_format((float) ($fi->product?->price ?? 0), 0, ',', '.') }}
                                                        &rarr; <strong class="text-[#F05A28]">Flash Rp {{ number_format((float) $fi->flash_price, 0, ',', '.') }}</strong>
                                                    </div>
                                                </div>
                                                <div class="font-mono-tabular text-[11px] font-bold text-[#C2410C]">
                                                    Terjual {{ $fi->flash_sold }}/{{ $fi->flash_quota }} (Maks {{ $fi->max_qty_per_user }}/akun)
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Attach Product Form --}}
                                <form method="POST" action="{{ route('admin.cms.flash-sales.items.attach', $fs->id) }}" class="grid grid-cols-1 sm:grid-cols-12 gap-2 pt-1">
                                    @csrf
                                    <select name="product_id" required class="sm:col-span-5 h-8 px-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg text-[11px]">
                                        @foreach($products as $pr)
                                            <option value="{{ $pr->id }}">{{ $pr->sku_code }} — {{ $pr->title }} (Rp {{ number_format((float) $pr->price, 0, ',', '.') }})</option>
                                        @endforeach
                                    </select>
                                    <input type="number" name="flash_price" required min="1000" placeholder="Harga Flash"
                                           class="sm:col-span-3 h-8 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                    <input type="number" name="flash_quota" required min="1" value="10" placeholder="Kuota"
                                           class="sm:col-span-2 h-8 px-2 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                                    <button type="submit" class="sm:col-span-2 btn-tactile h-8 rounded-lg bg-[#0068E1] text-white text-[11px] font-bold cursor-pointer">
                                        + Pasang
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="bg-white border border-[#E4E8EE] rounded-xl p-10 text-center text-xs text-[#6B7280]">
                            Belum ada event Flash Sale.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
