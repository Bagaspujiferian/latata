@extends('layouts.app')

@section('title', 'Pusat Notifikasi — NexaTrade')

@section('content')
    <div class="max-w-4xl mx-auto space-y-5" x-data="{ loadingTab: false }">
        {{-- Header Bar --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                </div>
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Pusat Notifikasi</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        <strong class="font-mono-tabular text-[#F05A28]">{{ $unreadCount }}</strong> notifikasi belum dibaca di akun Anda.
                    </p>
                </div>
            </div>

            @if($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit"
                            class="btn-tactile px-4 py-2 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>
                        <span>Tandai Semua Dibaca</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- Category Filter Tabs --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-2 flex flex-wrap gap-1.5 animate-enter" style="--stagger-index: 1">
            @php
                $filterTabs = [
                    '' => 'Semua',
                    'order' => 'Pesanan',
                    'escrow' => 'Escrow',
                    'chat' => 'Chat',
                    'kyc' => 'KYC',
                    'withdrawal' => 'Penarikan',
                ];
            @endphp
            @foreach($filterTabs as $key => $label)
                @php
                    $tabUnread = $key === ''
                        ? $unreadCount
                        : (int) ($categoryCounts->get($key)?->unread ?? 0);
                @endphp
                <a href="{{ route('notifications.index', array_filter(['category' => $key])) }}"
                   @click="loadingTab = true"
                   class="btn-tactile px-3.5 py-2 rounded-lg text-xs font-bold inline-flex items-center gap-1.5 {{ $category === $key ? 'bg-[#0068E1] text-white' : 'text-[#4B5563] hover:bg-[#F8FAFC]' }}">
                    <span>{{ $label }}</span>
                    @if($tabUnread > 0)
                        <span class="font-mono-tabular text-[10px] px-1.5 py-0.2 rounded-full {{ $category === $key ? 'bg-white/20 text-white' : 'bg-[#FFF4ED] text-[#C2410C]' }}">
                            {{ $tabUnread }}
                        </span>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- Light Mode Shimmer Skeleton while Switching Category Tabs --}}
        <div x-show="loadingTab" x-cloak class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4">
            @for($i = 0; $i < 4; $i++)
                <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-lg skeleton-shimmer shrink-0"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3.5 w-48 rounded skeleton-shimmer"></div>
                        <div class="h-3 w-full rounded skeleton-shimmer"></div>
                    </div>
                </div>
            @endfor
        </div>

        {{-- Notification Feed --}}
        <div x-show="!loadingTab" class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 2">
            @if($notifications->isEmpty())
                <div class="p-12 text-center text-xs text-[#6B7280]">
                    <div class="w-12 h-12 rounded-full bg-[#F1F5F9] text-[#6B7280] mx-auto flex items-center justify-center mb-3">
                        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    </div>
                    <div class="text-sm font-bold text-[#111827]">Tidak ada notifikasi di kategori ini</div>
                    <p class="mt-1">Semua pembaruan pesanan, escrow, chat, KYC, dan penarikan Anda akan muncul di sini secara real-time.</p>
                </div>
            @else
                <div class="divide-y divide-[#F1F5F9]">
                    @foreach($notifications as $notif)
                        @php
                            $catMeta = match ($notif->category) {
                                'order' => ['label' => 'Pesanan', 'bg' => 'bg-[#EFF6FF] text-[#0068E1]', 'svg' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1"/>'],
                                'escrow' => ['label' => 'Escrow', 'bg' => 'bg-[#ECFDF5] text-[#00AA5B]', 'svg' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>'],
                                'chat' => ['label' => 'Chat', 'bg' => 'bg-[#EFF6FF] text-[#0068E1]', 'svg' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
                                'kyc' => ['label' => 'KYC', 'bg' => 'bg-[#FFF4ED] text-[#F05A28]', 'svg' => '<rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M15 10h3"/><path d="M15 14h3"/>'],
                                'withdrawal' => ['label' => 'Penarikan', 'bg' => 'bg-[#ECFDF5] text-[#047857]', 'svg' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'],
                                default => ['label' => 'Sistem', 'bg' => 'bg-[#F1F5F9] text-[#4B5563]', 'svg' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>'],
                            };
                        @endphp
                        <div class="row-interactive p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 {{ $notif->isUnread() ? 'bg-[#EFF6FF]/50' : 'bg-white' }}">
                            <div class="flex items-start gap-3.5 min-w-0 flex-1">
                                <span class="w-9 h-9 rounded-lg {{ $catMeta['bg'] }} flex items-center justify-center shrink-0 mt-0.5">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $catMeta['svg'] !!}</svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($notif->isUnread())
                                            <span class="w-2 h-2 rounded-full bg-[#0068E1] shrink-0"></span>
                                        @endif
                                        <span class="text-xs font-bold text-[#111827]">{{ $notif->title }}</span>
                                        <span class="px-1.5 py-0.2 rounded text-[10px] font-bold uppercase {{ $catMeta['bg'] }}">{{ $catMeta['label'] }}</span>
                                    </div>
                                    <p class="text-xs text-[#4B5563] mt-1 leading-relaxed">{{ $notif->body }}</p>
                                    <div class="font-mono-tabular text-[10px] text-[#6B7280] mt-1.5">
                                        {{ $notif->created_at?->format('d M Y, H:i') }} ({{ $notif->created_at?->diffForHumans() }})
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                @if($notif->action_url)
                                    <form method="POST" action="{{ route('notifications.read', $notif->id) }}">
                                        @csrf
                                        <input type="hidden" name="go" value="1">
                                        <button type="submit"
                                                class="btn-tactile px-3 py-1.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold cursor-pointer">
                                            Buka
                                        </button>
                                    </form>
                                @elseif($notif->isUnread())
                                    <form method="POST" action="{{ route('notifications.read', $notif->id) }}">
                                        @csrf
                                        <button type="submit"
                                                class="btn-tactile px-3 py-1.5 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#E4E8EE] text-[#374151] text-xs font-semibold cursor-pointer">
                                            Tandai Dibaca
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
