@extends('layouts.app')

@section('title', 'Garansi Anti-Hackback Saya — NexaTrade')

@section('content')
    <div class="max-w-5xl mx-auto space-y-5"
         x-data="{
            claimModalOpen: false,
            selectedOrderId: null,
            selectedOrderCode: '',
            selectedCoverage: 0,
            openClaimModal(orderId, orderCode, coverage) {
                this.selectedOrderId = orderId;
                this.selectedOrderCode = orderCode;
                this.selectedCoverage = coverage;
                this.claimModalOpen = true;
            }
         }">
        {{-- Header --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-[#00AA5B] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                </span>
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Sertifikat Garansi Anti-Hackback</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Perlindungan akun pasca-selesai dengan jaminan penggantian 100% dari Deposit Penjual &amp; Dana Garansi NexaTrade.</p>
                </div>
            </div>
            <a href="{{ route('buyer.orders.index') }}" class="btn-tactile px-3.5 py-2 rounded-lg border border-[#E4E8EE] hover:border-[#0068E1] bg-white text-xs font-semibold text-[#111827] hover:text-[#0068E1]">
                Lihat Semua Pesanan
            </a>
        </div>

        {{-- Warranty List --}}
        @if($warranties->isEmpty())
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter">
                <div class="w-12 h-12 rounded-full bg-[#ECFDF5] text-[#00AA5B] mx-auto flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                </div>
                <h2 class="text-sm font-bold text-[#111827]">Belum Ada Sertifikat Garansi Aktif</h2>
                <p class="text-xs text-[#6B7280] mt-1">Setiap pembelian kategori Akun Game bergaransi akan otomatis menerbitkan sertifikat perlindungan di sini saat pesanan selesai.</p>
                <a href="{{ route('marketplace.index') }}" class="btn-tactile inline-flex items-center mt-4 px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold">
                    Jelajahi Akun Bergaransi
                </a>
            </div>
        @else
            <div class="space-y-4">
                @foreach($warranties as $idx => $w)
                    @php
                        $ord = $w->order;
                        $firstItem = $ord?->items->first();
                        $badgeStyle = match ($w->status) {
                            'active' => 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]',
                            'claimed' => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
                            'refunded' => 'bg-[#EFF6FF] text-[#0068E1] border-[#BFDBFE]',
                            default => 'bg-[#F1F5F9] text-[#4B5563] border-[#E4E8EE]',
                        };
                    @endphp
                    <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: {{ min($idx + 1, 6) }}">
                        <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="font-mono-tabular font-extrabold text-[#111827]">{{ $ord?->order_code }}</span>
                                <span class="text-[#6B7280]">&middot;</span>
                                <span class="font-semibold text-[#374151]">{{ $w->sellerProfile?->store_name ?? 'Toko NexaTrade' }}</span>
                                <span class="px-2 py-0.5 rounded-full border text-[10px] font-bold uppercase {{ $badgeStyle }}">{{ $w->status }}</span>
                            </div>
                            <span class="font-mono-tabular text-xs font-bold text-[#047857]">
                                Proteksi {{ $w->warranty_days }} Hari &middot; Rp {{ number_format((float) $w->coverage_amount, 0, ',', '.') }}
                            </span>
                        </div>

                        <div class="p-5 space-y-4 text-xs">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="text-sm font-bold text-[#111827]">
                                        {{ $firstItem?->product_title_snapshot ?? 'Pesanan Akun Game' }}
                                    </div>
                                    <div class="text-[#6B7280] font-mono-tabular">
                                        Mulai: {{ $w->starts_at?->format('d M Y H:i') }} &middot; Berakhir: <strong class="text-[#111827]">{{ $w->expires_at?->format('d M Y H:i') }} WIB</strong>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 shrink-0">
                                    @if($ord)
                                        <a href="{{ route('buyer.orders.show', $ord->order_code) }}"
                                           class="btn-tactile px-3.5 py-2 rounded-lg border border-[#E4E8EE] hover:border-[#0068E1] text-[#374151] hover:text-[#0068E1] font-semibold">
                                            Detail Pesanan
                                        </a>
                                    @endif
                                    @if($w->isClaimable() && $ord)
                                        <button type="button"
                                                @click="openClaimModal({{ $ord->id }}, '{{ $ord->order_code }}', {{ (float) $w->coverage_amount }})"
                                                class="btn-tactile px-3.5 py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">
                                            Ajukan Klaim Hackback
                                        </button>
                                    @endif
                                </div>
                            </div>

                            @if($w->claims->isNotEmpty())
                                <div class="pt-3 border-t border-[#F1F5F9] space-y-2">
                                    @foreach($w->claims as $cl)
                                        <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                            <div class="space-y-0.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono-tabular font-bold text-[#111827]">{{ $cl->claim_code }}</span>
                                                    <span class="text-[#4B5563]">{{ $reasonLabels[$cl->reason_category] ?? $cl->reason_category }}</span>
                                                </div>
                                                <p class="text-[#6B7280]">{{ $cl->description }}</p>
                                                @if($cl->admin_resolution_notes)
                                                    <p class="text-[#047857] font-semibold">Putusan Admin: {{ $cl->admin_resolution_notes }}</p>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                @foreach($cl->evidence_paths ?? [] as $evIdx => $p)
                                                    <a href="{{ route('admin.warranties.evidence', [$cl, $evIdx]) }}" target="_blank"
                                                       class="px-2 py-1 rounded bg-white border border-[#BFDBFE] text-[#0068E1] text-[10px] font-semibold hover:underline">
                                                        Bukti #{{ $evIdx + 1 }}
                                                    </a>
                                                @endforeach
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $cl->status === 'approved_refund' ? 'bg-[#ECFDF5] text-[#047857]' : ($cl->status === 'rejected' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#FFF4ED] text-[#C2410C]') }}">
                                                    {{ $cl->status }}
                                                </span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Quick Claim Modal --}}
        <div x-show="claimModalOpen" x-cloak @keydown.escape.window="claimModalOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 backdrop-blur-[2px] p-4">
            <div @click.outside="claimModalOpen = false" class="bg-white border border-[#E4E8EE] shadow-2xl max-w-lg w-full rounded-xl overflow-hidden">
                <div class="px-5 py-3.5 bg-[#ECFDF5] border-b border-[#A7F3D0] flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-[#065F46]">Ajukan Klaim Garansi Hackback</h3>
                        <p class="text-[11px] text-[#047857]">
                            Pesanan <strong class="font-mono-tabular" x-text="selectedOrderCode"></strong> &middot;
                            Pertanggungan <strong class="font-mono-tabular" x-text="window.formatRupiah(selectedCoverage)"></strong>
                        </p>
                    </div>
                    <button type="button" @click="claimModalOpen = false" class="p-1 text-[#047857] hover:text-[#065F46] cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <form method="POST" :action="'/buyer/orders/' + selectedOrderId + '/warranty-claim'" enctype="multipart/form-data"
                      class="p-5 space-y-3.5 text-xs">
                    @csrf
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Alasan Klaim *</label>
                        <select name="reason_category" required class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            @foreach($reasonLabels as $rk => $rl)
                                <option value="{{ $rk }}">{{ $rl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Kronologi Kejadian *</label>
                        <textarea name="description" rows="4" required minlength="10"
                                  placeholder="Jelaskan secara rinci perubahan akses akun, notifikasi recovery email, atau status banned..."
                                  class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Bukti Screenshot Privat (Opsional, maks 4 file)</label>
                        <input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp"
                               class="w-full text-xs text-[#4B5563] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-[#BFDBFE] file:bg-[#EFF6FF] file:text-[#0068E1] file:font-semibold file:cursor-pointer">
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="claimModalOpen = false" class="btn-tactile px-4 py-2 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold cursor-pointer">Batal</button>
                        <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">Kirim Klaim Garansi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
