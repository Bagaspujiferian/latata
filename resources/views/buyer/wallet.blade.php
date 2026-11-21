@extends('layouts.app')

@section('title', 'Dompet & Buku Besar Mutasi — NexaTrade')

@section('content')
    <div x-data="{
            directionFilter: 'ALL',
            filtering: false,
            switchFilter(dir) {
                if (this.directionFilter === dir) return;
                this.filtering = true;
                this.directionFilter = dir;
                setTimeout(() => { this.filtering = false; }, 220);
            }
         }">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-extrabold text-[#111827]">Dompet &amp; Buku Besar Mutasi</h1>
                <p class="text-xs text-[#6B7280] mt-0.5">Rekam jejak transaksi double-entry permanen dengan bukti `before &rarr; after` di setiap rupiah.</p>
            </div>

            <form method="POST" action="{{ route('demo.wallet-topup') }}">
                @csrf
                <input type="hidden" name="amount" value="500000">
                <button type="submit" class="btn-tactile inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-semibold cursor-pointer">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    <span>Top-Up Demo Rp 500.000</span>
                </button>
            </form>
        </div>

        {{-- Dual-Summary Balance Banner --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 card-elevate flex items-start justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <div class="text-xs font-semibold text-[#6B7280]">Saldo Aktif (Siap Belanja &amp; Refund)</div>
                    <div class="font-mono-tabular text-2xl sm:text-3xl font-extrabold text-[#047857] mt-1.5">
                        Rp {{ number_format((float) ($user->wallet?->available_balance ?? 0), 0, ',', '.') }}
                    </div>
                    <p class="text-[11px] text-[#6B7280] mt-1.5">Menerima refund SLA, H2H gagal, Hybrid Expiry Rollback &amp; Zombie Payment Recovery.</p>
                </div>
                <span class="w-10 h-10 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-[#00AA5B] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                </span>
            </div>

            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 card-elevate flex items-start justify-between gap-4 animate-enter" style="--stagger-index: 1">
                <div>
                    <div class="text-xs font-semibold text-[#6B7280]">Saldo Escrow Tertahan</div>
                    <div class="font-mono-tabular text-2xl sm:text-3xl font-extrabold text-[#111827] mt-1.5">
                        Rp {{ number_format((float) ($user->wallet?->pending_balance ?? 0), 0, ',', '.') }}
                    </div>
                    <p class="text-[11px] text-[#6B7280] mt-1.5">Pembaruan terakhir: {{ $user->wallet?->updated_at?->format('d M Y H:i:s') ?? '-' }}</p>
                </div>
                <span class="w-10 h-10 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] text-[#F05A28] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
            </div>
        </div>

        {{-- Filter Strip --}}
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-1.5 text-xs">
                <button type="button" @click="switchFilter('ALL')"
                        :class="directionFilter === 'ALL' ? 'bg-[#0068E1] text-white border-[#0068E1] font-semibold' : 'bg-white text-[#4B5563] border-[#E4E8EE]'"
                        class="btn-tactile px-3 py-1.5 rounded-lg border cursor-pointer">Semua Mutasi ({{ $mutations->count() }})</button>
                <button type="button" @click="switchFilter('CREDIT')"
                        :class="directionFilter === 'CREDIT' ? 'bg-[#00AA5B] text-white border-[#00AA5B] font-semibold' : 'bg-white text-[#4B5563] border-[#E4E8EE]'"
                        class="btn-tactile px-3 py-1.5 rounded-lg border cursor-pointer">Dana Masuk (CREDIT)</button>
                <button type="button" @click="switchFilter('DEBIT')"
                        :class="directionFilter === 'DEBIT' ? 'bg-[#DC2626] text-white border-[#DC2626] font-semibold' : 'bg-white text-[#4B5563] border-[#E4E8EE]'"
                        class="btn-tactile px-3 py-1.5 rounded-lg border cursor-pointer">Dana Keluar (DEBIT)</button>
            </div>
            <span class="font-mono-tabular text-[11px] text-[#6B7280]">Double-Entry Invariant: after = before &plusmn; amount</span>
        </div>

        {{-- Shimmer Table Skeleton --}}
        <div x-show="filtering" x-cloak>
            <x-skeleton.table-rows :rows="6" :cols="6" />
        </div>

        {{-- Crisp Wallet Mutations Ledger Table --}}
        <div x-show="!filtering" class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 2">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] font-semibold uppercase tracking-wider text-[#6B7280]">
                        <tr>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">Tipe Mutasi</th>
                            <th class="px-4 py-3">Bucket</th>
                            <th class="px-4 py-3 text-right">Nominal</th>
                            <th class="px-4 py-3 text-right">Saldo Before &rarr; After</th>
                            <th class="px-4 py-3">Keterangan</th>
                            <th class="px-4 py-3 text-right">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F1F5F9]">
                        @forelse($mutations as $m)
                            <tr x-show="directionFilter === 'ALL' || directionFilter === '{{ $m->direction }}'" class="row-interactive">
                                <td class="px-4 py-3 font-mono-tabular font-semibold text-[#111827]">{{ $m->reference_code }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $m->direction === 'CREDIT' ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#FEF2F2] text-[#B91C1C]' }}">
                                        {{ $m->mutation_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 uppercase text-[10px] font-medium text-[#6B7280]">{{ $m->balance_bucket }}</td>
                                <td class="px-4 py-3 text-right font-mono-tabular font-bold {{ $m->direction === 'CREDIT' ? 'text-[#047857]' : 'text-[#DC2626]' }}">
                                    {{ $m->direction === 'CREDIT' ? '+' : '-' }}Rp {{ number_format((float) $m->amount, 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono-tabular text-[11px] text-[#6B7280]">
                                    {{ number_format((float) $m->balance_before, 0, ',', '.') }} &rarr;
                                    <strong class="text-[#111827]">{{ number_format((float) $m->balance_after, 0, ',', '.') }}</strong>
                                </td>
                                <td class="px-4 py-3 text-[#4B5563] max-w-xs">{{ $m->description }}</td>
                                <td class="px-4 py-3 text-right font-mono-tabular text-[11px] text-[#6B7280]">{{ $m->created_at?->format('d/m H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-[#6B7280]">Belum ada mutasi tercatat.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
