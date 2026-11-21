<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'NexaTrade — Marketplace Digital Gaming & Escrow')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#F4F6F9] text-[#111827] antialiased selection:bg-[#0068E1] selection:text-white">
    {{-- ===================================================================== --}}
    {{-- SLIM TRUST-BLUE TOP NAVIGATION PROGRESS BAR                           --}}
    {{-- ===================================================================== --}}
    <div x-data="navProgressBar()" class="nav-progress-bar" aria-hidden="true">
        <div class="nav-progress-bar__fill"
             :style="`transform: scaleX(${progress}); opacity: ${visible ? 1 : 0};`"></div>
    </div>

    {{-- ===================================================================== --}}
    {{-- 1. TOP TRUST MICRO-BAR (#F8FAFC, h-8)                                 --}}
    {{-- ===================================================================== --}}
    <div class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-xs text-[#4B5563]">
        <div class="max-w-[1320px] mx-auto px-4 sm:px-6 h-8 flex items-center justify-between gap-4">
            <div class="flex items-center gap-1.5 min-w-0 truncate">
                <svg class="w-3.5 h-3.5 text-[#00AA5B] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                <span class="truncate">
                    <strong class="font-semibold text-[#111827]">NexaTrade Safe Trading</strong> — Garansi 100% Uang Kembali via Escrow Otomatis
                </span>
            </div>

            <div class="hidden sm:flex items-center gap-4 shrink-0">
                <a href="{{ route('marketplace.index') }}" class="hover:text-[#0068E1] transition-colors">Pusat Bantuan</a>
                @if($currentUser?->isBuyer())
                    <a href="{{ route('buyer.open-store') }}" class="font-semibold text-[#00AA5B] hover:underline inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>
                        <span>Buka Toko</span>
                    </a>
                @endif
                @if($currentUser?->isSeller())
                    <a href="{{ route('seller.dashboard') }}" class="font-medium text-[#0068E1] hover:underline">TokoKu (Seller Center)</a>
                @endif
                @if($currentUser?->isAdmin())
                    <a href="{{ route('admin.finance.index') }}" class="font-semibold text-[#00AA5B] hover:underline">Finance P&amp;L</a>
                    <a href="{{ route('admin.cms.catalog') }}" class="font-medium text-[#0068E1] hover:underline">CMS Katalog</a>
                    <a href="{{ route('admin.warranties.index') }}" class="font-medium text-[#0068E1] hover:underline">Klaim Garansi</a>
                    <a href="{{ route('admin.kyc.index') }}" class="font-medium text-[#0068E1] hover:underline">Verifikasi KYC</a>
                    <a href="{{ route('admin.chaos-lab') }}" class="font-medium text-[#0068E1] hover:underline">Admin &amp; Chaos Lab</a>
                @endif
            </div>
        </div>
    </div>

    {{-- ===================================================================== --}}
    {{-- 2. MAIN STICKY WHITE NAVBAR (#FFFFFF, h-16)                           --}}
    {{-- ===================================================================== --}}
    <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-[#E4E8EE]">
        <div class="max-w-[1320px] mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3 sm:gap-6">
            {{-- Left: Clean Geometric Brand Mark + Wordmark --}}
            <a href="{{ route('marketplace.index') }}" class="flex items-center gap-2.5 shrink-0 group">
                <div class="w-9 h-9 rounded-lg bg-[#0068E1] text-white flex items-center justify-center transition-transform duration-150 group-hover:bg-[#0055B8] group-active:scale-95">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 18V6l9 12V6"/>
                        <path d="M18 6v12"/>
                    </svg>
                </div>
                <div class="leading-none">
                    <div class="text-[17px] font-extrabold tracking-tight">
                        <span class="text-[#0068E1]">Nexa</span><span class="text-[#111827]">Trade</span>
                    </div>
                    <div class="text-[10px] font-medium text-[#6B7280] mt-0.5 hidden sm:block">Gaming Escrow Market</div>
                </div>
            </a>

            {{-- Center: Tokopedia/Itemku Wide Search Bar --}}
            <form method="GET" action="{{ route('marketplace.index') }}" class="flex-1 max-w-xl hidden md:block">
                <div class="relative">
                    <svg class="w-4 h-4 text-[#6B7280] absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                    </svg>
                    <input type="text" name="q" value="{{ request('q') }}"
                           placeholder="Cari Diamond MLBB, Voucher Steam, Akun Valorant, BGL Growtopia..."
                           class="w-full h-10 pl-10 pr-20 text-[13px] bg-[#F3F5F8] text-[#111827] placeholder-[#6B7280] border border-transparent rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none focus:ring-2 focus:ring-[#0068E1]/15 transition-all">
                    <button type="submit" class="btn-tactile absolute right-1.5 top-1/2 -translate-y-1/2 px-3 py-1 bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold rounded-md cursor-pointer">
                        Cari
                    </button>
                </div>
            </form>

            {{-- Right: Notification Bell, Inbox Chat, Wishlist, Cart Badge, Wallet Balance Pill & Account Dropdown --}}
            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                @if($currentUser)
                    @php
                        $bellUnread = (int) ($notificationBell['unread_count'] ?? 0);
                        $bellItems = $notificationBell['items'] ?? collect();
                    @endphp

                    {{-- Notification Bell Popover --}}
                    <div class="relative" x-data="{
                        bellOpen: false,
                        unread: {{ $bellUnread }},
                        async markAllRead() {
                            try {
                                const res = await fetch('{{ route('notifications.read-all') }}', {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                        'Accept': 'application/json',
                                    },
                                });
                                if (res.ok) {
                                    this.unread = 0;
                                    this.$el.querySelectorAll('[data-notif-dot]').forEach((el) => el.remove());
                                    this.$el.querySelectorAll('[data-notif-row]').forEach((el) => el.classList.remove('bg-[#EFF6FF]/60'));
                                }
                            } catch (e) {}
                        }
                    }">
                        <button type="button" @click="bellOpen = !bellOpen" title="Notifikasi"
                                class="btn-tactile relative w-10 h-10 rounded-lg border border-[#E4E8EE] bg-white hover:bg-[#F8FAFC] hover:border-[#CBD5E1] flex items-center justify-center text-[#111827] cursor-pointer">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                            </svg>
                            <span x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread"
                                  class="font-mono-tabular absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 rounded-full bg-[#F05A28] text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                                {{ $bellUnread }}
                            </span>
                        </button>

                        <div x-show="bellOpen" x-cloak @click.outside="bellOpen = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute right-0 mt-2 w-80 sm:w-96 bg-white border border-[#E4E8EE] rounded-xl shadow-[0_14px_34px_-8px_rgba(15,23,42,0.18)] overflow-hidden z-50">
                            <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-[#111827]">Notifikasi</span>
                                    <span x-show="unread > 0" x-cloak x-text="unread + ' baru'"
                                          class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#FFF4ED] text-[#C2410C] border border-[#FED7AA]"></span>
                                </div>
                                <button type="button" @click="markAllRead()" x-show="unread > 0" x-cloak
                                        class="text-[11px] font-semibold text-[#0068E1] hover:underline cursor-pointer">
                                    Tandai Semua Dibaca
                                </button>
                            </div>

                            <div class="max-h-80 overflow-y-auto divide-y divide-[#F1F5F9]">
                                @forelse($bellItems as $notif)
                                    @php
                                        $catStyle = match ($notif->category) {
                                            'order' => ['bg' => 'bg-[#EFF6FF] text-[#0068E1]', 'svg' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1"/>'],
                                            'escrow' => ['bg' => 'bg-[#ECFDF5] text-[#00AA5B]', 'svg' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>'],
                                            'chat' => ['bg' => 'bg-[#EFF6FF] text-[#0068E1]', 'svg' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
                                            'kyc' => ['bg' => 'bg-[#FFF4ED] text-[#F05A28]', 'svg' => '<rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M15 10h3"/><path d="M15 14h3"/>'],
                                            'withdrawal' => ['bg' => 'bg-[#ECFDF5] text-[#047857]', 'svg' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'],
                                            'warranty' => ['bg' => 'bg-[#ECFDF5] text-[#00AA5B]', 'svg' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/>'],
                                            'promo' => ['bg' => 'bg-[#FFF4ED] text-[#F05A28]', 'svg' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>'],
                                            'moderation' => ['bg' => 'bg-[#FEF2F2] text-[#DC2626]', 'svg' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>'],
                                            default => ['bg' => 'bg-[#F1F5F9] text-[#4B5563]', 'svg' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>'],
                                        };
                                    @endphp
                                    <form method="POST" action="{{ route('notifications.read', $notif->id) }}">
                                        @csrf
                                        <input type="hidden" name="go" value="1">
                                        <button type="submit" data-notif-row
                                                class="w-full text-left px-4 py-3 hover:bg-[#F8FAFC] transition-colors flex items-start gap-3 cursor-pointer {{ $notif->isUnread() ? 'bg-[#EFF6FF]/60' : '' }}">
                                            <span class="w-8 h-8 rounded-lg {{ $catStyle['bg'] }} flex items-center justify-center shrink-0 mt-0.5">
                                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $catStyle['svg'] !!}</svg>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-xs font-bold text-[#111827] truncate">{{ $notif->title }}</span>
                                                    @if($notif->isUnread())
                                                        <span data-notif-dot class="w-2 h-2 rounded-full bg-[#0068E1] shrink-0"></span>
                                                    @endif
                                                </div>
                                                <p class="text-[11px] text-[#4B5563] line-clamp-2 mt-0.5 leading-snug">{{ $notif->body }}</p>
                                                <div class="text-[10px] text-[#6B7280] mt-1 font-mono-tabular">{{ $notif->created_at?->diffForHumans() }}</div>
                                            </div>
                                        </button>
                                    </form>
                                @empty
                                    <div class="p-8 text-center text-xs text-[#6B7280]">
                                        Belum ada notifikasi terbaru.
                                    </div>
                                @endforelse
                            </div>

                            <div class="px-4 py-2.5 bg-[#F8FAFC] border-t border-[#E4E8EE] text-center">
                                <a href="{{ route('notifications.index') }}" class="text-xs font-bold text-[#0068E1] hover:underline">
                                    Lihat Semua Notifikasi
                                </a>
                            </div>
                        </div>
                    </div>

                    {{-- Inbox Chat Icon Button --}}
                    <a href="{{ route('inbox.index') }}" title="Chat &amp; Pesan Masuk"
                       class="btn-tactile relative w-10 h-10 rounded-lg border border-[#E4E8EE] bg-white hover:bg-[#F8FAFC] hover:border-[#CBD5E1] flex items-center justify-center text-[#111827]">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        @if(($inboxUnreadCount ?? 0) > 0)
                            <span class="font-mono-tabular absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 rounded-full bg-[#F05A28] text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                                {{ $inboxUnreadCount }}
                            </span>
                        @endif
                    </a>

                    {{-- Wishlist Icon Button --}}
                    <a href="{{ route('buyer.wishlist') }}" title="Wishlist Produk"
                       class="btn-tactile relative w-10 h-10 rounded-lg border border-[#E4E8EE] bg-white hover:bg-[#F8FAFC] hover:border-[#CBD5E1] flex items-center justify-center text-[#111827]">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                        </svg>
                        @if(($wishlistCount ?? 0) > 0)
                            <span class="font-mono-tabular absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 rounded-full bg-[#0068E1] text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                                {{ $wishlistCount }}
                            </span>
                        @endif
                    </a>

                    {{-- Cart Icon Button --}}
                    <a href="{{ route('cart.index') }}" title="Keranjang Belanja"
                       class="btn-tactile relative w-10 h-10 rounded-lg border border-[#E4E8EE] bg-white hover:bg-[#F8FAFC] hover:border-[#CBD5E1] flex items-center justify-center text-[#111827]">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>
                            <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
                        </svg>
                        @if(($cartCount ?? 0) > 0)
                            <span class="font-mono-tabular absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 rounded-full bg-[#F05A28] text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                                {{ $cartCount }}
                            </span>
                        @endif
                    </a>

                    {{-- Crisp Wallet Balance Pill --}}
                    <a href="{{ $currentUser->isSeller() ? route('seller.wallet') : route('buyer.wallet') }}"
                       class="btn-tactile hidden sm:inline-flex items-center gap-2 h-10 px-3 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] hover:bg-[#D1FAE5] text-[#047857]">
                        <svg class="w-4 h-4 text-[#00AA5B] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/>
                            <path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>
                        </svg>
                        <div class="leading-tight text-left">
                            <div class="text-[9px] font-semibold uppercase tracking-wide text-[#047857]/80">Saldo Aktif</div>
                            <div class="font-mono-tabular text-xs font-bold text-[#047857]">
                                Rp {{ number_format((float) ($currentUser->wallet?->available_balance ?? 0), 0, ',', '.') }}
                            </div>
                        </div>
                    </a>

                    {{-- Account Dropdown --}}
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" @click="open = !open"
                                class="btn-tactile h-10 pl-2 pr-3 rounded-lg border border-[#E4E8EE] bg-white hover:bg-[#F8FAFC] hover:border-[#CBD5E1] flex items-center gap-2 cursor-pointer">
                            <span class="w-7 h-7 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-bold text-xs flex items-center justify-center">
                                {{ strtoupper(substr($currentUser->name, 0, 2)) }}
                            </span>
                            <div class="hidden lg:block text-left leading-tight max-w-[120px]">
                                <div class="text-xs font-semibold text-[#111827] truncate">{{ $currentUser->name }}</div>
                                <div class="text-[10px] font-medium text-[#6B7280] uppercase">{{ $currentUser->role }}</div>
                            </div>
                            <svg class="w-4 h-4 text-[#6B7280] transition-transform duration-150" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6"/>
                            </svg>
                        </button>

                        <div x-show="open" x-cloak @click.outside="open = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute right-0 mt-2 w-64 bg-white border border-[#E4E8EE] rounded-xl shadow-lg py-2 z-50">
                            <div class="px-3.5 py-2.5 border-b border-[#F1F5F9]">
                                <div class="text-xs font-bold text-[#111827] truncate">{{ $currentUser->name }}</div>
                                <div class="text-[11px] text-[#6B7280] truncate">{{ $currentUser->email }}</div>
                                <div class="mt-2 grid grid-cols-2 gap-1.5 font-mono-tabular text-[11px]">
                                    <div class="px-2 py-1 rounded bg-[#ECFDF5] text-[#047857]">
                                        <div class="text-[9px] uppercase opacity-75">Aktif</div>
                                        <div class="font-bold">Rp {{ number_format((float) ($currentUser->wallet?->available_balance ?? 0), 0, ',', '.') }}</div>
                                    </div>
                                    <div class="px-2 py-1 rounded bg-[#FFF4ED] text-[#C2410C]">
                                        <div class="text-[9px] uppercase opacity-75">Escrow</div>
                                        <div class="font-bold">Rp {{ number_format((float) ($currentUser->wallet?->pending_balance ?? 0), 0, ',', '.') }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="py-1.5 text-[13px] font-medium text-[#111827]">
                                <a href="{{ route('buyer.orders.index') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1"/></svg>
                                    <span>Pesanan Saya</span>
                                </a>
                                <a href="{{ route('buyer.warranties') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#ECFDF5] hover:text-[#047857]">
                                    <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                                    <span>Garansi Anti-Hackback</span>
                                </a>
                                <a href="{{ route('buyer.wishlist') }}" class="flex items-center justify-between px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <span class="flex items-center gap-2.5">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                                        <span>Wishlist Tersimpan</span>
                                    </span>
                                    @if(($wishlistCount ?? 0) > 0)
                                        <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#EFF6FF] text-[#0068E1]">{{ $wishlistCount }}</span>
                                    @endif
                                </a>
                                <a href="{{ route('inbox.index') }}" class="flex items-center justify-between px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <span class="flex items-center gap-2.5">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <span>Inbox Chat</span>
                                    </span>
                                    @if(($inboxUnreadCount ?? 0) > 0)
                                        <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#FFF4ED] text-[#C2410C]">{{ $inboxUnreadCount }}</span>
                                    @endif
                                </a>
                                <a href="{{ route('notifications.index') }}" class="flex items-center justify-between px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <span class="flex items-center gap-2.5">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                                        <span>Pusat Notifikasi</span>
                                    </span>
                                    @if(($notificationBell['unread_count'] ?? 0) > 0)
                                        <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#FFF4ED] text-[#C2410C]">{{ $notificationBell['unread_count'] }}</span>
                                    @endif
                                </a>
                                <a href="{{ route('buyer.wallet') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                                    <span>Dompet &amp; Buku Besar</span>
                                </a>
                                <a href="{{ route('wallet.bank-accounts') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                                    <span>Rekening Bank Tersimpan</span>
                                </a>
                                <a href="{{ route('account.security') }}" class="flex items-center justify-between px-3.5 py-2 hover:bg-[#F8FAFC] hover:text-[#0068E1]">
                                    <span class="flex items-center gap-2.5">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        <span>Keamanan &amp; PIN</span>
                                    </span>
                                    @if($currentUser->hasWalletPin())
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-[#ECFDF5] text-[#047857]">PIN AKTIF</span>
                                    @endif
                                </a>
                                @if($currentUser->isBuyer())
                                    <a href="{{ route('buyer.open-store') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#ECFDF5] text-[#047857] font-semibold">
                                        <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>
                                        <span>Buka Toko (Verifikasi KYC)</span>
                                    </a>
                                @endif

                                @if($currentUser->isSeller())
                                    <div class="my-1 border-t border-[#F1F5F9]"></div>
                                    <a href="{{ route('seller.dashboard') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#EFF6FF] text-[#0068E1] font-semibold">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>
                                        <span>TokoKu (Seller Studio)</span>
                                    </a>
                                    <a href="{{ route('seller.products') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>
                                        <span>Produk &amp; Brankas AES-256</span>
                                    </a>
                                    <a href="{{ route('seller.orders') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <span>Antrean Pesanan SLA</span>
                                    </a>
                                    <a href="{{ route('seller.vouchers') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/></svg>
                                        <span>Voucher Toko</span>
                                    </a>
                                    <a href="{{ route('seller.ad-credits') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                                        <span>Kredit Sundul &amp; CSV</span>
                                    </a>
                                @endif

                                @if($currentUser->isAdmin())
                                    <div class="my-1 border-t border-[#F1F5F9]"></div>
                                    <a href="{{ route('admin.finance.index') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#ECFDF5] text-[#047857] font-semibold">
                                        <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                        <span>Finance P&amp;L &amp; Ledger</span>
                                    </a>
                                    <a href="{{ route('admin.cms.catalog') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#EFF6FF] text-[#0068E1] font-semibold">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
                                        <span>Master Katalog CMS</span>
                                    </a>
                                    <a href="{{ route('admin.cms.promos') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        <span>Promo &amp; Flash Sale</span>
                                    </a>
                                    <a href="{{ route('admin.warranties.index') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                                        <span>Klaim Garansi Hackback</span>
                                    </a>
                                    <a href="{{ route('admin.reports.index') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#DC2626]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>
                                        <span>Laporan Moderasi</span>
                                    </a>
                                    <a href="{{ route('admin.audit-logs') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>
                                        <span>Audit Trail Log</span>
                                    </a>
                                    <a href="{{ route('admin.kyc.index') }}" class="flex items-center justify-between px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <span class="flex items-center gap-2.5">
                                            <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M15 10h3"/><path d="M15 14h3"/></svg>
                                            <span>Verifikasi KYC</span>
                                        </span>
                                        @if(($pendingKycCount ?? 0) > 0)
                                            <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#FFF4ED] text-[#C2410C]">{{ $pendingKycCount }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ route('admin.chaos-lab') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#EFF6FF] text-[#0068E1] font-semibold">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>
                                        <span>Chaos Lab Workbench</span>
                                    </a>
                                    <a href="{{ route('admin.disputes') }}" class="flex items-center justify-between px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <span class="flex items-center gap-2.5">
                                            <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M12 3v18"/></svg>
                                            <span>Meja Sengketa</span>
                                        </span>
                                        @if(($openDisputeCount ?? 0) > 0)
                                            <span class="font-mono-tabular text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#FEF2F2] text-[#B91C1C]">{{ $openDisputeCount }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ route('admin.withdrawals') }}" class="flex items-center gap-2.5 px-3.5 py-2 hover:bg-[#F8FAFC]">
                                        <svg class="w-4 h-4 text-[#6B7280]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                                        <span>Approval Tarik Saldo</span>
                                    </a>
                                @endif
                            </div>

                            <div class="pt-1.5 px-3 border-t border-[#F1F5F9] space-y-1.5">
                                @if($demoSwitcherEnabled ?? false)
                                    <form method="POST" action="{{ route('demo.wallet-topup') }}">
                                        @csrf
                                        <input type="hidden" name="amount" value="250000">
                                        <button type="submit" class="btn-tactile w-full py-1.5 px-3 rounded-lg bg-[#F8FAFC] hover:bg-[#EFF6FF] border border-[#E4E8EE] hover:border-[#BFDBFE] text-xs font-semibold text-[#0068E1] flex items-center justify-center gap-1.5 cursor-pointer">
                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                            <span>Top-Up Demo Rp 250.000</span>
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="btn-tactile w-full py-1.5 px-3 rounded-lg bg-white hover:bg-[#FEF2F2] border border-[#E4E8EE] hover:border-[#FECACA] text-xs font-semibold text-[#DC2626] flex items-center justify-center gap-1.5 cursor-pointer">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                                        <span>Keluar</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}"
                       class="btn-tactile h-10 px-4 rounded-lg border border-[#0068E1] bg-white hover:bg-[#EFF6FF] text-[#0068E1] text-xs font-bold inline-flex items-center">
                        Masuk
                    </a>
                    <a href="{{ route('register') }}"
                       class="btn-tactile h-10 px-4 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center">
                        Daftar
                    </a>
                @endif
            </div>
        </div>

        {{-- Mobile Search Strip --}}
        <div class="md:hidden border-t border-[#F1F5F9] px-4 py-2 bg-white">
            <form method="GET" action="{{ route('marketplace.index') }}" class="relative">
                <svg class="w-4 h-4 text-[#6B7280] absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                </svg>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari game, voucher, atau akun..."
                       class="w-full h-9 pl-9 pr-3 text-xs bg-[#F3F5F8] border border-transparent rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
            </form>
        </div>
    </header>

    {{-- ===================================================================== --}}
    {{-- LIGHT-MODE FLOATING TOAST NOTIFICATIONS                               --}}
    {{-- ===================================================================== --}}
    @php
        $initialToasts = [];
        if (session('success')) {
            $initialToasts[] = ['type' => 'success', 'title' => 'Berhasil', 'message' => session('success')];
        }
        if (session('warning')) {
            $initialToasts[] = ['type' => 'warning', 'title' => 'Peringatan Sistem', 'message' => session('warning')];
        }
        if (session('error')) {
            $initialToasts[] = ['type' => 'error', 'title' => 'Tindakan Diblokir', 'message' => session('error')];
        }
        if ($errors->any()) {
            $initialToasts[] = [
                'type' => 'error',
                'title' => 'Validasi Gagal',
                'message' => 'Mohon periksa kembali kolom berikut:',
                'items' => $errors->all(),
            ];
        }
    @endphp
    <div x-data="toastManager(@js($initialToasts))"
         class="fixed top-20 right-4 sm:right-6 z-50 w-[calc(100vw-2rem)] max-w-sm space-y-2.5 pointer-events-none"
         aria-live="polite">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-show="toast.visible"
                 x-transition:enter="transition ease-out duration-250"
                 x-transition:enter-start="opacity-0 translate-x-6 scale-95"
                 x-transition:enter-end="opacity-100 translate-x-0 scale-100"
                 x-transition:leave="transition ease-in duration-180"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-6"
                 @mouseenter="pause(toast)"
                 @mouseleave="resume(toast)"
                 :class="toast.paused ? 'toast-paused' : ''"
                 class="pointer-events-auto relative overflow-hidden bg-white border border-[#E4E8EE] rounded-xl shadow-[0_10px_30px_-8px_rgba(15,23,42,0.16)] flex">
                {{-- Left Accent Bar --}}
                <div class="w-1 shrink-0"
                     :class="toast.type === 'success' ? 'bg-[#00AA5B]' : (toast.type === 'warning' ? 'bg-[#F05A28]' : 'bg-[#DC2626]')"></div>

                <div class="flex-1 min-w-0">
                    <div class="p-3.5 pr-9 flex items-start gap-2.5 text-xs">
                        {{-- Crisp Lucide Icon --}}
                        <span class="mt-0.5 shrink-0"
                              :class="toast.type === 'success' ? 'text-[#00AA5B]' : (toast.type === 'warning' ? 'text-[#F05A28]' : 'text-[#DC2626]')">
                            <svg x-show="toast.type === 'success'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                            <svg x-show="toast.type === 'warning'" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            <svg x-show="toast.type === 'error'" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-[#111827]" x-text="toast.title"></div>
                            <p class="text-[#4B5563] mt-0.5 leading-relaxed" x-text="toast.message"></p>
                            <template x-if="toast.items && toast.items.length > 0">
                                <ul class="mt-1.5 list-disc pl-4 space-y-0.5 text-[#B91C1C] text-[11px]">
                                    <template x-for="(item, idx) in toast.items" :key="idx">
                                        <li x-text="item"></li>
                                    </template>
                                </ul>
                            </template>
                        </div>
                    </div>

                    <div class="h-0.5 w-full bg-[#F1F5F9] overflow-hidden">
                        <div class="h-full w-full toast-progress-fill"
                             :style="`--toast-duration: ${toast.duration}ms;`"
                             :class="toast.type === 'success' ? 'bg-[#00AA5B]' : (toast.type === 'warning' ? 'bg-[#F05A28]' : 'bg-[#DC2626]')"></div>
                    </div>
                </div>

                <button type="button" @click="dismiss(toast.id)" aria-label="Tutup notifikasi"
                        class="absolute top-2.5 right-2.5 w-6 h-6 flex items-center justify-center rounded-md text-[#6B7280] hover:text-[#111827] hover:bg-[#F1F5F9] transition cursor-pointer">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </template>
    </div>

    {{-- ===================================================================== --}}
    {{-- MAIN WORKSPACE                                                        --}}
    {{-- ===================================================================== --}}
    <main class="max-w-[1320px] mx-auto px-4 sm:px-6 py-6">
        @yield('content')
    </main>

    {{-- ===================================================================== --}}
    {{-- CLEAN MARKETPLACE FOOTER                                              --}}
    {{-- ===================================================================== --}}
    <footer class="mt-14 border-t border-[#E4E8EE] bg-white">
        <div class="max-w-[1320px] mx-auto px-4 sm:px-6 py-7 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 text-xs text-[#6B7280]">
            <div>
                <div class="text-sm font-extrabold tracking-tight">
                    <span class="text-[#0068E1]">Nexa</span><span class="text-[#111827]">Trade</span>
                    <span class="font-medium text-[#6B7280] ml-1.5">Enterprise Escrow Engine</span>
                </div>
                <p class="mt-1">Marketplace Digital Gaming C2C/B2C dengan Brankas AES-256, Buku Besar Double-Entry InnoDB, dan Garansi Pengiriman 10 Menit.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 font-mono-tabular text-[11px] text-[#4B5563]">
                <span class="px-2.5 py-1 bg-[#F8FAFC] border border-[#E4E8EE] rounded-md">MySQL InnoDB Locks</span>
                <span class="px-2.5 py-1 bg-[#F8FAFC] border border-[#E4E8EE] rounded-md">AES-256 + Blind Hash</span>
                <span class="px-2.5 py-1 bg-[#F8FAFC] border border-[#E4E8EE] rounded-md">Midtrans SHA-512</span>
            </div>
        </div>
    </footer>

    {{-- ===================================================================== --}}
    {{-- DISCREET FLOATING DEV ACCOUNT SWITCHER WIDGET (bottom-4 right-4)      --}}
    {{-- ===================================================================== --}}
    @if(($demoSwitcherEnabled ?? false) && ! empty($demoUsers) && $demoUsers->isNotEmpty())
        @php
            $activeShortRole = match ($currentUser?->email) {
                'bagas@nexatrade.id' => 'Buyer 1 · Bagas',
                'proplayer@nexatrade.id' => 'Buyer 2 · Fajar',
                'juragan@nexatrade.id' => 'Seller 1 · Juragan',
                'sultan@nexatrade.id' => 'Seller 2 · Sultan',
                'admin@nexatrade.id' => 'Admin · Chaos Lab',
                default => $currentUser?->name ?? 'Guest',
            };
        @endphp
        <div class="fixed bottom-4 right-4 z-50" x-data="{ open: false }">
            {{-- Expanded White Popover Card --}}
            <div x-show="open" x-cloak @click.outside="open = false"
                 x-transition:enter="transition ease-out duration-180"
                 x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-120"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                 class="mb-2 w-72 bg-white border border-[#E4E8EE] rounded-xl shadow-[0_16px_40px_-10px_rgba(15,23,42,0.2)] overflow-hidden">
                <div class="px-3.5 py-2.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                    <div>
                        <div class="text-xs font-bold text-[#111827]">Demo Account Switcher</div>
                        <div class="text-[10px] text-[#6B7280]">Ganti peran evaluasi 1-klik tanpa sandi</div>
                    </div>
                    <button type="button" @click="open = false" class="p-1 text-[#6B7280] hover:text-[#111827] rounded cursor-pointer">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <div class="p-2 space-y-1">
                    @foreach($demoUsers as $du)
                        @php
                            $isCurrent = isset($currentUser) && $currentUser->id === $du->id;
                            $label = match ($du->email) {
                                'bagas@nexatrade.id' => 'Buyer 1 · Bagas Ferian',
                                'proplayer@nexatrade.id' => 'Buyer 2 · Fajar ProPlayer',
                                'juragan@nexatrade.id' => 'Seller 1 · Juragan Voucher',
                                'sultan@nexatrade.id' => 'Seller 2 · Sultan Akun',
                                'admin@nexatrade.id' => 'Admin · Chaos Engineer',
                                default => $du->name,
                            };
                            $rolePill = match ($du->role) {
                                'admin' => 'bg-[#FEF2F2] text-[#B91C1C]',
                                'seller' => 'bg-[#FFF4ED] text-[#C2410C]',
                                default => 'bg-[#ECFDF5] text-[#047857]',
                            };
                        @endphp
                        <form method="POST" action="{{ route('demo.switch-user') }}">
                            @csrf
                            <input type="hidden" name="email" value="{{ $du->email }}">
                            <button type="submit"
                                    class="btn-tactile w-full px-2.5 py-2 rounded-lg text-left flex items-center justify-between gap-2 text-xs cursor-pointer {{ $isCurrent ? 'bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-semibold' : 'hover:bg-[#F8FAFC] text-[#111827] border border-transparent' }}">
                                <span class="truncate">{{ $label }}</span>
                                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded {{ $rolePill }} shrink-0">{{ $du->role }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>

            {{-- Collapsed Sleek Dark-Charcoal Pill Trigger --}}
            <button type="button" @click="open = !open"
                    class="btn-tactile ml-auto flex items-center gap-2 bg-[#111827] hover:bg-[#1F2937] text-white text-xs font-medium px-3.5 py-2 rounded-full shadow-lg cursor-pointer">
                <span class="w-2 h-2 rounded-full bg-[#00AA5B]"></span>
                <span>{{ $activeShortRole }}</span>
                <svg class="w-3.5 h-3.5 text-[#9CA3AF] transition-transform duration-150" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m18 15-6-6-6 6"/>
                </svg>
            </button>
        </div>
    @endif
</body>
</html>
