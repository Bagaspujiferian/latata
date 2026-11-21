@props(['profile' => null, 'activeQueueCount' => null])

@php
    $navItems = [
        [
            'route' => 'seller.dashboard',
            'label' => 'Ringkasan Toko',
            'active' => request()->routeIs('seller.dashboard'),
            'icon' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        ],
        [
            'route' => 'seller.products',
            'label' => 'Produk, Galeri & Brankas',
            'active' => request()->routeIs('seller.products'),
            'icon' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        ],
        [
            'route' => 'seller.orders',
            'label' => 'Antrean Pesanan SLA',
            'active' => request()->routeIs('seller.orders'),
            'badge' => $activeQueueCount,
            'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        ],
        [
            'route' => 'seller.wallet',
            'label' => 'Dompet & Tarik Saldo',
            'active' => request()->routeIs('seller.wallet'),
            'icon' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        ],
        [
            'route' => 'seller.bank-accounts',
            'label' => 'Rekening Bank Tersimpan',
            'active' => request()->routeIs('seller.bank-accounts') || request()->routeIs('wallet.bank-accounts'),
            'icon' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        ],
        [
            'route' => 'inbox.index',
            'label' => 'Inbox Chat Pembeli',
            'active' => request()->routeIs('inbox.index'),
            'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        ],
        [
            'route' => 'seller.settings',
            'label' => 'Jam Buka, Profil & Ulasan',
            'active' => request()->routeIs('seller.settings'),
            'icon' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        ],
    ];
    $effectiveOpen = $profile ? $profile->isOpenForManualTradeNow() : true;
@endphp

<aside class="w-full lg:w-60 shrink-0">
    <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 lg:sticky lg:top-20 space-y-4">
        {{-- Store Identity --}}
        <div class="pb-3.5 border-b border-[#F1F5F9]">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-bold text-sm flex items-center justify-center shrink-0">
                    {{ strtoupper(substr($profile?->store_name ?? 'TK', 0, 2)) }}
                </div>
                <div class="min-w-0">
                    <div class="text-xs font-bold text-[#111827] truncate">{{ $profile?->store_name ?? 'TokoKu Seller' }}</div>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="inline-block w-1.5 h-1.5 rounded-full {{ $effectiveOpen ? 'bg-[#00AA5B]' : 'bg-[#DC2626]' }}"></span>
                        <span class="text-[11px] text-[#6B7280]">{{ $effectiveOpen ? 'Toko Buka' : 'Toko Tutup' }}</span>
                        <span class="text-[10px] font-semibold px-1.5 py-0.2 bg-[#F1F5F9] text-[#4B5563] rounded">Lv.{{ $profile?->store_level ?? 1 }}</span>
                    </div>
                </div>
            </div>

            @if($profile)
                <a href="{{ route('stores.show', $profile->slug) }}" class="mt-2.5 inline-flex items-center gap-1 text-[11px] font-semibold text-[#0068E1] hover:underline">
                    <span>Lihat Halaman Etalase Toko</span>
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
                </a>
            @endif
        </div>

        {{-- Navigation --}}
        <nav class="space-y-1">
            <div class="px-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-[#6B7280]">TokoKu Studio</div>
            @foreach($navItems as $item)
                <a href="{{ route($item['route']) }}"
                   class="btn-tactile flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-[13px] font-medium {{ $item['active'] ? 'bg-[#EFF6FF] text-[#0068E1] font-semibold border border-[#BFDBFE]' : 'text-[#4B5563] hover:bg-[#F8FAFC] hover:text-[#111827] border border-transparent' }}">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $item['icon'] !!}</svg>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </span>
                    @if(! empty($item['badge']))
                        <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#FFF4ED] text-[#C2410C] border border-[#FED7AA]">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- SLA Badge Mini Status --}}
        <div class="pt-3 border-t border-[#F1F5F9]">
            @if($profile?->is_sla_10m_badge)
                <div class="p-2.5 rounded-lg bg-[#FFF4ED] border border-[#FED7AA] text-[11px] text-[#C2410C]">
                    <div class="flex items-center gap-1.5 font-bold">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>10 Menit Kirim Aktif</span>
                    </div>
                    <p class="text-[10px] mt-0.5 text-[#9A3412]">Rata-rata {{ number_format((float) $profile->avg_delivery_minutes, 1) }} menit</p>
                </div>
            @else
                <div class="p-2.5 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] text-[11px] text-[#4B5563]">
                    <div class="font-semibold text-[#111827]">Target 10 Menit Kirim</div>
                    <p class="text-[10px] mt-0.5">Rata-rata saat ini {{ number_format((float) ($profile?->avg_delivery_minutes ?? 0), 1) }} menit.</p>
                </div>
            @endif
        </div>
    </div>
</aside>
