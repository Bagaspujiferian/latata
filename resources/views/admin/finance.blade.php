@extends('layouts.app')

@section('title', 'Admin Finance P&L & Rekonsiliasi Ledger Global — NexaTrade')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar />

        <div class="flex-1 min-w-0 space-y-5 w-full" x-data="{ runningJob: false }">
            {{-- Header & Date Filter --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Keuangan Perusahaan (P&amp;L) &amp; Audit Ledger Global</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Rekonsiliasi dana titipan bank (*Custody Liability*), Laba Bersih Platform, Pendapatan Ad Credits, serta worker H2H.</p>
                </div>

                <form method="GET" action="{{ route('admin.finance.index') }}" class="flex flex-wrap items-center gap-2 text-xs">
                    <input type="date" name="from" value="{{ $fromInput }}" class="h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    <span class="text-[#6B7280]">&ndash;</span>
                    <input type="date" name="to" value="{{ $toInput }}" class="h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                    <button type="submit" class="btn-tactile h-9 px-3.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">Terapkan</button>
                    @if($fromInput !== '' || $toInput !== '')
                        <a href="{{ route('admin.finance.index') }}" class="text-[#0068E1] font-semibold hover:underline">Reset</a>
                    @endif
                </form>
            </div>

            {{-- Primary P&L KPI Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3.5 animate-enter" style="--stagger-index: 1">
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate">
                    <div class="text-[11px] font-semibold text-[#6B7280]">GMV (Gross Merchandise Value)</div>
                    <div class="font-mono-tabular text-lg font-extrabold text-[#111827] mt-1">
                        Rp {{ number_format((float) $summary['gmv'], 0, ',', '.') }}
                    </div>
                    <div class="text-[10px] text-[#6B7280] mt-1">Total transaksi berbayar &amp; selesai</div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate">
                    <div class="text-[11px] font-semibold text-[#6B7280]">Pendapatan Kotor Platform</div>
                    <div class="font-mono-tabular text-lg font-extrabold text-[#0068E1] mt-1">
                        Rp {{ number_format((float) $summary['gross_revenue_total'], 0, ',', '.') }}
                    </div>
                    <div class="text-[10px] text-[#4B5563] mt-1 font-mono-tabular">
                        Komisi Rp {{ number_format((float) $summary['gross_commission_revenue'], 0, ',', '.') }}
                        &middot; Ads Rp {{ number_format((float) $summary['ad_credits_revenue'], 0, ',', '.') }}
                    </div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate">
                    <div class="text-[11px] font-semibold text-[#6B7280]">Biaya MDR Payment Gateway</div>
                    <div class="font-mono-tabular text-lg font-extrabold text-[#D97706] mt-1">
                        - Rp {{ number_format((float) ($summary['total_gateway_mdr_cost'] ?? 0), 0, ',', '.') }}
                    </div>
                    <div class="text-[10px] text-[#4B5563] mt-1 font-mono-tabular">
                        Inv Rp {{ number_format((float) ($summary['total_invoice_mdr_cost'] ?? 0), 0, ',', '.') }}
                        &middot; Top-Up Rp {{ number_format((float) ($summary['total_topup_mdr_cost'] ?? 0), 0, ',', '.') }}
                    </div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate">
                    <div class="text-[11px] font-semibold text-[#6B7280]">Beban Subsidi &amp; Eksposur Hackback</div>
                    <div class="font-mono-tabular text-lg font-extrabold text-[#F05A28] mt-1">
                        - Rp {{ number_format((float) $summary['total_expenses'], 0, ',', '.') }}
                    </div>
                    <div class="text-[10px] text-[#4B5563] mt-1 font-mono-tabular">
                        Voucher Rp {{ number_format((float) $summary['platform_voucher_subsidy_expense'], 0, ',', '.') }}
                        &middot; Net Debt Rp {{ number_format((float) $summary['net_hackback_exposure'], 0, ',', '.') }}
                    </div>
                </div>

                <div class="bg-[#ECFDF5] border border-[#A7F3D0] rounded-xl p-4 card-elevate">
                    <div class="text-[11px] font-bold text-[#065F46]">True Net Operating Profit</div>
                    <div class="font-mono-tabular text-lg font-extrabold {{ ($summary['true_net_operating_profit'] ?? $summary['net_platform_revenue']) >= 0 ? 'text-[#047857]' : 'text-[#DC2626]' }} mt-1">
                        Rp {{ number_format((float) ($summary['true_net_operating_profit'] ?? $summary['net_platform_revenue']), 0, ',', '.') }}
                    </div>
                    <div class="text-[10px] text-[#047857] mt-1 font-mono-tabular">
                        Net Rev: Rp {{ number_format((float) $summary['net_platform_revenue'], 0, ',', '.') }} &middot; WD Fee: Rp {{ number_format((float) $summary['withdrawal_fee_revenue'], 0, ',', '.') }}
                    </div>
                </div>
            </div>

            {{-- Global Ledger Reconciliation & H2H Operations Panel --}}
            @php $latestAudit = $summary['latest_audit']; @endphp
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 animate-enter" style="--stagger-index: 2">
                <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-[#F1F5F9]">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-extrabold text-[#111827]">Rekonsiliasi Buku Besar Global &amp; Custody Liability</h2>
                            @if($latestAudit && $latestAudit->status === 'BALANCED')
                                <span class="px-2.5 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[11px] font-extrabold font-mono-tabular">
                                    BALANCED (Selisih Rp 0)
                                </span>
                            @elseif($latestAudit)
                                <span class="badge-live-pulse px-2.5 py-0.5 rounded-full bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] text-[11px] font-extrabold font-mono-tabular">
                                    DISCREPANCY (Rp {{ number_format((float) $latestAudit->discrepancy_amount, 0, ',', '.') }})
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-[#6B7280] mt-0.5">
                            Total dana titipan wajib di rekening bank perusahaan (*Custody Liability*):
                            <strong class="font-mono-tabular text-[#111827]">Rp {{ number_format((float) $summary['custody_liability'], 0, ',', '.') }}</strong>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('admin.finance.audit-ledger') }}" @submit="runningJob = true">
                            @csrf
                            <button type="submit" :disabled="runningJob"
                                    class="btn-tactile px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold cursor-pointer disabled:opacity-60">
                                Jalankan Audit Ledger Sekarang
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.finance.reconcile-h2h') }}" @submit="runningJob = true">
                            @csrf
                            <input type="hidden" name="minutes" value="5">
                            <button type="submit" :disabled="runningJob"
                                    class="btn-tactile px-4 py-2 rounded-lg bg-white hover:bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-xs font-bold cursor-pointer disabled:opacity-60">
                                Jalankan Reconcile H2H ({{ $pendingH2hCount }} Processing)
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Light Mode Shimmer while executing Audit/Reconcile --}}
                <div x-show="runningJob" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-3" aria-busy="true">
                    <div class="h-16 rounded-xl skeleton-shimmer"></div>
                    <div class="h-16 rounded-xl skeleton-shimmer"></div>
                    <div class="h-16 rounded-xl skeleton-shimmer"></div>
                </div>

                <div x-show="!runningJob" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs font-mono-tabular">
                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="font-sans text-[10px] font-semibold uppercase text-[#6B7280]">Bucket Pending Escrow</div>
                        <div class="text-sm font-extrabold text-[#C2410C] mt-0.5">Rp {{ number_format((float) $summary['custody_pending'], 0, ',', '.') }}</div>
                    </div>
                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="font-sans text-[10px] font-semibold uppercase text-[#6B7280]">Bucket Saldo Aktif</div>
                        <div class="text-sm font-extrabold text-[#047857] mt-0.5">Rp {{ number_format((float) $summary['custody_available'], 0, ',', '.') }}</div>
                    </div>
                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="font-sans text-[10px] font-semibold uppercase text-[#6B7280]">Bucket Deposit Jaminan</div>
                        <div class="text-sm font-extrabold text-[#0068E1] mt-0.5">Rp {{ number_format((float) $summary['custody_deposit'], 0, ',', '.') }}</div>
                    </div>
                    <div class="p-3 rounded-lg bg-[#FEF2F2] border border-[#FECACA]">
                        <div class="font-sans text-[10px] font-semibold uppercase text-[#991B1B]">Piutang Clawback Penjual</div>
                        <div class="text-sm font-extrabold text-[#DC2626] mt-0.5">Rp {{ number_format((float) $summary['outstanding_seller_debt'], 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>

            {{-- System Worker Health (Scheduler Heartbeat) Card --}}
            @if(isset($workerHealth))
                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 3">
                    <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <span class="w-8 h-8 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            </span>
                            <div>
                                <h2 class="text-sm font-bold text-[#111827]">System Worker Health (Scheduler Heartbeat)</h2>
                                <p class="text-[11px] text-[#6B7280]">Deteksi otomatis cron/worker mati diam-diam (`STALE` bila melewati 2&times; interval jadwal).</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-[11px] font-mono-tabular">
                            <span class="px-2.5 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] font-bold">
                                HEALTHY: {{ $workerHealth['healthy_count'] }}/{{ $workerHealth['total'] }}
                            </span>
                            @if($workerHealth['stale_count'] > 0)
                                <span class="px-2.5 py-0.5 rounded-full bg-[#FFFBEB] border border-[#FDE68A] text-[#B45309] font-bold">
                                    STALE: {{ $workerHealth['stale_count'] }}
                                </span>
                            @endif
                            @if($workerHealth['failed_count'] > 0)
                                <span class="px-2.5 py-0.5 rounded-full bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] font-bold">
                                    FAILED: {{ $workerHealth['failed_count'] }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] font-semibold uppercase text-[#6B7280]">
                                <tr>
                                    <th class="px-4 py-3">Worker Command</th>
                                    <th class="px-4 py-3">Interval</th>
                                    <th class="px-4 py-3">Terakhir Selesai</th>
                                    <th class="px-4 py-3 text-right">Durasi</th>
                                    <th class="px-4 py-3">Ringkasan / Error</th>
                                    <th class="px-4 py-3 text-right">Health Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F1F5F9]">
                                @foreach($workerHealth['workers'] as $w)
                                    @php
                                        $statusVal = $w['health_status']->value ?? (string) $w['health_status'];
                                        $pillClass = match ($statusVal) {
                                            'healthy' => 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857]',
                                            'stale' => 'bg-[#FFFBEB] border-[#FDE68A] text-[#B45309]',
                                            'failed' => 'bg-[#FEF2F2] border-[#FECACA] text-[#B91C1C]',
                                            'running' => 'bg-[#EFF6FF] border-[#BFDBFE] text-[#0068E1]',
                                            default => 'bg-[#F1F5F9] border-[#E4E8EE] text-[#4B5563]',
                                        };
                                    @endphp
                                    <tr class="row-interactive">
                                        <td class="px-4 py-3">
                                            <div class="font-bold text-[#111827]">{{ $w['display_name'] }}</div>
                                            <div class="font-mono-tabular text-[11px] text-[#0068E1]">{{ $w['command_name'] }}</div>
                                        </td>
                                        <td class="px-4 py-3 font-mono-tabular text-[#4B5563]">
                                            Setiap {{ $w['expected_interval_minutes'] }}m
                                        </td>
                                        <td class="px-4 py-3 font-mono-tabular text-[11px] text-[#4B5563]">
                                            {{ $w['last_finished_at'] ? $w['last_finished_at']->format('d M Y H:i:s') : 'Belum Pernah' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono-tabular text-[#111827]">
                                            {{ $w['last_duration_ms'] !== null ? number_format($w['last_duration_ms'], 0, ',', '.').' ms' : '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-[11px] max-w-md truncate">
                                            @if(! empty($w['last_error']))
                                                <span class="text-[#DC2626] font-semibold">{{ $w['last_error'] }}</span>
                                            @else
                                                <span class="text-[#6B7280]">{{ $w['last_summary'] ?? '-' }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full border text-[10px] font-extrabold font-mono-tabular {{ $pillClass }}">
                                                {{ $w['health_label'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Audit Snapshots Table --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 3">
                <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                    <h2 class="text-sm font-bold text-[#111827]">Riwayat Snapshot Rekonsiliasi Buku Besar (`ledger_audit_snapshots`)</h2>
                    <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $auditSnapshots->count() }} snapshot</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] font-semibold uppercase text-[#6B7280]">
                            <tr>
                                <th class="px-4 py-3">Audit Code</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Custody Wallets</th>
                                <th class="px-4 py-3 text-right">Ledger Net</th>
                                <th class="px-4 py-3 text-right">Selisih</th>
                                <th class="px-4 py-3">Dipicu Oleh</th>
                                <th class="px-4 py-3">Waktu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F1F5F9]">
                            @forelse($auditSnapshots as $snap)
                                <tr class="row-interactive">
                                    <td class="px-4 py-3 font-mono-tabular font-bold text-[#111827]">{{ $snap->audit_code }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $snap->status === 'BALANCED' ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#FEF2F2] text-[#B91C1C]' }}">
                                            {{ $snap->status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono-tabular">Rp {{ number_format((float) $snap->total_custody_wallets, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right font-mono-tabular">Rp {{ number_format((float) $snap->total_ledger_net, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right font-mono-tabular font-bold {{ (float) $snap->discrepancy_amount === 0.0 ? 'text-[#047857]' : 'text-[#DC2626]' }}">
                                        Rp {{ number_format((float) $snap->discrepancy_amount, 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-[#4B5563]">{{ $snap->triggered_by }}</td>
                                    <td class="px-4 py-3 font-mono-tabular text-[11px] text-[#6B7280]">{{ $snap->created_at?->format('d M Y H:i:s') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-[#6B7280]">Belum ada snapshot audit ledger.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Ad Credit Revenue & Debt Recovery Tables --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 animate-enter" style="--stagger-index: 4">
                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">
                        Penjualan Ad Credits (`ad_credit_purchases`)
                    </div>
                    <div class="divide-y divide-[#F1F5F9] text-xs">
                        @forelse($adCreditPurchases as $ap)
                            <div class="row-interactive p-3.5 flex items-center justify-between gap-2">
                                <div>
                                    <div class="font-bold text-[#111827]">{{ $ap->sellerProfile?->store_name ?? 'Seller #'.$ap->seller_id }}</div>
                                    <div class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $ap->purchase_code }} &middot; {{ $ap->package?->name }} (+{{ $ap->credits_granted }})</div>
                                </div>
                                <div class="text-right font-mono-tabular">
                                    <div class="font-bold text-[#047857]">+Rp {{ number_format((float) $ap->amount_paid, 0, ',', '.') }}</div>
                                    <div class="text-[10px] text-[#6B7280]">{{ $ap->created_at?->format('d M H:i') }}</div>
                                </div>
                            </div>
                        @empty
                            <p class="p-6 text-center text-[#6B7280]">Belum ada transaksi penjualan Ad Credits.</p>
                        @endforelse
                    </div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">
                        Pelunasan Tunggakan Clawback (`debt_repayments`)
                    </div>
                    <div class="divide-y divide-[#F1F5F9] text-xs">
                        @forelse($debtRepayments as $dr)
                            <div class="row-interactive p-3.5 flex items-center justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-[#111827]">{{ $dr->sellerProfile?->store_name ?? 'Seller #'.$dr->seller_id }}</span>
                                        @if($dr->auto_unsuspended)
                                            <span class="px-1.5 py-0.2 rounded bg-[#ECFDF5] text-[#047857] text-[9px] font-bold">AUTO-UNSUSPEND</span>
                                        @endif
                                    </div>
                                    <div class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $dr->repayment_code }} &middot; {{ $dr->payment_source }}</div>
                                </div>
                                <div class="text-right font-mono-tabular">
                                    <div class="font-bold text-[#0068E1]">Rp {{ number_format((float) $dr->amount, 0, ',', '.') }}</div>
                                    <div class="text-[10px] text-[#6B7280]">Sisa: Rp {{ number_format((float) $dr->debt_after, 0, ',', '.') }}</div>
                                </div>
                            </div>
                        @empty
                            <p class="p-6 text-center text-[#6B7280]">Belum ada pelunasan tunggakan clawback.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
