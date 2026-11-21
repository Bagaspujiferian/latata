@props(['openDisputes' => null, 'pendingWithdrawals' => null, 'pendingKycs' => null])

@php
    $navItems = [
        [
            'route' => 'admin.kyc.index',
            'label' => 'Verifikasi KYC',
            'active' => request()->routeIs('admin.kyc.*'),
            'badge' => $pendingKycs,
            'badgeTone' => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
            'icon' => '<rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M15 10h3"/><path d="M15 14h3"/>',
        ],
        [
            'route' => 'admin.chaos-lab',
            'label' => 'Chaos Lab Workbench',
            'active' => request()->routeIs('admin.chaos-lab'),
            'icon' => '<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>',
        ],
        [
            'route' => 'admin.disputes',
            'label' => 'Meja Sengketa',
            'active' => request()->routeIs('admin.disputes'),
            'badge' => $openDisputes,
            'badgeTone' => 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]',
            'icon' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
        ],
        [
            'route' => 'admin.withdrawals',
            'label' => 'Approval Tarik Saldo',
            'active' => request()->routeIs('admin.withdrawals'),
            'badge' => $pendingWithdrawals,
            'badgeTone' => 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]',
            'icon' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        ],
    ];
@endphp

<aside class="w-full lg:w-60 shrink-0">
    <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 lg:sticky lg:top-20 space-y-4">
        <div class="pb-3.5 border-b border-[#F1F5F9]">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="text-xs font-bold text-[#111827] truncate">Admin Risk &amp; Ops</div>
                    <div class="text-[11px] text-[#6B7280]">Escrow Governance</div>
                </div>
            </div>
        </div>

        <nav class="space-y-1">
            <div class="px-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-[#6B7280]">Console Navigasi</div>
            @foreach($navItems as $item)
                <a href="{{ route($item['route']) }}"
                   class="btn-tactile flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-[13px] font-medium {{ $item['active'] ? 'bg-[#EFF6FF] text-[#0068E1] font-semibold border border-[#BFDBFE]' : 'text-[#4B5563] hover:bg-[#F8FAFC] hover:text-[#111827] border border-transparent' }}">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $item['icon'] !!}</svg>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </span>
                    @if(! empty($item['badge']))
                        <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full border {{ $item['badgeTone'] }}">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="pt-3 border-t border-[#F1F5F9] text-[11px] text-[#6B7280] space-y-1.5">
            <div class="font-semibold text-[#111827]">Guardrail Engine</div>
            <div class="flex items-center gap-1.5 text-[#047857]">
                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                <span>InnoDB Row Locks Active</span>
            </div>
            <div class="flex items-center gap-1.5 text-[#047857]">
                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                <span>SHA-512 &amp; Blind-Hash Verified</span>
            </div>
        </div>
    </div>
</aside>
