@extends('layouts.app')

@section('title', 'Laporan Moderasi Trust & Safety — Admin')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Antrean Laporan Moderasi</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Tindak cepat produk melanggar, akun curian, atau indikasi penipuan dengan pencatatan otomatis ke Audit Log.</p>
                </div>

                <div class="flex items-center gap-1.5 text-xs">
                    @foreach([
                        'open' => 'Terbuka ('.$openCount.')',
                        'action_taken' => 'Ditindak',
                        'dismissed' => 'Diabaikan',
                        'all' => 'Semua',
                    ] as $stKey => $stLabel)
                        <a href="{{ route('admin.reports.index', ['status' => $stKey]) }}"
                           class="btn-tactile px-3 py-1.5 rounded-lg font-semibold {{ $status === $stKey ? 'bg-[#0068E1] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]' }}">
                            {{ $stLabel }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="space-y-4">
                @forelse($reports as $idx => $rep)
                    @php
                        $targetProduct = $rep->target_type === 'product' ? $productMap->get($rep->target_id) : null;
                    @endphp
                    <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: {{ min($idx + 1, 6) }}">
                        <div class="px-5 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono-tabular font-extrabold text-[#111827]">REPORT #{{ $rep->id }}</span>
                                <span class="px-2 py-0.5 rounded bg-white border border-[#E4E8EE] uppercase text-[10px] font-bold text-[#4B5563]">
                                    {{ $rep->target_type }} #{{ $rep->target_id }}
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $rep->status === 'action_taken' ? 'bg-[#FEF2F2] text-[#B91C1C]' : ($rep->status === 'dismissed' ? 'bg-[#F1F5F9] text-[#4B5563]' : 'bg-[#FFF4ED] text-[#C2410C]') }}">
                                    {{ $rep->status }}
                                </span>
                            </div>
                            <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $rep->created_at?->format('d M Y H:i') }}</span>
                        </div>

                        <div class="p-5 grid grid-cols-1 xl:grid-cols-12 gap-5 text-xs">
                            <div class="xl:col-span-7 space-y-2.5">
                                <div class="font-bold text-[#111827]">
                                    Kategori: {{ \App\Models\ModerationReport::REASON_LABELS[$rep->reason_category] ?? $rep->reason_category }}
                                </div>
                                @if($targetProduct)
                                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] flex items-center justify-between gap-2">
                                        <div>
                                            <div class="font-bold text-[#111827]">{{ $targetProduct->title }}</div>
                                            <div class="text-[11px] text-[#6B7280] font-mono-tabular">
                                                SKU {{ $targetProduct->sku_code }} &middot; Status Produk: <strong>{{ $targetProduct->is_active ? 'AKTIF' : 'NONAKTIF' }}</strong>
                                            </div>
                                        </div>
                                        <a href="{{ route('marketplace.show', $targetProduct->slug) }}" target="_blank" class="text-[#0068E1] font-semibold hover:underline shrink-0">Buka Produk</a>
                                    </div>
                                @endif
                                <p class="text-[#374151] leading-relaxed">{{ $rep->details }}</p>
                                <div class="text-[11px] text-[#6B7280]">Pelapor: <strong class="text-[#111827]">{{ $rep->reporter?->name }}</strong> ({{ $rep->reporter?->email }})</div>
                            </div>

                            <div class="xl:col-span-5">
                                @if($rep->status === 'open')
                                    <form method="POST" action="{{ route('admin.reports.resolve', $rep) }}" class="space-y-3">
                                        @csrf
                                        <div>
                                            <label class="block font-semibold text-[#374151] mb-1">Catatan Moderasi *</label>
                                            <textarea name="resolution_notes" rows="2" required minlength="5"
                                                      placeholder="Tulis alasan penindakan atau pengabaian laporan..."
                                                      class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit" name="action_type" value="dismiss"
                                                    class="btn-tactile flex-1 py-2 px-3 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#D1D5DB] text-[#374151] font-semibold cursor-pointer">
                                                Abaikan
                                            </button>
                                            @if($rep->target_type === 'product')
                                                <button type="submit" name="action_type" value="takedown_product"
                                                        class="btn-tactile flex-1 py-2 px-3 rounded-lg bg-[#F05A28] hover:bg-[#D94A1B] text-white font-bold cursor-pointer">
                                                    Nonaktifkan Produk
                                                </button>
                                            @endif
                                            <button type="submit" name="action_type" value="suspend_seller"
                                                    class="btn-tactile w-full py-2 px-3 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">
                                                Bekukan Toko (Suspend)
                                            </button>
                                        </div>
                                    </form>
                                @else
                                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-1">
                                        <div class="font-bold text-[#111827]">Diselesaikan oleh {{ $rep->reviewer?->name ?? 'Admin' }}</div>
                                        <p class="text-[#4B5563]">{{ $rep->resolution_notes }}</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center text-xs text-[#6B7280]">
                        Tidak ada laporan moderasi pada status ini.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
