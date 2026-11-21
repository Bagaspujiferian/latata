@extends('layouts.app')

@section('title', 'TokoKu Seller Studio — NexaTrade')

@section('content')
    @php $profile = $user->sellerProfile; @endphp

    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$profile" :activeQueueCount="$activeQueueCount" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Top Studio Header Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-extrabold text-[#111827]">{{ $profile?->store_name }}</h1>
                        @if($profile?->is_sla_10m_badge)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#FFF4ED] text-[#C2410C] border border-[#FED7AA] text-[11px] font-bold">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                <span>Lencana 10 Menit Aktif</span>
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-[#6B7280] mt-1">{{ $profile?->store_tagline }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('seller.store.toggle') }}">
                        @csrf
                        <button type="submit"
                                class="btn-tactile px-3.5 py-2 rounded-lg text-xs font-semibold border cursor-pointer inline-flex items-center gap-1.5 {{ ($profile?->is_store_open ?? true) ? 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857] hover:bg-[#D1FAE5]' : 'bg-[#FEF2F2] border-[#FECACA] text-[#B91C1C] hover:bg-[#FEE2E2]' }}">
                            <span class="w-2 h-2 rounded-full {{ ($profile?->is_store_open ?? true) ? 'bg-[#00AA5B]' : 'bg-[#DC2626]' }}"></span>
                            <span>{{ ($profile?->is_store_open ?? true) ? 'Toko Buka (Klik Tutup)' : 'Toko Tutup (Klik Buka)' }}</span>
                        </button>
                    </form>
                    <a href="{{ route('seller.products') }}" class="btn-tactile px-3.5 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold">
                        Kelola Produk &amp; Brankas
                    </a>
                </div>
            </div>

            {{-- 4 Crisp KPI Metric Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate animate-enter" style="--stagger-index: 1">
                    <div class="flex items-center justify-between text-xs text-[#6B7280]">
                        <span>Pending Escrow</span>
                        <span class="w-7 h-7 rounded-lg bg-[#FFF4ED] text-[#F05A28] flex items-center justify-center">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                    </div>
                    <div class="font-mono-tabular text-xl font-extrabold text-[#111827] mt-2">
                        Rp {{ number_format((float) ($user->wallet?->pending_balance ?? 0), 0, ',', '.') }}
                    </div>
                    <div class="text-[11px] text-[#6B7280] mt-1">Tertahan aman hingga selesai</div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate animate-enter" style="--stagger-index: 2">
                    <div class="flex items-center justify-between text-xs text-[#6B7280]">
                        <span>Saldo Aktif</span>
                        <span class="w-7 h-7 rounded-lg bg-[#ECFDF5] text-[#00AA5B] flex items-center justify-center">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                        </span>
                    </div>
                    <div class="font-mono-tabular text-xl font-extrabold text-[#047857] mt-2">
                        Rp {{ number_format((float) ($user->wallet?->available_balance ?? 0), 0, ',', '.') }}
                    </div>
                    <a href="{{ route('seller.wallet') }}" class="inline-block text-[11px] font-semibold text-[#0068E1] hover:underline mt-1">Tarik ke Bank &rarr;</a>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate animate-enter" style="--stagger-index: 3">
                    <div class="flex items-center justify-between text-xs text-[#6B7280]">
                        <span>Rata-Rata Kirim</span>
                        <span class="w-7 h-7 rounded-lg bg-[#EFF6FF] text-[#0068E1] flex items-center justify-center">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </span>
                    </div>
                    <div class="font-mono-tabular text-xl font-extrabold text-[#111827] mt-2">
                        {{ number_format((float) ($profile?->avg_delivery_minutes ?? 0), 1) }} <span class="text-xs font-medium text-[#6B7280]">menit</span>
                    </div>
                    <div class="text-[11px] text-[#6B7280] mt-1">Selesai {{ $profile?->total_completed_orders }} &middot; Batal {{ $profile?->total_cancelled_orders }}</div>
                </div>

                <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 card-elevate animate-enter" style="--stagger-index: 4">
                    <div class="flex items-center justify-between text-xs text-[#6B7280]">
                        <span>Status Lencana 10m</span>
                        <span class="w-7 h-7 rounded-lg bg-[#FFF4ED] text-[#F05A28] flex items-center justify-center">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        </span>
                    </div>
                    <div class="text-lg font-extrabold mt-2 {{ $profile?->is_sla_10m_badge ? 'text-[#047857]' : 'text-[#C2410C]' }}">
                        {{ $profile?->is_sla_10m_badge ? 'Aktif' : 'Belum Aktif' }}
                    </div>
                    <div class="text-[11px] mt-1 {{ $disputedCount > 0 ? 'text-[#DC2626] font-semibold' : 'text-[#6B7280]' }}">
                        Kredit Sundul: {{ $profile?->ad_credits ?? 0 }} &middot; Sengketa: {{ $disputedCount }}
                    </div>
                </div>
            </div>

            {{-- Priority Queue & Top Products --}}
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5">
                <div class="xl:col-span-7 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 5">
                    <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-sm font-bold text-[#111827]">Antrean Pesanan Prioritas SLA</h2>
                        <a href="{{ route('seller.orders') }}" class="text-xs font-semibold text-[#0068E1] hover:underline">Lihat Semua &rarr;</a>
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @forelse($recentOrders as $ord)
                            <div class="row-interactive p-4 flex items-center justify-between gap-3 text-xs">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono-tabular font-bold text-[#111827]">{{ $ord->order_code }}</span>
                                        <span class="{{ in_array($ord->status, ['PROCESSING', 'DISPUTED'], true) ? 'badge-live-pulse' : 'inline-flex' }} px-2 py-0.5 rounded-full text-[10px] font-bold {{ $ord->status === 'PROCESSING' ? 'bg-[#FFF4ED] text-[#C2410C]' : ($ord->status === 'DISPUTED' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#ECFDF5] text-[#047857]') }}">
                                            {{ $ord->status }}
                                        </span>
                                        <span class="text-[#6B7280]">{{ $ord->delivery_method }}</span>
                                    </div>
                                    <div class="font-semibold text-[#111827] truncate mt-1">{{ $ord->items->first()?->product_title_snapshot }}</div>
                                    <div class="text-[#6B7280]">Pembeli: {{ $ord->buyer?->name }}</div>
                                </div>
                                <div class="text-right font-mono-tabular shrink-0">
                                    <div class="font-bold text-[#047857]">Rp {{ number_format((float) $ord->seller_escrow_amount, 0, ',', '.') }}</div>
                                    @if($ord->status === 'PROCESSING' && $ord->sla_deadline_at)
                                        <div x-data="liveCountdown('{{ $ord->sla_deadline_at->toIso8601String() }}')" class="badge-live-pulse text-[11px] font-bold mt-0.5" :class="expired ? 'text-[#DC2626]' : 'text-[#C2410C]'">
                                            <span x-text="formatted"></span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="p-6 text-xs text-[#6B7280]">Belum ada pesanan masuk.</p>
                        @endforelse
                    </div>
                </div>

                <div class="xl:col-span-5 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 6">
                    <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-sm font-bold text-[#111827]">Produk Terlaris Toko</h2>
                        <a href="{{ route('seller.products') }}" class="text-xs font-semibold text-[#0068E1] hover:underline">Kelola &rarr;</a>
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @foreach($topProducts as $prod)
                            <div class="row-interactive p-4 flex items-center justify-between gap-3 text-xs">
                                <div class="min-w-0">
                                    <div class="font-mono-tabular text-[10px] text-[#6B7280]">{{ $prod->sku_code }} &middot; {{ $prod->gameCategory->delivery_method }}</div>
                                    <div class="font-semibold text-[#111827] truncate mt-0.5">{{ $prod->title }}</div>
                                    <div class="text-[11px] text-[#6B7280] mt-0.5">Terjual {{ $prod->sold_count }} &middot; Stok {{ $prod->stock }}</div>
                                </div>
                                <div class="font-mono-tabular font-bold text-[#111827] shrink-0">
                                    Rp {{ number_format((float) $prod->price, 0, ',', '.') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
