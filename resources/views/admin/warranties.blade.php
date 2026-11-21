@extends('layouts.app')

@section('title', 'Meja Klaim Garansi Hackback — Admin Risiko')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header + Filter Tabs --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Meja Klaim Garansi Anti-Hackback</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Investigasi bukti privat pembeli dan eksekusi Clawback Otomatis: Deposit Jaminan &rarr; Saldo Aktif &rarr; Hutang Clawback + Auto-Suspend.</p>
                </div>

                <div class="flex items-center gap-1.5 text-xs">
                    @foreach([
                        'open' => 'Antrean Aktif ('.$openCount.')',
                        'approved_refund' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        'all' => 'Semua',
                    ] as $stKey => $stLabel)
                        <a href="{{ route('admin.warranties.index', ['status' => $stKey]) }}"
                           class="btn-tactile px-3 py-1.5 rounded-lg font-semibold {{ $statusFilter === $stKey ? 'bg-[#0068E1] text-white' : 'bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563] hover:text-[#111827]' }}">
                            {{ $stLabel }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Claim Cards --}}
            @forelse($claims as $idx => $claim)
                @php
                    $coverage = (float) ($claim->warranty?->coverage_amount ?? 0);
                    $sellerDeposit = (float) ($claim->seller?->wallet?->security_deposit_balance ?? 0);
                    $sellerAvail = (float) ($claim->seller?->wallet?->available_balance ?? 0);
                    $simFromDeposit = min($coverage, $sellerDeposit);
                    $remAfterDeposit = max(0, $coverage - $simFromDeposit);
                    $simFromAvail = min($remAfterDeposit, $sellerAvail);
                    $simDebt = max(0, $remAfterDeposit - $simFromAvail);
                    $willSuspend = $simDebt > 0;
                    $isOpen = in_array($claim->status, ['open', 'investigating'], true);
                @endphp

                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: {{ min($idx + 1, 6) }}">
                    <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-mono-tabular font-extrabold text-[#111827]">{{ $claim->claim_code }}</span>
                            <span class="px-2 py-0.5 rounded bg-white border border-[#E4E8EE] font-mono-tabular text-[#4B5563]">{{ $claim->order?->order_code }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $claim->status === 'approved_refund' ? 'bg-[#ECFDF5] text-[#047857]' : ($claim->status === 'rejected' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#FFF4ED] text-[#C2410C]') }}">
                                {{ $claim->status }}
                            </span>
                        </div>
                        <span class="font-mono-tabular text-xs font-extrabold text-[#DC2626]">
                            Pertanggungan: Rp {{ number_format($coverage, 0, ',', '.') }}
                        </span>
                    </div>

                    <div class="p-5 grid grid-cols-1 xl:grid-cols-12 gap-5 text-xs">
                        {{-- Left 7/12: Claim Story & Private Evidence Gallery --}}
                        <div class="xl:col-span-7 space-y-3.5">
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                    <div class="text-[10px] text-[#6B7280] uppercase font-semibold">Pembeli Pelapor</div>
                                    <div class="font-bold text-[#111827] mt-0.5">{{ $claim->buyer?->name }}</div>
                                    <div class="text-[11px] text-[#6B7280]">{{ $claim->buyer?->email }}</div>
                                </div>
                                <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                    <div class="text-[10px] text-[#6B7280] uppercase font-semibold">Penjual Terlapor</div>
                                    <div class="font-bold text-[#111827] mt-0.5 flex items-center gap-1.5">
                                        <span>{{ $claim->sellerProfile?->store_name ?? $claim->seller?->name }}</span>
                                        @if($claim->sellerProfile?->is_suspended)
                                            <span class="px-1.5 py-0.2 rounded bg-[#FEF2F2] text-[#B91C1C] text-[9px] font-bold uppercase">SUSPENDED</span>
                                        @endif
                                    </div>
                                    <div class="font-mono-tabular text-[11px] text-[#4B5563]">
                                        Deposit: Rp {{ number_format($sellerDeposit, 0, ',', '.') }} &middot; Aktif: Rp {{ number_format($sellerAvail, 0, ',', '.') }}
                                    </div>
                                </div>
                            </div>

                            <div class="p-3.5 rounded-xl bg-[#FFF4ED]/60 border border-[#FED7AA] space-y-1">
                                <div class="font-bold text-[#9A3412]">
                                    Alasan: {{ \App\Models\WarrantyClaim::REASON_LABELS[$claim->reason_category] ?? $claim->reason_category }}
                                </div>
                                <p class="text-[#374151] leading-relaxed">{{ $claim->description }}</p>
                            </div>

                            {{-- Private Local-Disk Evidence Thumbnails --}}
                            <div class="space-y-2">
                                <div class="text-[11px] font-bold text-[#374151]">Bukti Screenshot Privat ({{ count($claim->evidence_paths ?? []) }})</div>
                                @if(! empty($claim->evidence_paths))
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                                        @foreach($claim->evidence_paths as $evIdx => $evPath)
                                            <a href="{{ route('admin.warranties.evidence', [$claim, $evIdx]) }}" target="_blank"
                                               class="group block rounded-lg overflow-hidden border border-[#E4E8EE] hover:border-[#0068E1] bg-[#F8FAFC]">
                                                <img src="{{ route('admin.warranties.evidence', [$claim, $evIdx]) }}" alt="Bukti #{{ $evIdx + 1 }}"
                                                     class="w-full h-24 object-cover group-hover:scale-105 transition-transform">
                                                <div class="px-2 py-1 text-[10px] font-semibold text-[#0068E1] truncate">Bukti #{{ $evIdx + 1 }}</div>
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-[11px] text-[#6B7280] italic">Tidak ada lampiran gambar diunggah.</p>
                                @endif
                            </div>
                        </div>

                        {{-- Right 5/12: Live Clawback Waterfall Simulation & Verdict Form --}}
                        <div class="xl:col-span-5 space-y-3.5">
                            @if($isOpen)
                                <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2 font-mono-tabular">
                                    <div class="text-[11px] font-sans font-extrabold uppercase tracking-wide text-[#111827]">
                                        Simulasi Clawback (Non-Negative Invariant)
                                    </div>
                                    <div class="flex justify-between text-[#4B5563]">
                                        <span>1. Potong Deposit Jaminan</span>
                                        <strong class="text-[#047857]">Rp {{ number_format($simFromDeposit, 0, ',', '.') }}</strong>
                                    </div>
                                    <div class="flex justify-between text-[#4B5563]">
                                        <span>2. Potong Saldo Aktif</span>
                                        <strong class="text-[#C2410C]">Rp {{ number_format($simFromAvail, 0, ',', '.') }}</strong>
                                    </div>
                                    <div class="flex justify-between pt-2 border-t border-[#E4E8EE]">
                                        <span class="font-sans font-bold text-[#111827]">3. Catat Hutang + Auto-Suspend?</span>
                                        <strong class="{{ $willSuspend ? 'text-[#DC2626]' : 'text-[#047857]' }}">
                                            {{ $willSuspend ? 'YA (Rp '.number_format($simDebt, 0, ',', '.').')' : 'TIDAK (Rp 0)' }}
                                        </strong>
                                    </div>
                                    <div class="text-[10px] font-sans text-[#047857] pt-1">
                                        Pembeli tetap menerima 100% Rp {{ number_format($coverage, 0, ',', '.') }} ke Saldo Aktif.
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('admin.warranties.resolve', $claim) }}" class="space-y-3">
                                    @csrf
                                    <div>
                                        <label class="block font-semibold text-[#374151] mb-1">Catatan Investigasi &amp; Putusan *</label>
                                        <textarea name="admin_resolution_notes" rows="3" required minlength="5"
                                                  placeholder="Tuliskan hasil verifikasi bukti hackback dan dasar keputusan..."
                                                  class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none"></textarea>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button type="submit" name="decision" value="APPROVE_REFUND"
                                                class="btn-tactile py-2.5 px-3 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white font-bold cursor-pointer">
                                            Setujui &amp; Clawback
                                        </button>
                                        <button type="submit" name="decision" value="REJECT"
                                                class="btn-tactile py-2.5 px-3 rounded-lg bg-white hover:bg-[#FEF2F2] border border-[#FECACA] text-[#DC2626] font-bold cursor-pointer">
                                            Tolak Klaim
                                        </button>
                                    </div>
                                </form>
                            @else
                                <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                                    <div class="font-bold text-[#111827]">Putusan Final ({{ $claim->resolved_at?->format('d M Y H:i') }})</div>
                                    <p class="text-[#374151]">{{ $claim->admin_resolution_notes }}</p>
                                    @if($claim->status === 'approved_refund')
                                        <div class="pt-2 border-t border-[#E4E8EE] font-mono-tabular text-[11px] space-y-1 text-[#4B5563]">
                                            <div>Deposit Dipotong: <strong class="text-[#111827]">Rp {{ number_format((float) $claim->clawback_from_deposit, 0, ',', '.') }}</strong></div>
                                            <div>Saldo Aktif Dipotong: <strong class="text-[#111827]">Rp {{ number_format((float) $claim->clawback_from_available, 0, ',', '.') }}</strong></div>
                                            <div>Hutang Penjual: <strong class="text-[#DC2626]">Rp {{ number_format((float) $claim->recorded_seller_debt, 0, ',', '.') }}</strong></div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center text-xs text-[#6B7280]">
                    Tidak ada klaim garansi hackback pada filter ini.
                </div>
            @endforelse
        </div>
    </div>
@endsection
