@extends('layouts.app')

@section('title', 'Dompet & Tarik Saldo — TokoKu')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 animate-enter" style="--stagger-index: 0">
                <h1 class="text-xl font-extrabold text-[#111827]">Dompet &amp; Penarikan Saldo</h1>
                <p class="text-xs text-[#6B7280] mt-0.5">Penarikan menahan saldo di muka (`WITHDRAW_HOLD`) dengan penguncian baris InnoDB untuk mencegah double payout.</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                {{-- Left: Balances & Withdrawal Form --}}
                <div class="xl:col-span-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate">
                            <div class="text-[11px] font-semibold text-[#6B7280]">Saldo Aktif</div>
                            <div class="font-mono-tabular text-lg font-extrabold text-[#047857] mt-1">
                                Rp {{ number_format((float) ($user->wallet?->available_balance ?? 0), 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate">
                            <div class="text-[11px] font-semibold text-[#6B7280]">Pending Escrow</div>
                            <div class="font-mono-tabular text-lg font-extrabold text-[#C2410C] mt-1">
                                Rp {{ number_format((float) ($user->wallet?->pending_balance ?? 0), 0, ',', '.') }}
                            </div>
                        </div>
                    </div>

                    @php
                        $defaultBankId = $bankAccounts->firstWhere('is_primary', true)?->id ?? $bankAccounts->first()?->id;
                    @endphp
                    <form method="POST" action="{{ route('seller.wallet.withdraw') }}"
                          x-data="{ amount: 50000, adminFee: {{ (float) $adminFee }}, selectedBankId: '{{ $defaultBankId ?? '' }}', submittingWd: false }"
                          @submit="submittingWd = true"
                          class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-3.5 text-xs">
                        @csrf
                        <div class="flex items-center justify-between gap-2 pb-2 border-b border-[#F1F5F9]">
                            <h2 class="text-sm font-bold text-[#111827]">Ajukan Penarikan ke Rekening</h2>
                            <a href="{{ route('seller.bank-accounts') }}" class="text-[11px] font-bold text-[#0068E1] hover:underline">
                                + Kelola Rekening
                            </a>
                        </div>

                        @if($bankAccounts->isNotEmpty())
                            <div class="space-y-2">
                                <label class="block font-semibold text-[#374151]">Pilih Rekening Tujuan Tersimpan *</label>
                                <div class="space-y-2">
                                    @foreach($bankAccounts as $acc)
                                        <label :class="String(selectedBankId) === '{{ $acc->id }}' ? 'border-[#0068E1] bg-[#EFF6FF]/60 ring-1 ring-[#0068E1]/25' : 'border-[#E4E8EE] bg-white hover:bg-[#F8FAFC]'"
                                               class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all">
                                            <input type="radio" name="user_bank_account_id" value="{{ $acc->id }}"
                                                   x-model="selectedBankId" required class="mt-0.5 accent-[#0068E1]">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="inline-flex items-center gap-1.5 font-bold text-[#111827]">
                                                        <span class="px-1.5 py-0.5 rounded bg-[#0068E1] text-white text-[10px] font-mono-tabular">{{ $acc->bank_code }}</span>
                                                        <span class="truncate">{{ $acc->bank_name }}</span>
                                                    </span>
                                                    @if($acc->is_primary)
                                                        <span class="px-2 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[10px] font-bold shrink-0">Utama</span>
                                                    @endif
                                                </div>
                                                <div class="font-mono-tabular text-xs font-bold text-[#111827] mt-1">{{ $acc->account_number }}</div>
                                                <div class="text-[11px] text-[#6B7280] mt-0.5">a.n. {{ $acc->account_holder_name }}</div>
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="p-4 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] space-y-2 text-[#9A3412]">
                                <div class="font-bold text-xs">Belum Ada Rekening Bank Tersimpan</div>
                                <p class="text-[11px] leading-relaxed">Tambahkan minimal 1 rekening bank atau e-wallet terverifikasi terlebih dahulu agar pencairan dana lebih aman dan bebas salah ketik.</p>
                                <a href="{{ route('seller.bank-accounts') }}"
                                   class="btn-tactile inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-[11px] font-bold">
                                    <span>Tambah Rekening Sekarang</span>
                                </a>
                            </div>
                        @endif

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Nominal Penarikan (Min Rp 10.000) *</label>
                            <input type="number" name="amount" x-model.number="amount" required min="10000" step="1000"
                                   class="w-full h-10 px-3 font-mono-tabular text-sm font-bold bg-[#F8FAFC] border border-[#0068E1] rounded-lg focus:bg-white focus:outline-none">
                        </div>

                        <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] font-mono-tabular space-y-1">
                            <div class="flex justify-between text-[#6B7280]">
                                <span>Biaya Admin Kliring</span>
                                <span>- Rp {{ number_format($adminFee, 0, ',', '.') }}</span>
                            </div>
                            <div class="flex justify-between font-bold text-[#047857] text-sm pt-1.5 border-t border-[#E4E8EE]">
                                <span>Net Diterima</span>
                                <span x-text="window.formatRupiah(Math.max(0, amount - adminFee))"></span>
                            </div>
                        </div>

                        <button type="submit" :disabled="submittingWd || !selectedBankId"
                                class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-text="submittingWd ? 'Mengunci Saldo...' : 'Ajukan Tarik Saldo'"></span>
                        </button>
                    </form>
                </div>

                {{-- Right: Withdrawal History --}}
                <div class="xl:col-span-7 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE]">
                        <h2 class="text-sm font-bold text-[#111827]">Riwayat Penarikan ({{ $withdrawals->count() }})</h2>
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @forelse($withdrawals as $wd)
                            <div class="row-interactive p-4 flex flex-wrap items-center justify-between gap-3 text-xs">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono-tabular font-bold text-[#111827]">{{ $wd->withdraw_code }}</span>
                                        <span class="{{ $wd->status === 'PENDING' ? 'badge-live-pulse' : 'inline-flex' }} px-2 py-0.5 rounded-full text-[10px] font-bold {{ $wd->status === 'APPROVED_PAID' ? 'bg-[#ECFDF5] text-[#047857]' : ($wd->status === 'REJECTED_REFUNDED' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#FFF4ED] text-[#C2410C]') }}">
                                            {{ $wd->status }}
                                        </span>
                                    </div>
                                    <div class="text-[#6B7280] mt-1">{{ $wd->bank_name }} &middot; {{ $wd->account_number }} a.n. {{ $wd->account_holder }}</div>
                                    @if($wd->rejection_reason)
                                        <div class="text-[#DC2626] mt-1">Ditolak: {{ $wd->rejection_reason }}</div>
                                    @endif
                                </div>
                                <div class="text-right font-mono-tabular">
                                    <div class="font-extrabold text-sm text-[#111827]">Rp {{ number_format((float) $wd->amount, 0, ',', '.') }}</div>
                                    <div class="text-[11px] text-[#047857]">Net: Rp {{ number_format((float) $wd->net_payout, 0, ',', '.') }}</div>
                                </div>
                            </div>
                        @empty
                            <p class="p-6 text-xs text-[#6B7280]">Belum ada pengajuan penarikan.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Ledger Table --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                    <h2 class="text-sm font-bold text-[#111827]">Buku Besar Double-Entry (`wallet_mutations`)</h2>
                    <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $mutations->count() }} mutasi</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] font-semibold uppercase tracking-wider text-[#6B7280]">
                            <tr>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">Tipe</th>
                                <th class="px-4 py-3">Bucket</th>
                                <th class="px-4 py-3 text-right">Nominal</th>
                                <th class="px-4 py-3 text-right">Before &rarr; After</th>
                                <th class="px-4 py-3">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F1F5F9]">
                            @foreach($mutations as $m)
                                <tr class="row-interactive">
                                    <td class="px-4 py-3 font-mono-tabular font-semibold text-[#111827]">{{ $m->reference_code }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $m->direction === 'CREDIT' ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#FEF2F2] text-[#B91C1C]' }}">
                                            {{ $m->mutation_type }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 uppercase text-[10px] text-[#6B7280]">{{ $m->balance_bucket }}</td>
                                    <td class="px-4 py-3 text-right font-mono-tabular font-bold {{ $m->direction === 'CREDIT' ? 'text-[#047857]' : 'text-[#DC2626]' }}">
                                        {{ $m->direction === 'CREDIT' ? '+' : '-' }}Rp {{ number_format((float) $m->amount, 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono-tabular text-[11px] text-[#6B7280]">
                                        {{ number_format((float) $m->balance_before, 0, ',', '.') }} &rarr;
                                        <strong class="text-[#111827]">{{ number_format((float) $m->balance_after, 0, ',', '.') }}</strong>
                                    </td>
                                    <td class="px-4 py-3 text-[#4B5563]">{{ $m->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
