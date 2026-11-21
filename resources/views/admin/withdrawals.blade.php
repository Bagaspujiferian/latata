@extends('layouts.app')

@section('title', 'Approval Penarikan Saldo — Admin NexaTrade')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar :pendingWithdrawals="$withdrawals->where('status', 'PENDING')->count()" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 animate-enter" style="--stagger-index: 0">
                <h1 class="text-xl font-extrabold text-[#111827]">Approval Penarikan Saldo Penjual</h1>
                <p class="text-xs text-[#6B7280] mt-0.5">Row lock InnoDB mencegah double payout. Menolak permintaan otomatis mengeksekusi `WITHDRAW_REJECT_REFUND` ke dompet penjual.</p>
            </div>

            <div class="space-y-3">
                @forelse($withdrawals as $idx => $wd)
                    <div style="--stagger-index: {{ min($idx, 8) }}"
                         class="bg-white border border-[#E4E8EE] rounded-xl p-5 card-elevate animate-enter {{ $wd->status === 'PENDING' ? 'border-l-4 border-l-[#F05A28]' : '' }}">
                        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                            <div class="space-y-1.5 text-xs">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono-tabular font-bold text-sm text-[#111827]">{{ $wd->withdraw_code }}</span>
                                    <span class="{{ $wd->status === 'PENDING' ? 'badge-live-pulse' : 'inline-flex' }} px-2 py-0.5 rounded-full text-[10px] font-bold {{ $wd->status === 'APPROVED_PAID' ? 'bg-[#ECFDF5] text-[#047857]' : ($wd->status === 'REJECTED_REFUNDED' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#FFF4ED] text-[#C2410C]') }}">
                                        {{ $wd->status }}
                                    </span>
                                    <span class="text-[#6B7280]">{{ $wd->created_at?->format('d M Y H:i') }} WIB</span>
                                </div>

                                <div class="text-sm font-bold text-[#111827]">
                                    {{ $wd->user?->sellerProfile?->store_name ?? $wd->user?->name }}
                                    <span class="text-xs font-normal text-[#6B7280]">({{ $wd->user?->email }})</span>
                                </div>

                                <div class="font-mono-tabular text-[#4B5563]">
                                    {{ $wd->bank_name }} &middot; <strong>{{ $wd->account_number }}</strong> a.n. <strong>{{ $wd->account_holder }}</strong>
                                </div>

                                <div class="flex flex-wrap gap-4 font-mono-tabular pt-1">
                                    <span>Hold: <strong class="text-[#111827]">Rp {{ number_format((float) $wd->amount, 0, ',', '.') }}</strong></span>
                                    <span>Fee: <strong class="text-[#6B7280]">Rp {{ number_format((float) $wd->admin_fee, 0, ',', '.') }}</strong></span>
                                    <span class="text-[#047857]">Net Transfer: <strong>Rp {{ number_format((float) $wd->net_payout, 0, ',', '.') }}</strong></span>
                                </div>

                                @if($wd->rejection_reason)
                                    <div class="text-[#DC2626]">Alasan Ditolak: {{ $wd->rejection_reason }}</div>
                                @endif
                            </div>

                            @if($wd->status === 'PENDING')
                                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 shrink-0">
                                    <form method="POST" action="{{ route('admin.withdrawals.approve', $wd->id) }}">
                                        @csrf
                                        <button type="submit" class="btn-tactile w-full sm:w-auto px-4 py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-bold cursor-pointer">
                                            Setujui &amp; Paid
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.withdrawals.reject', $wd->id) }}" class="flex items-center gap-2">
                                        @csrf
                                        <input type="text" name="rejection_reason" required value="Nama rekening tidak cocok dengan KYC toko"
                                               class="h-9 px-3 text-xs bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg w-52 focus:bg-white focus:border-[#DC2626] focus:outline-none">
                                        <button type="submit" class="btn-tactile px-3.5 h-9 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white text-xs font-bold cursor-pointer shrink-0">
                                            Tolak &amp; Refund
                                        </button>
                                    </form>
                                </div>
                            @else
                                <div class="text-right text-[11px] text-[#6B7280]">
                                    Diproses oleh <strong class="text-[#111827]">{{ $wd->processor?->name ?? 'System' }}</strong><br>
                                    {{ $wd->processed_at?->format('d M Y H:i') }}
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center text-xs text-[#6B7280]">Belum ada permintaan penarikan saldo.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
