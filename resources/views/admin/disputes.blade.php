@extends('layouts.app')

@section('title', 'Meja Sengketa Arbitrase — Admin NexaTrade')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar :openDisputes="$disputedOrders->count()" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Meja Sengketa &amp; Arbitrase</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Timer pencairan dibekukan (`auto_complete_at = NULL`). Putusan Admin langsung mengeksekusi mutasi escrow atomik.</p>
                </div>
                <span class="px-3 py-1 rounded-full bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] font-mono-tabular text-xs font-bold">
                    {{ $disputedOrders->count() }} Kasus Aktif
                </span>
            </div>

            @forelse($disputedOrders as $idx => $order)
                @php $flaggedCount = $order->messages->where('is_flagged_bypass', true)->count(); @endphp
                <div style="--stagger-index: {{ $idx + 1 }}"
                     class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter">
                    {{-- Case Header --}}
                    <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="badge-live-pulse px-2.5 py-0.5 rounded-full bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] font-bold">DISPUTED</span>
                            <span class="font-mono-tabular font-bold text-sm text-[#111827]">{{ $order->order_code }}</span>
                            <span class="px-2 py-0.5 rounded bg-white border border-[#E4E8EE] text-[10px] font-semibold uppercase text-[#4B5563]">{{ $order->delivery_method }}</span>
                            @if($flaggedCount > 0)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#DC2626] text-white text-[10px] font-bold">
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                    <span>{{ $flaggedCount }} Pelanggaran Anti-Bypass</span>
                                </span>
                            @endif
                        </div>

                        <div class="font-mono-tabular text-xs text-right">
                            <span class="text-[#6B7280]">Gross:</span> <strong class="text-[#111827]">Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }}</strong>
                            <span class="text-[#6B7280] ml-2">Escrow:</span> <strong class="text-[#047857]">Rp {{ number_format((float) $order->seller_escrow_amount, 0, ',', '.') }}</strong>
                        </div>
                    </div>

                    {{-- 2-Column Split: Left = Financials + Verdict Cards | Right = Moderated Chat Transcript --}}
                    <div class="grid grid-cols-1 xl:grid-cols-12 divide-y xl:divide-y-0 xl:divide-x divide-[#E4E8EE]">
                        {{-- Left Column (5/12): Parties & 1-Click Verdict Cards --}}
                        <div class="xl:col-span-5 p-5 space-y-4">
                            <div class="text-xs font-bold text-[#111827]">{{ $order->items->first()?->product_title_snapshot }}</div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                                <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                    <div class="text-[10px] font-bold uppercase text-[#0068E1]">Pembeli</div>
                                    <div class="font-bold text-[#111827] mt-0.5 truncate">{{ $order->buyer?->name }}</div>
                                    <div class="font-mono-tabular text-[11px] text-[#047857] mt-1">Aktif: Rp {{ number_format((float) ($order->buyer?->wallet?->available_balance ?? 0), 0, ',', '.') }}</div>
                                </div>
                                <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                    <div class="text-[10px] font-bold uppercase text-[#C2410C]">Penjual</div>
                                    <div class="font-bold text-[#111827] mt-0.5 truncate">{{ $order->sellerProfile?->store_name }}</div>
                                    <div class="font-mono-tabular text-[11px] text-[#C2410C] mt-1">Pending: Rp {{ number_format((float) ($order->seller?->wallet?->pending_balance ?? 0), 0, ',', '.') }}</div>
                                </div>
                            </div>

                            {{-- Verdict 1: Refund Buyer --}}
                            <form method="POST" action="{{ route('admin.disputes.resolve', $order->order_code) }}" class="p-3.5 rounded-xl bg-[#FEF2F2] border border-[#FECACA] space-y-2.5 text-xs">
                                @csrf
                                <input type="hidden" name="verdict" value="REFUND_TO_BUYER">
                                <div class="font-bold text-[#B91C1C]">Putusan 1: Menangkan Pembeli</div>
                                <p class="text-[11px] text-[#991B1B]">Debit escrow penjual &rarr; refund 100% Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }} ke dompet pembeli.</p>
                                <input type="text" name="admin_notes" required value="Terbukti pelanggaran anti-bypass & akun terkunci 2FA. Refund penuh ke pembeli."
                                       class="w-full h-8 px-2.5 bg-white border border-[#FECACA] rounded-lg text-xs">
                                <button type="submit" class="btn-tactile w-full py-2 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">
                                    Menangkan Pembeli &rarr; Refund ke Dompet
                                </button>
                            </form>

                            {{-- Verdict 2: Release to Seller --}}
                            <form method="POST" action="{{ route('admin.disputes.resolve', $order->order_code) }}" class="p-3.5 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] space-y-2.5 text-xs">
                                @csrf
                                <input type="hidden" name="verdict" value="RELEASE_TO_SELLER">
                                <div class="font-bold text-[#065F46]">Putusan 2: Menangkan Penjual</div>
                                <p class="text-[11px] text-[#047857]">Release escrow Rp {{ number_format((float) $order->seller_escrow_amount, 0, ',', '.') }} ke Saldo Aktif penjual.</p>
                                <input type="text" name="admin_notes" required value="Bukti pengiriman sah dan sesuai deskripsi SKU. Escrow dicairkan ke penjual."
                                       class="w-full h-8 px-2.5 bg-white border border-[#A7F3D0] rounded-lg text-xs">
                                <button type="submit" class="btn-tactile w-full py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white font-bold cursor-pointer">
                                    Menangkan Penjual &rarr; Release Escrow
                                </button>
                            </form>
                        </div>

                        {{-- Right Column (7/12): Forensic Moderated Chat Transcript --}}
                        <div class="xl:col-span-7 p-5 flex flex-col justify-between bg-[#F8FAFC]/60">
                            <div>
                                <div class="text-xs font-bold text-[#111827] mb-3">Transkrip Chat &amp; Audit Forensik Anti-Bypass</div>
                                <div class="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
                                    @foreach($order->messages as $msg)
                                        <div class="p-3 rounded-xl text-xs border {{ $msg->is_flagged_bypass ? 'bg-[#FEF2F2] border-[#FECACA]' : ($msg->sender_role === 'admin' ? 'bg-[#EFF6FF] border-[#BFDBFE]' : 'bg-white border-[#E4E8EE]') }}">
                                            <div class="flex items-center justify-between gap-2 text-[10px] mb-1">
                                                <div class="flex items-center gap-1.5">
                                                    <strong class="uppercase {{ $msg->is_flagged_bypass ? 'text-[#B91C1C]' : ($msg->sender_role === 'admin' ? 'text-[#0068E1]' : 'text-[#111827]') }}">
                                                        {{ $msg->sender_role }} &middot; {{ $msg->sender?->name }}
                                                    </strong>
                                                    @if($msg->is_flagged_bypass)
                                                        <span class="px-1.5 py-0.2 rounded-full bg-[#DC2626] text-white font-bold text-[9px]">BYPASS FLAGGED</span>
                                                    @endif
                                                </div>
                                                <span class="font-mono-tabular text-[#6B7280]">{{ $msg->created_at?->format('d M H:i:s') }}</span>
                                            </div>

                                            <p class="text-[#111827] leading-relaxed">{{ $msg->sanitized_message }}</p>

                                            @if($msg->attachment_proof_url)
                                                <div class="mt-1 font-mono-tabular text-[10px] text-[#0068E1] break-all">Bukti: {{ $msg->attachment_proof_url }}</div>
                                            @endif

                                            @if($msg->is_flagged_bypass)
                                                <div class="mt-2 pt-2 border-t border-[#FECACA] text-[11px] text-[#991B1B] space-y-1">
                                                    <div class="font-mono-tabular text-[10px] font-bold">Pelanggaran: {{ $msg->detected_violation_type }}</div>
                                                    <div class="p-2 rounded-lg bg-white border border-[#FECACA] text-[#4B5563]">
                                                        <span class="block text-[9px] font-bold uppercase text-[#B91C1C]">Raw Evidence (Khusus Admin):</span>
                                                        {{ $msg->raw_message }}
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <form method="POST" action="{{ route('admin.disputes.messages', $order->order_code) }}" class="mt-4 pt-3 border-t border-[#E4E8EE] flex gap-2">
                                @csrf
                                <input type="text" name="message" required placeholder="Kirim pesan mediasi resmi Admin Arbitrase..."
                                       class="flex-1 h-9 px-3 text-xs bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                <button type="submit" class="btn-tactile px-4 h-9 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold cursor-pointer">Kirim</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter">
                    <div class="w-10 h-10 rounded-full bg-[#ECFDF5] text-[#00AA5B] mx-auto flex items-center justify-center mb-2.5">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h2 class="text-sm font-bold text-[#111827]">Tidak Ada Sengketa Aktif</h2>
                    <p class="text-xs text-[#6B7280] mt-1">Semua pesanan berjalan lancar tanpa komplain terbuka.</p>
                </div>
            @endforelse

            @if($resolvedDisputes->isNotEmpty())
                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] text-sm font-bold text-[#111827]">Arsip Putusan Arbitrase Terakhir</div>
                    <div class="divide-y divide-[#F1F5F9] text-xs">
                        @foreach($resolvedDisputes as $rd)
                            <div class="row-interactive p-4 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span class="font-mono-tabular font-bold text-[#111827]">{{ $rd->order_code }}</span>
                                    <span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-bold {{ $rd->status === 'COMPLETED' ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#FEF2F2] text-[#B91C1C]' }}">{{ $rd->status }}</span>
                                    <span class="ml-2 text-[#6B7280]">{{ $rd->buyer?->name }} vs {{ $rd->sellerProfile?->store_name }}</span>
                                </div>
                                <span class="font-mono-tabular font-bold text-[#111827]">Rp {{ number_format((float) $rd->gross_amount, 0, ',', '.') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
