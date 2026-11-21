@extends('layouts.app')

@section('title', 'Pesanan Saya — NexaTrade')

@section('content')
    <div x-data="{ switchingTab: false }">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-extrabold text-[#111827]">Pesanan Saya</h1>
                <p class="text-xs text-[#6B7280] mt-0.5">Pantau status SLA penjual, buka brankas voucher AES-256, dan kelola jaminan escrow Anda.</p>
            </div>
            <a href="{{ route('marketplace.index') }}" class="btn-tactile inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                <span>Belanja Produk Lain</span>
            </a>
        </div>

        {{-- Unpaid Invoices Alert Strip --}}
        @if($unpaidInvoices->isNotEmpty())
            <div class="mb-5 bg-[#FFF4ED] border border-[#FED7AA] rounded-xl p-4 space-y-3 animate-enter" style="--stagger-index: 0">
                <div class="flex items-center gap-2 text-xs font-bold text-[#C2410C]">
                    <svg class="w-4 h-4 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Tagihan Menunggu Pembayaran ({{ $unpaidInvoices->count() }})</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($unpaidInvoices as $inv)
                        <div class="bg-white border border-[#FED7AA] rounded-lg p-3.5 flex items-center justify-between gap-3"
                             x-data="liveCountdown('{{ $inv->expires_at?->toIso8601String() }}')">
                            <div class="min-w-0">
                                <div class="font-mono-tabular text-xs font-bold text-[#111827]">{{ $inv->invoice_code }}</div>
                                <div class="text-xs text-[#4B5563] mt-0.5">
                                    {{ $inv->orders->count() }} Toko &middot; <strong class="font-mono-tabular text-[#F05A28]">Rp {{ number_format((float) $inv->grand_total, 0, ',', '.') }}</strong>
                                </div>
                                <div class="badge-live-pulse font-mono-tabular text-[11px] font-semibold text-[#C2410C] mt-1">
                                    Sisa waktu: <span x-text="formatted"></span>
                                </div>
                            </div>
                            <a href="{{ route('invoices.show', $inv->invoice_code) }}" class="btn-tactile px-3.5 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold shrink-0">
                                Bayar Sekarang
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Clean Horizontal Status Tabs --}}
        @php
            $tabs = [
                '' => 'Semua',
                'WAITING_PAYMENT' => 'Menunggu Bayar',
                'PROCESSING' => 'Diproses',
                'DELIVERED' => 'Dikirim',
                'DISPUTED' => 'Komplain',
                'COMPLETED' => 'Selesai',
                'REFUNDED' => 'Refund',
                'CANCELLED' => 'Batal',
            ];
        @endphp
        <div class="bg-white border border-[#E4E8EE] rounded-xl px-3 pt-2 mb-4 flex items-center gap-1 overflow-x-auto">
            @foreach($tabs as $key => $label)
                @php $active = $statusFilter === $key; @endphp
                <a href="{{ route('buyer.orders.index', $key !== '' ? ['status' => $key] : []) }}"
                   @click="switchingTab = true"
                   class="shrink-0 px-3 py-2.5 text-xs font-semibold border-b-2 transition-colors {{ $active ? 'border-[#0068E1] text-[#0068E1]' : 'border-transparent text-[#6B7280] hover:text-[#111827]' }}">
                    {{ $label }}
                    @if($key !== '' && isset($statusCounts[$key]))
                        <span class="ml-1 font-mono-tabular text-[10px] px-1.5 py-0.5 rounded-full {{ $active ? 'bg-[#EFF6FF] text-[#0068E1]' : 'bg-[#F1F5F9] text-[#4B5563]' }}">{{ $statusCounts[$key] }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- Light-Mode Skeleton Table During Tab Transition --}}
        <div x-show="switchingTab" x-cloak>
            <x-skeleton.table-rows :rows="5" :cols="6" />
        </div>

        {{-- Orders List --}}
        <div x-show="!switchingTab">
            @if($orders->isEmpty())
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter">
                    <p class="text-sm font-bold text-[#111827]">Belum ada pesanan pada kategori ini.</p>
                    <p class="text-xs text-[#6B7280] mt-1">Jelajahi katalog game untuk memulai transaksi aman ber-escrow.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($orders as $idx => $order)
                        @php
                            $statusPill = match ($order->status) {
                                'COMPLETED' => 'bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]',
                                'DELIVERED' => 'bg-[#EFF6FF] text-[#0068E1] border-[#BFDBFE]',
                                'PROCESSING' => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
                                'DISPUTED' => 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]',
                                'REFUNDED' => 'bg-[#F1F5F9] text-[#4B5563] border-[#CBD5E1]',
                                default => 'bg-[#F8FAFC] text-[#4B5563] border-[#E4E8EE]',
                            };
                            $isLive = in_array($order->status, ['PROCESSING', 'DISPUTED'], true);
                        @endphp
                        <div style="--stagger-index: {{ min($idx, 8) }}"
                             class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate animate-enter flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                            <div class="space-y-2 flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="font-mono-tabular font-bold text-[#111827]">{{ $order->order_code }}</span>
                                    <span class="{{ $isLive ? 'badge-live-pulse' : 'inline-flex' }} px-2 py-0.5 rounded-full border text-[10px] font-bold {{ $statusPill }}">
                                        {{ $order->status }}
                                    </span>
                                    <span class="px-2 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563] text-[10px] font-semibold uppercase">{{ $order->delivery_method }}</span>
                                    <span class="text-[#6B7280]">Toko: <strong class="text-[#111827]">{{ $order->sellerProfile?->store_name }}</strong></span>
                                </div>

                                <div class="space-y-0.5">
                                    @foreach($order->items as $item)
                                        <div class="text-sm font-semibold text-[#111827] truncate">
                                            {{ $item->product_title_snapshot }}
                                            <span class="font-mono-tabular text-xs font-normal text-[#6B7280]">&times;{{ $item->quantity }}</span>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex flex-wrap items-center gap-3 text-[11px] text-[#6B7280]">
                                    <span class="font-mono-tabular">Invoice: {{ $order->paymentInvoice?->invoice_code }}</span>
                                    @if($order->verified_game_nickname)
                                        <span class="text-[#047857] font-semibold">Nick: {{ $order->verified_game_nickname }}</span>
                                    @endif
                                    @if($order->status === 'PROCESSING' && $order->sla_deadline_at)
                                        <span x-data="liveCountdown('{{ $order->sla_deadline_at->toIso8601String() }}')"
                                              class="badge-live-pulse font-mono-tabular font-bold"
                                              :class="expired ? 'text-[#DC2626]' : 'text-[#C2410C]'">
                                            SLA: <span x-text="expired ? 'Lewat Batas (Refund Siap)' : formatted"></span>
                                        </span>
                                    @endif
                                    @if($order->status === 'DELIVERED' && $order->auto_complete_at)
                                        <span x-data="liveCountdown('{{ $order->auto_complete_at->toIso8601String() }}')" class="font-mono-tabular text-[#047857] font-semibold">
                                            Auto-Selesai: <span x-text="formatted"></span>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center justify-between lg:justify-end gap-4 shrink-0 border-t lg:border-t-0 border-[#F1F5F9] pt-3 lg:pt-0">
                                <div class="text-left lg:text-right">
                                    <div class="text-[10px] text-[#6B7280]">Total Escrow</div>
                                    <div class="font-mono-tabular text-base font-extrabold text-[#111827]">Rp {{ number_format((float) $order->gross_amount, 0, ',', '.') }}</div>
                                </div>
                                <a href="{{ route('buyer.orders.show', $order->order_code) }}"
                                   class="btn-tactile px-4 py-2 rounded-lg text-xs font-semibold {{ $order->delivery_method === 'instant_vault' && in_array($order->status, ['DELIVERED', 'COMPLETED']) ? 'bg-[#00AA5B] hover:bg-[#00924E] text-white' : 'bg-[#0068E1] hover:bg-[#0055B8] text-white' }}">
                                    {{ $order->delivery_method === 'instant_vault' && in_array($order->status, ['DELIVERED', 'COMPLETED']) ? 'Buka Brankas' : 'Detail Pesanan' }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
