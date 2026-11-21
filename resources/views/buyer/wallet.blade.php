@extends('layouts.app')

@section('title', 'Dompet, Top-Up & Buku Besar Mutasi — NexaTrade')

@section('content')
    @php
        $availableBalance = (float) ($user->wallet?->available_balance ?? 0);
        $nonWithdrawable = (float) ($user->wallet?->non_withdrawable_portion ?? 0);
        $withdrawableBalance = round(max(0.0, $availableBalance - $nonWithdrawable), 2);
        $pendingBalance = (float) ($user->wallet?->pending_balance ?? 0);
    @endphp

    <div x-data="{
            directionFilter: 'ALL',
            filtering: false,
            topupAmount: 100000,
            topupChannel: 'qris',
            submittingTopup: false,
            switchFilter(dir) {
                if (this.directionFilter === dir) return;
                this.filtering = true;
                this.directionFilter = dir;
                setTimeout(() => { this.filtering = false; }, 220);
            }
         }">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-extrabold text-[#111827]">Dompet, Top-Up &amp; Buku Besar Mutasi</h1>
                <p class="text-xs text-[#6B7280] mt-0.5">Rekam jejak transaksi double-entry permanen dengan bukti `before &rarr; after` di setiap rupiah.</p>
            </div>

            @if($demoSwitcherEnabled ?? false)
                <form method="POST" action="{{ route('demo.wallet-topup') }}">
                    @csrf
                    <input type="hidden" name="amount" value="500000">
                    <button type="submit" class="btn-tactile inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-semibold cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        <span>Top-Up Demo Rp 500.000</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- 3-Card Transparent Balance Breakdown Banner --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 card-elevate flex items-start justify-between gap-3 animate-enter" style="--stagger-index: 0">
                <div>
                    <div class="text-xs font-semibold text-[#6B7280]">Saldo Aktif Total</div>
                    <div class="font-mono-tabular text-2xl font-extrabold text-[#047857] mt-1.5">
                        Rp {{ number_format($availableBalance, 0, ',', '.') }}
                    </div>
                    <p class="text-[11px] text-[#6B7280] mt-1.5">Siap dibelanjakan instan di seluruh etalase game NexaTrade.</p>
                </div>
                <span class="w-10 h-10 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-[#00AA5B] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                </span>
            </div>

            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 card-elevate flex items-start justify-between gap-3 animate-enter" style="--stagger-index: 1">
                <div>
                    <div class="text-xs font-semibold text-[#6B7280]">Saldo Dapat Ditarik</div>
                    <div class="font-mono-tabular text-2xl font-extrabold text-[#0068E1] mt-1.5">
                        Rp {{ number_format($withdrawableBalance, 0, ',', '.') }}
                    </div>
                    @if($nonWithdrawable > 0)
                        <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                            <span>Saldo Top-Up (Khusus Belanja): Rp {{ number_format($nonWithdrawable, 0, ',', '.') }}</span>
                        </div>
                    @else
                        <p class="text-[11px] text-[#6B7280] mt-1.5">Bebas porsi Top-Up Anti-Gestun. Menerima refund SLA &amp; Split Settlement.</p>
                    @endif
                </div>
                <span class="w-10 h-10 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                </span>
            </div>

            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 card-elevate flex items-start justify-between gap-3 animate-enter" style="--stagger-index: 2">
                <div>
                    <div class="text-xs font-semibold text-[#6B7280]">Saldo Escrow Tertahan</div>
                    <div class="font-mono-tabular text-2xl font-extrabold text-[#111827] mt-1.5">
                        Rp {{ number_format($pendingBalance, 0, ',', '.') }}
                    </div>
                    <p class="text-[11px] text-[#6B7280] mt-1.5">Pembaruan terakhir: {{ $user->wallet?->updated_at?->format('d M Y H:i:s') ?? '-' }}</p>
                </div>
                <span class="w-10 h-10 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] text-[#F05A28] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
            </div>
        </div>

        {{-- Official Top-Up Card & Recent Top-Up Invoices --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-6 items-start">
            <form method="POST" action="{{ route('buyer.wallet.topup') }}" @submit="submittingTopup = true"
                  class="lg:col-span-7 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 text-xs animate-enter" style="--stagger-index: 3">
                @csrf
                <input type="hidden" name="_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-[#F1F5F9]">
                    <div>
                        <h2 class="text-sm font-bold text-[#111827]">Top-Up Saldo Dompet (QRIS / Virtual Account)</h2>
                        <p class="text-[11px] text-[#6B7280] mt-0.5">Isi saldo cepat untuk checkout 0 detik tanpa biaya gateway. Dilindungi kebijakan Anti-Gestun.</p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] text-[10px] font-bold">Min Rp 10.000</span>
                </div>

                <div>
                    <label class="block font-semibold text-[#374151] mb-2">Pilih Nominal Cepat</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach([50000 => 'Rp 50.000', 100000 => 'Rp 100.000', 250000 => 'Rp 250.000', 500000 => 'Rp 500.000'] as $val => $lbl)
                            <button type="button" @click="topupAmount = {{ $val }}"
                                    :class="Number(topupAmount) === {{ $val }} ? 'bg-[#0068E1] text-white border-[#0068E1]' : 'bg-[#F8FAFC] text-[#111827] border-[#E4E8EE] hover:border-[#0068E1]'"
                                    class="btn-tactile h-9 rounded-lg border font-mono-tabular font-bold cursor-pointer">
                                {{ $lbl }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Nominal Custom (Rp 10rb – 10jt) *</label>
                        <input type="number" name="amount" x-model.number="topupAmount" required min="10000" max="10000000" step="1000"
                               class="w-full h-10 px-3 font-mono-tabular text-sm font-bold bg-[#F8FAFC] border border-[#0068E1] rounded-lg focus:bg-white focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Kanal Pembayaran *</label>
                        <select name="payment_channel" x-model="topupChannel"
                                class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none font-semibold text-[#111827]">
                            <option value="qris">QRIS Instan</option>
                            <option value="midtrans_snap">Midtrans Snap</option>
                            <option value="bca_va">BCA Virtual Account</option>
                            <option value="bri_va">BRI Virtual Account</option>
                            <option value="gopay">GoPay</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-[#F1F5F9]">
                    <span class="text-[11px] text-[#6B7280]">Dana hasil Top-Up hanya untuk belanja (tidak dapat ditarik tunai / Anti-Gestun).</span>
                    <button type="submit" :disabled="submittingTopup"
                            class="btn-tactile h-10 px-5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold inline-flex items-center gap-2 cursor-pointer disabled:opacity-65">
                        <svg x-show="submittingTopup" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        <span x-text="submittingTopup ? 'Membuat Tagihan...' : 'Buat Tagihan Top-Up'"></span>
                    </button>
                </div>
            </form>

            {{-- Recent Top-Up History --}}
            <div class="lg:col-span-5 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 4">
                <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                    <h2 class="text-sm font-bold text-[#111827]">Riwayat Tagihan Top-Up</h2>
                    <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ ($topups ?? collect())->count() }} tagihan</span>
                </div>
                <div class="divide-y divide-[#F1F5F9] max-h-[260px] overflow-y-auto text-xs">
                    @forelse(($topups ?? collect()) as $tu)
                        <div class="row-interactive px-4 py-3 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <a href="{{ route('buyer.wallet.topup.show', $tu->topup_code) }}"
                                       class="font-mono-tabular font-bold text-[#0068E1] hover:underline truncate">{{ $tu->topup_code }}</a>
                                    <span class="px-1.5 py-0.2 rounded-full text-[9px] font-bold {{ $tu->status === 'PAID' ? 'bg-[#ECFDF5] text-[#047857]' : ($tu->status === 'UNPAID' ? 'bg-[#FFF4ED] text-[#C2410C]' : 'bg-[#FEF2F2] text-[#B91C1C]') }}">
                                        {{ $tu->status }}
                                    </span>
                                </div>
                                <div class="text-[10px] text-[#6B7280] mt-0.5 uppercase">{{ str_replace('_', ' ', $tu->payment_channel) }} &middot; {{ $tu->created_at?->format('d M H:i') }}</div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-mono-tabular font-bold text-[#111827]">Rp {{ number_format((float) $tu->amount, 0, ',', '.') }}</div>
                                @if($tu->status === 'UNPAID')
                                    <a href="{{ route('buyer.wallet.topup.show', $tu->topup_code) }}" class="text-[10px] font-bold text-[#00AA5B] hover:underline">Bayar Sekarang &rarr;</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="p-6 text-center text-[#6B7280]">Belum ada tagihan Top-Up dompet.</p>
                    @endforelse
                </div>
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
        <div x-show="!filtering" class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 5">
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
