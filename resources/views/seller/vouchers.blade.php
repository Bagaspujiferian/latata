@extends('layouts.app')

@section('title', 'Voucher Toko — TokoKu Studio')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Voucher Promosi Toko</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Terbitkan kupon khusus toko Anda. Potongan Voucher Toko mengurangi dasar harga bersih (`gross - seller_discount`) sehingga komisi platform otomatis lebih hemat.</p>
                </div>
                <div class="px-3.5 py-2 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] text-right font-mono-tabular">
                    <div class="text-[10px] font-semibold uppercase text-[#9A3412]">Total Margin Disubsidi</div>
                    <div class="text-sm font-extrabold text-[#C2410C]">
                        Rp {{ number_format((float) $vouchers->sum('applied_discount_total'), 0, ',', '.') }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                {{-- Left (5/12): Create Seller Voucher Form --}}
                <form method="POST" action="{{ route('seller.vouchers.store') }}"
                      x-data="{ discountType: 'fixed' }"
                      class="xl:col-span-5 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs animate-enter" style="--stagger-index: 1">
                    @csrf
                    <div class="pb-2.5 border-b border-[#F1F5F9]">
                        <h2 class="text-sm font-bold text-[#111827]">Buat Voucher Toko Baru</h2>
                        <p class="text-[11px] text-[#6B7280] mt-0.5">Pembeli dapat menggabungkan Voucher Toko Anda dengan Voucher Subsidi Platform.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Kode Voucher *</label>
                            <input type="text" name="code" value="{{ old('code') }}" required placeholder="TOKOHEMAT10"
                                   class="w-full h-9 px-3 font-mono-tabular uppercase bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Tipe Potongan *</label>
                            <select name="discount_type" x-model="discountType"
                                    class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                <option value="fixed">Nominal Tetap (Rp)</option>
                                <option value="percent">Persentase (%)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Judul Promo *</label>
                        <input type="text" name="title" value="{{ old('title') }}" required placeholder="Potongan Spesial Akhir Pekan"
                               class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1" x-text="discountType === 'fixed' ? 'Nilai Potongan (Rp) *' : 'Persentase (%) *'"></label>
                            <input type="number" name="discount_value" value="{{ old('discount_value', 5000) }}" required min="1"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div x-show="discountType === 'percent'" x-cloak>
                            <label class="block font-semibold text-[#374151] mb-1">Maks Potongan (Rp)</label>
                            <input type="number" name="max_discount_amount" value="{{ old('max_discount_amount', 25000) }}" min="1000"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Min Belanja (Rp)</label>
                            <input type="number" name="min_purchase_amount" value="{{ old('min_purchase_amount', 20000) }}" min="0" step="1000"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Kuota Total *</label>
                            <input type="number" name="quota_total" value="{{ old('quota_total', 30) }}" required min="1"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Maks / Pembeli</label>
                            <input type="number" name="max_uses_per_user" value="{{ old('max_uses_per_user', 1) }}" min="1" max="20"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Batasi Game (Opsional)</label>
                        <select name="game_id" class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            <option value="">Semua Game di Toko Saya</option>
                            @foreach($games as $g)
                                <option value="{{ $g->id }}">{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Mulai Berlaku *</label>
                            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required
                                   class="w-full h-9 px-2.5 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Berakhir *</label>
                            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', now()->addDays(14)->format('Y-m-d\TH:i')) }}" required
                                   class="w-full h-9 px-2.5 font-mono-tabular text-[11px] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                        Terbitkan Voucher Toko
                    </button>
                </form>

                {{-- Right (7/12): Active & Past Seller Vouchers --}}
                <div class="xl:col-span-7 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 2">
                    <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-sm font-bold text-[#111827]">Daftar Voucher Toko ({{ $vouchers->count() }})</h2>
                        <span class="text-[11px] text-[#6B7280]">Kuota dikunci atomik InnoDB</span>
                    </div>

                    <div class="divide-y divide-[#F1F5F9]">
                        @forelse($vouchers as $v)
                            @php
                                $pct = $v->quota_total > 0 ? (int) round(($v->quota_used / $v->quota_total) * 100) : 0;
                            @endphp
                            <div class="row-interactive p-4 space-y-2.5 text-xs">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="px-2 py-0.5 rounded bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] font-mono-tabular font-extrabold">{{ $v->code }}</span>
                                            <span class="font-bold text-[#111827]">{{ $v->title }}</span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $v->is_active ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#F1F5F9] text-[#6B7280]' }}">
                                                {{ $v->is_active ? 'AKTIF' : 'NONAKTIF' }}
                                            </span>
                                        </div>
                                        <div class="text-[11px] text-[#6B7280] mt-1 font-mono-tabular">
                                            Potongan: <strong class="text-[#111827]">{{ $v->discount_type === 'fixed' ? 'Rp '.number_format((float) $v->discount_value, 0, ',', '.') : (int) $v->discount_value.'% (Maks Rp '.number_format((float) ($v->max_discount_amount ?? 0), 0, ',', '.').')' }}</strong>
                                            &middot; Min Belanja Rp {{ number_format((float) $v->min_purchase_amount, 0, ',', '.') }}
                                            @if($v->game) &middot; {{ $v->game->name }} @endif
                                        </div>
                                    </div>

                                    <form method="POST" action="{{ route('seller.vouchers.toggle', $v) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                class="btn-tactile px-3 py-1.5 rounded-lg border text-[11px] font-semibold cursor-pointer {{ $v->is_active ? 'border-[#FECACA] bg-[#FEF2F2] text-[#B91C1C]' : 'border-[#A7F3D0] bg-[#ECFDF5] text-[#047857]' }}">
                                            {{ $v->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    </form>
                                </div>

                                <div class="space-y-1">
                                    <div class="h-1.5 w-full rounded-full bg-[#F1F5F9] overflow-hidden">
                                        <div class="h-full rounded-full bg-[#0068E1]" style="width: {{ min(100, max(4, $pct)) }}%"></div>
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between text-[10px] text-[#6B7280] font-mono-tabular">
                                        <span>Terpakai {{ $v->quota_used }} / {{ $v->quota_total }} kuota ({{ $pct }}%)</span>
                                        <span>Subsidi Terpakai: <strong class="text-[#111827]">Rp {{ number_format((float) ($v->applied_discount_total ?? 0), 0, ',', '.') }}</strong></span>
                                        <span>s.d. {{ $v->ends_at?->format('d M Y H:i') }}</span>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="p-10 text-center text-xs text-[#6B7280]">
                                Belum ada voucher toko. Buat voucher pertama Anda di sebelah kiri untuk mendongkrak konversi!
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
