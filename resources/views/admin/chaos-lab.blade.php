@extends('layouts.app')

@section('title', 'Chaos Lab Workbench — Admin NexaTrade')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start"
         x-data="{
            runningId: null,
            runningAll: false,
            selectedId: null,
            results: {},
            errors: {},
            copiedJson: false,

            get passedCount() {
                return Object.values(this.results).filter(r => r && r.passed === true).length;
            },

            get activeResult() {
                return this.selectedId ? this.results[this.selectedId] : null;
            },

            get activeError() {
                return this.selectedId ? this.errors[this.selectedId] : null;
            },

            async runScenario(id) {
                this.selectedId = id;
                this.runningId = id;
                this.errors[id] = null;
                this.results[id] = null;
                window.dispatchEvent(new CustomEvent('nexa-progress-start'));
                try {
                    const shimmerBeat = new Promise((r) => setTimeout(r, 360));
                    const req = fetch('/admin/chaos-lab/run/' + id, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                    }).then((res) => res.json());

                    const [data] = await Promise.all([req, shimmerBeat]);
                    if (data.ok && data.result) {
                        this.results[id] = data.result;
                        if (!this.runningAll) {
                            window.nexaToast(
                                data.result.passed ? 'success' : 'error',
                                'Skenario 0' + id + (data.result.passed ? ' Lulus' : ' Gagal'),
                                data.result.title + ' (' + data.result.duration_ms + ' ms).'
                            );
                        }
                    } else {
                        this.errors[id] = (data.exception ? '[' + data.exception + '] ' : '') + (data.message || 'Eksekusi skenario gagal.');
                    }
                } catch (e) {
                    this.errors[id] = 'Gagal menghubungi endpoint Chaos Lab.';
                } finally {
                    this.runningId = null;
                    if (!this.runningAll) {
                        window.dispatchEvent(new CustomEvent('nexa-progress-done'));
                    }
                }
            },

            async runAllScenarios() {
                this.runningAll = true;
                for (let id = 1; id <= 8; id++) {
                    await this.runScenario(id);
                }
                this.runningAll = false;
                window.dispatchEvent(new CustomEvent('nexa-progress-done'));
                window.nexaToast(
                    this.passedCount === 8 ? 'success' : 'warning',
                    'Audit Suite Selesai',
                    this.passedCount + '/8 skenario verifikasi MySQL InnoDB lulus penuh.'
                );
            },

            copyAuditJson() {
                if (!this.activeResult) return;
                navigator.clipboard.writeText(JSON.stringify(this.activeResult.audit_trace || {}, null, 2));
                this.copiedJson = true;
                window.nexaToast('success', 'JSON Disalin', 'Audit trace skenario disalin ke clipboard.');
                setTimeout(() => { this.copiedJson = false; }, 2000);
            }
         }">

        <x-admin-sidebar :openDisputes="$systemTelemetry['active_disputes']" :pendingWithdrawals="$systemTelemetry['pending_withdrawals']" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Stripe Developer Workbench Header --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 animate-enter" style="--stagger-index: 0">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-[#F1F5F9]">
                    <div>
                        <div class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-[#0068E1] mb-1">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>
                            <span>Developer Verification Workbench</span>
                        </div>
                        <h1 class="text-xl font-extrabold text-[#111827]">8-Scenario Backend Chaos Lab</h1>
                        <p class="text-xs text-[#6B7280] mt-0.5">Eksekusi real-time terhadap Service Layer &amp; MySQL InnoDB (`lockForUpdate`, `SKIP LOCKED`, SHA-512, Blind Index, Double-Entry Ledger).</p>
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0">
                        <div class="px-3.5 py-2 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] text-center">
                            <div class="text-[10px] font-semibold uppercase text-[#6B7280]">Pass Rate</div>
                            <div class="font-mono-tabular text-sm font-extrabold text-[#047857]" x-text="passedCount + ' / 8'"></div>
                        </div>

                        <button type="button" @click="runAllScenarios()" :disabled="runningAll || runningId !== null"
                                class="btn-tactile h-11 px-4 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-2 cursor-pointer disabled:opacity-55">
                            <svg x-show="runningAll" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <svg x-show="!runningAll" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                            <span x-show="!runningAll">Jalankan Semua 8 Skenario</span>
                            <span x-show="runningAll" x-cloak>Menjalankan #0<span x-text="runningId"></span>...</span>
                        </button>
                    </div>
                </div>

                {{-- 6 Live Telemetry KPI Tiles --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-2.5 pt-4 text-xs">
                    <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] text-[#6B7280]">Escrow Locked</div>
                        <div class="font-mono-tabular font-bold text-[#111827] mt-0.5">Rp {{ number_format($systemTelemetry['total_escrow_locked'], 0, ',', '.') }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] text-[#6B7280]">Available Liquidity</div>
                        <div class="font-mono-tabular font-bold text-[#047857] mt-0.5">Rp {{ number_format($systemTelemetry['total_available_liquidity'], 0, ',', '.') }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] text-[#6B7280]">Active Disputes</div>
                        <div class="font-mono-tabular font-bold text-[#DC2626] mt-0.5">{{ $systemTelemetry['active_disputes'] }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] text-[#6B7280]">Pending Withdraw</div>
                        <div class="font-mono-tabular font-bold text-[#C2410C] mt-0.5">{{ $systemTelemetry['pending_withdrawals'] }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] text-[#6B7280]">Webhook Logs</div>
                        <div class="font-mono-tabular font-bold text-[#111827] mt-0.5">{{ $systemTelemetry['webhook_logs_count'] }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] text-[#6B7280]">Ledger Rows</div>
                        <div class="font-mono-tabular font-bold text-[#0068E1] mt-0.5">{{ $systemTelemetry['ledger_rows_count'] }}</div>
                    </div>
                </div>
            </div>

            {{-- 2-Column Stripe Workbench: Left = 8 White Scenario Cards | Right = Sticky Live Execution Inspector --}}
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                {{-- Left Column (6/12): 8 Scenario Cards --}}
                <div class="xl:col-span-6 space-y-3">
                    @foreach($scenarios as $idx => $sc)
                        <div style="--stagger-index: {{ $idx }}"
                             @click="if (results[{{ $sc['id'] }}] || errors[{{ $sc['id'] }}]) selectedId = {{ $sc['id'] }}"
                             :class="selectedId === {{ $sc['id'] }} ? 'border-[#0068E1] ring-2 ring-[#0068E1]/15' : 'border-[#E4E8EE] hover:border-[#CBD5E1]'"
                             class="bg-white border rounded-xl p-4 transition-all animate-enter cursor-pointer">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3 min-w-0">
                                    <span class="font-mono-tabular w-7 h-7 rounded-lg text-xs font-bold flex items-center justify-center shrink-0"
                                          :class="results[{{ $sc['id'] }}]?.passed ? 'bg-[#ECFDF5] text-[#047857] border border-[#A7F3D0]' : (runningId === {{ $sc['id'] }} ? 'bg-[#EFF6FF] text-[#0068E1] border border-[#BFDBFE]' : 'bg-[#F1F5F9] text-[#4B5563] border border-[#E4E8EE]')">
                                        0{{ $sc['id'] }}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="text-[10px] font-semibold uppercase tracking-wide text-[#6B7280]">{{ $sc['badge'] }}</span>
                                            <template x-if="runningId === {{ $sc['id'] }}">
                                                <span class="badge-live-pulse px-2 py-0.2 rounded-full bg-[#EFF6FF] text-[#0068E1] text-[10px] font-bold">Running</span>
                                            </template>
                                            <template x-if="runningId !== {{ $sc['id'] }} && results[{{ $sc['id'] }}]?.passed">
                                                <span class="px-2 py-0.2 rounded-full bg-[#ECFDF5] text-[#047857] font-mono-tabular text-[10px] font-bold"
                                                      x-text="'PASS · ' + results[{{ $sc['id'] }}].duration_ms + 'ms'"></span>
                                            </template>
                                            <template x-if="runningId !== {{ $sc['id'] }} && ((results[{{ $sc['id'] }}] && !results[{{ $sc['id'] }}].passed) || errors[{{ $sc['id'] }}])">
                                                <span class="px-2 py-0.2 rounded-full bg-[#FEF2F2] text-[#B91C1C] text-[10px] font-bold">FAIL</span>
                                            </template>
                                        </div>
                                        <h2 class="text-sm font-bold text-[#111827] mt-0.5 leading-snug">{{ $sc['title'] }}</h2>
                                        <p class="text-xs text-[#6B7280] mt-1 leading-relaxed">{{ $sc['summary'] }}</p>
                                    </div>
                                </div>

                                <button type="button" @click.stop="runScenario({{ $sc['id'] }})" :disabled="runningAll || runningId !== null"
                                        class="btn-tactile px-3 py-1.5 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] text-xs font-semibold shrink-0 cursor-pointer disabled:opacity-45">
                                    Jalankan
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Right Column (6/12, Sticky): Live Execution Inspector --}}
                <div class="xl:col-span-6 xl:sticky xl:top-20">
                    <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                        <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>
                                <h2 class="text-sm font-bold text-[#111827]">Live Execution Inspector</h2>
                            </div>
                            <span class="font-mono-tabular text-[11px] text-[#6B7280]" x-text="selectedId ? 'POST /admin/chaos-lab/run/' + selectedId : 'Standby'"></span>
                        </div>

                        <div class="p-5 min-h-[380px]">
                            {{-- Idle State --}}
                            <div x-show="!selectedId && runningId === null" class="h-72 flex flex-col items-center justify-center text-center px-6">
                                <div class="w-11 h-11 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center mb-3">
                                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>
                                </div>
                                <div class="text-sm font-bold text-[#111827]">Siap Menjalankan Verifikasi InnoDB</div>
                                <p class="text-xs text-[#6B7280] mt-1 max-w-sm">
                                    Klik tombol <strong>Jalankan</strong> pada salah satu skenario di sebelah kiri atau jalankan seluruh 8 skenario untuk melihat assertion dan audit log JSON secara real-time.
                                </p>
                            </div>

                            {{-- Light-Mode Shimmer Skeleton While Executing --}}
                            <div x-show="runningId !== null && selectedId === runningId" x-cloak>
                                <x-skeleton.chaos-output />
                            </div>

                            {{-- Error Result --}}
                            <div x-show="runningId === null && activeError" x-cloak
                                 class="p-4 rounded-xl bg-[#FEF2F2] border border-[#FECACA] text-xs text-[#B91C1C] font-mono-tabular"
                                 x-text="activeError"></div>

                            {{-- Structured PASS/FAIL Audit Summary + JSON Trace --}}
                            <div x-show="runningId !== selectedId && activeResult" x-cloak
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 translate-y-1"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-[#F1F5F9]">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold border"
                                              :class="activeResult?.passed ? 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]' : 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]'"
                                              x-text="activeResult?.passed ? 'PASS VERIFIED' : 'ASSERTION FAILED'"></span>
                                        <span class="text-xs font-bold text-[#111827]" x-text="'Skenario 0' + (activeResult?.scenario ?? '')"></span>
                                    </div>
                                    <span class="font-mono-tabular text-xs font-semibold text-[#6B7280]" x-text="(activeResult?.duration_ms ?? 0) + ' ms'"></span>
                                </div>

                                <div class="text-xs font-semibold text-[#111827]" x-text="activeResult?.title"></div>

                                {{-- Structured Assertions Grid --}}
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <template x-for="(val, key) in (activeResult?.assertions || {})" :key="key">
                                        <div class="px-3 py-2 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] flex items-center justify-between gap-2 font-mono-tabular text-[11px]">
                                            <span class="text-[#6B7280] truncate" x-text="key"></span>
                                            <span class="font-bold shrink-0"
                                                  :class="val === false ? 'text-[#DC2626]' : 'text-[#047857]'"
                                                  x-text="typeof val === 'boolean' ? (val ? 'TRUE' : 'FALSE') : val"></span>
                                        </div>
                                    </template>
                                </div>

                                {{-- Syntax-Styled Light-Mode JSON Audit Log Block --}}
                                <div>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-[#6B7280]">Audit Trace (JSON / Ledger / SQL)</span>
                                        <button type="button" @click="copyAuditJson()" class="btn-tactile text-[11px] font-semibold text-[#0068E1] hover:underline cursor-pointer" x-text="copiedJson ? 'Tersalin!' : 'Salin JSON'"></button>
                                    </div>
                                    <pre class="p-3.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] font-mono-tabular text-[11px] text-[#111827] overflow-x-auto max-h-72 leading-relaxed"
                                         x-text="JSON.stringify(activeResult?.audit_trace || {}, null, 2)"></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Live Audit Tables: Payment Webhook Logs & Latest Double-Entry Mutations --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-xs font-bold text-[#111827]">Log Idempotency Webhook (`payment_webhook_logs`)</h2>
                        <a href="{{ route('admin.chaos-lab') }}" class="text-[11px] font-semibold text-[#0068E1] hover:underline">Refresh DB</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left font-mono-tabular text-[11px]">
                            <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[10px] font-semibold uppercase text-[#6B7280]">
                                <tr>
                                    <th class="px-3 py-2.5">Provider</th>
                                    <th class="px-3 py-2.5">Idempotency Key</th>
                                    <th class="px-3 py-2.5">Sig</th>
                                    <th class="px-3 py-2.5">HTTP</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F1F5F9]">
                                @forelse($recentWebhooks as $wh)
                                    <tr class="row-interactive">
                                        <td class="px-3 py-2 font-semibold text-[#111827]">{{ $wh->provider }}</td>
                                        <td class="px-3 py-2 max-w-[180px] truncate text-[#4B5563]" title="{{ $wh->idempotency_key }}">{{ $wh->idempotency_key }}</td>
                                        <td class="px-3 py-2 font-bold {{ $wh->signature_valid ? 'text-[#047857]' : 'text-[#DC2626]' }}">{{ $wh->signature_valid ? 'VALID' : 'FAKE' }}</td>
                                        <td class="px-3 py-2 font-bold text-[#111827]">{{ $wh->http_status }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-6 text-center text-[#6B7280] font-sans">Jalankan skenario 01-04 atau 07 untuk mengisi log webhook.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-xs font-bold text-[#111827]">Mutasi Buku Besar Terkini (`wallet_mutations`)</h2>
                        <a href="{{ route('admin.chaos-lab') }}" class="text-[11px] font-semibold text-[#0068E1] hover:underline">Refresh DB</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left font-mono-tabular text-[11px]">
                            <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[10px] font-semibold uppercase text-[#6B7280]">
                                <tr>
                                    <th class="px-3 py-2.5">Reference</th>
                                    <th class="px-3 py-2.5">Type</th>
                                    <th class="px-3 py-2.5">User</th>
                                    <th class="px-3 py-2.5 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F1F5F9]">
                                @foreach($recentMutations as $m)
                                    <tr class="row-interactive">
                                        <td class="px-3 py-2 font-semibold text-[#111827] max-w-[150px] truncate">{{ $m->reference_code }}</td>
                                        <td class="px-3 py-2">
                                            <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold {{ $m->direction === 'CREDIT' ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#FEF2F2] text-[#B91C1C]' }}">
                                                {{ $m->mutation_type }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 font-sans text-[#6B7280]">{{ Str::limit($m->user?->name, 14) }}</td>
                                        <td class="px-3 py-2 text-right font-bold {{ $m->direction === 'CREDIT' ? 'text-[#047857]' : 'text-[#DC2626]' }}">
                                            {{ $m->direction === 'CREDIT' ? '+' : '-' }}{{ number_format((float) $m->amount, 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
