@extends('layouts.app')

@section('title', 'Inbox Chat — NexaTrade')

@section('content')
    @php
        $storeProductsJson = $storeProducts->map(fn ($p): array => [
            'id' => $p->id,
            'title' => $p->title,
            'price_formatted' => 'Rp '.number_format((float) $p->price, 0, ',', '.'),
            'thumbnail_url' => $p->primaryThumbnailUrl(),
            'slug' => $p->slug,
        ])->values()->all();
    @endphp

    <div class="space-y-4"
         x-data="{
             switchingThread: false,
             messages: @js($serializedMessages),
             draft: '',
             sending: false,
             sendError: '',
             pickerOpen: false,
             storeProducts: @js($storeProductsJson),
             selectedProductId: {{ $preAttachedProduct ? (int) $preAttachedProduct->id : 'null' }},
             pollTimer: null,

             get selectedProduct() {
                 if (!this.selectedProductId) return null;
                 return this.storeProducts.find((p) => p.id === Number(this.selectedProductId)) || null;
             },

             init() {
                 this.$nextTick(() => this.scrollToBottom());
                 @if($activeConversation)
                 this.pollTimer = setInterval(() => this.pollMessages(), 5000);
                 window.addEventListener('beforeunload', () => clearInterval(this.pollTimer));
                 @endif
             },

             scrollToBottom() {
                 const el = this.$refs.chatFeed;
                 if (el) {
                     el.scrollTop = el.scrollHeight;
                 }
             },

             async pollMessages() {
                 @if($activeConversation)
                 try {
                     const res = await fetch('{{ route('inbox.messages', $activeConversation) }}', {
                         headers: { 'Accept': 'application/json' },
                     });
                     if (!res.ok) return;
                     const data = await res.json();
                     if (data.ok && Array.isArray(data.messages)) {
                         const prevLen = this.messages.length;
                         this.messages = data.messages;
                         if (data.messages.length > prevLen) {
                             this.$nextTick(() => this.scrollToBottom());
                         }
                     }
                 } catch (e) {}
                 @endif
             },

             async submitMessage() {
                 @if($activeConversation)
                 const text = this.draft.trim();
                 if (!text || this.sending) return;
                 this.sending = true;
                 this.sendError = '';
                 try {
                     const res = await fetch('{{ route('inbox.send', $activeConversation) }}', {
                         method: 'POST',
                         headers: {
                             'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                             'Accept': 'application/json',
                             'Content-Type': 'application/json',
                         },
                         body: JSON.stringify({
                             message: text,
                             attached_product_id: this.selectedProductId,
                         }),
                     });
                     const data = await res.json();
                     if (!res.ok || !data.ok) {
                         this.sendError = data.message || 'Gagal mengirim pesan.';
                         return;
                     }
                     this.messages.push(data.message);
                     this.draft = '';
                     this.selectedProductId = null;
                     this.pickerOpen = false;
                     this.$nextTick(() => this.scrollToBottom());
                     if (data.warning && window.nexaToast) {
                         window.nexaToast('warning', 'Moderasi Anti-Bypass', data.warning);
                     }
                 } catch (e) {
                     this.sendError = 'Koneksi terganggu. Silakan coba lagi.';
                 } finally {
                     this.sending = false;
                 }
                 @endif
             }
         }">

        {{-- Page Title Header --}}
        <div class="flex flex-wrap items-center justify-between gap-3 animate-enter" style="--stagger-index: 0">
            <div>
                <h1 class="text-xl font-extrabold text-[#111827]">Inbox Chat Pra-Transaksi</h1>
                <p class="text-xs text-[#6B7280] mt-0.5">
                    Tanya stok, spesifikasi akun, atau detail pengiriman langsung ke penjual. Seluruh pesan dipantau otomatis oleh Filter Anti-Bypass NexaTrade.
                </p>
            </div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-[11px] font-semibold text-[#047857]">
                <svg class="w-3.5 h-3.5 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                <span>Anti-Bypass Guard Aktif</span>
            </div>
        </div>

        {{-- 2-Pane Light Mode Chat Workspace --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[600px] animate-enter" style="--stagger-index: 1">
            {{-- LEFT PANE (4/12): Searchable Conversation List --}}
            <aside class="lg:col-span-4 border-b lg:border-b-0 lg:border-r border-[#E4E8EE] flex flex-col bg-[#F8FAFC]/60">
                <div class="p-3.5 border-b border-[#E4E8EE] bg-white">
                    <form method="GET" action="{{ route('inbox.index') }}" class="relative">
                        <svg class="w-4 h-4 text-[#6B7280] absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <input type="text" name="q" value="{{ $search }}" placeholder="Cari nama toko, pembeli, atau pesan..."
                               class="w-full h-9 pl-9 pr-3 text-xs bg-[#F3F5F8] border border-transparent rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </form>
                </div>

                <div class="flex-1 overflow-y-auto divide-y divide-[#F1F5F9] max-h-[540px]">
                    @forelse($conversations as $conv)
                        @php
                            $isViewerBuyer = (int) $conv->buyer_id === (int) $user->id;
                            $counterpartName = $isViewerBuyer
                                ? ($conv->sellerProfile?->store_name ?? $conv->seller?->name ?? 'Toko Penjual')
                                : ($conv->buyer?->name ?? 'Pembeli');
                            $counterpartTag = $isViewerBuyer ? 'Penjual' : 'Pembeli';
                            $unread = $conv->unreadCountFor($user->id);
                            $isActive = $activeConversation && $activeConversation->id === $conv->id;
                        @endphp
                        <a href="{{ route('inbox.index', ['conversation' => $conv->id]) }}"
                           @click="switchingThread = true"
                           class="block px-4 py-3.5 transition-colors {{ $isActive ? 'bg-[#EFF6FF] border-l-3 border-l-[#0068E1]' : 'bg-white hover:bg-[#F8FAFC]' }}">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full {{ $isViewerBuyer ? 'bg-[#EFF6FF] border-[#BFDBFE] text-[#0068E1]' : 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857]' }} border font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr($counterpartName, 0, 2)) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#111827] truncate">{{ $counterpartName }}</span>
                                        <span class="font-mono-tabular text-[10px] text-[#6B7280] shrink-0">
                                            {{ $conv->last_message_at?->diffForHumans(short: true) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 mt-1">
                                        <p class="text-[11px] text-[#6B7280] truncate">
                                            {{ $conv->last_message_preview ?: 'Percakapan dimulai — tulis pertanyaan pertama Anda.' }}
                                        </p>
                                        @if($unread > 0)
                                            <span class="font-mono-tabular min-w-[18px] h-[18px] px-1 rounded-full bg-[#F05A28] text-white text-[10px] font-bold flex items-center justify-center shrink-0">
                                                {{ $unread }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <span class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase {{ $isViewerBuyer ? 'bg-[#EFF6FF] text-[#0068E1]' : 'bg-[#F1F5F9] text-[#4B5563]' }}">
                                            {{ $counterpartTag }}
                                        </span>
                                        @if($conv->contextProduct)
                                            <span class="text-[10px] text-[#6B7280] truncate">{{ $conv->contextProduct->title }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="p-8 text-center space-y-2">
                            <div class="w-11 h-11 rounded-full bg-[#F1F5F9] text-[#6B7280] mx-auto flex items-center justify-center">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            </div>
                            <div class="text-xs font-bold text-[#111827]">Belum ada percakapan</div>
                            <p class="text-[11px] text-[#6B7280]">Klik tombol <strong>Tanya Penjual</strong> di halaman produk atau etalase toko untuk mulai chat.</p>
                        </div>
                    @endforelse
                </div>
            </aside>

            {{-- RIGHT PANE (8/12): Active Message Thread --}}
            <section class="lg:col-span-8 flex flex-col bg-[#F4F6F9]/50 relative">
                {{-- Light-Mode Shimmer Skeleton when switching threads --}}
                <div x-show="switchingThread" x-cloak class="p-5 space-y-4 flex-1 bg-white">
                    <div class="flex items-center gap-3 pb-4 border-b border-[#E4E8EE]">
                        <div class="w-10 h-10 rounded-full skeleton-shimmer"></div>
                        <div class="space-y-2 flex-1">
                            <div class="h-3.5 w-44 rounded skeleton-shimmer"></div>
                            <div class="h-2.5 w-28 rounded skeleton-shimmer"></div>
                        </div>
                    </div>
                    <div class="space-y-3 pt-2">
                        <div class="h-14 w-2/3 rounded-xl skeleton-shimmer"></div>
                        <div class="h-14 w-1/2 ml-auto rounded-xl skeleton-shimmer"></div>
                        <div class="h-20 w-3/5 rounded-xl skeleton-shimmer"></div>
                    </div>
                </div>

                @if($activeConversation)
                    @php
                        $viewerIsBuyer = (int) $activeConversation->buyer_id === (int) $user->id;
                        $headerName = $viewerIsBuyer
                            ? ($activeConversation->sellerProfile?->store_name ?? $activeConversation->seller?->name)
                            : $activeConversation->buyer?->name;
                    @endphp
                    <div x-show="!switchingThread" class="flex flex-col flex-1 h-full">
                        {{-- Active Thread Header --}}
                        <div class="px-5 py-3.5 bg-white border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr((string) $headerName, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-bold text-[#111827] truncate">{{ $headerName }}</div>
                                    <div class="text-[11px] text-[#6B7280] flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#00AA5B]"></span>
                                        <span>{{ $viewerIsBuyer ? 'Merchant Terverifikasi NexaTrade' : 'Pembeli Terdaftar' }}</span>
                                    </div>
                                </div>
                            </div>

                            @if($activeConversation->sellerProfile)
                                <a href="{{ route('stores.toko', $activeConversation->sellerProfile->slug) }}"
                                   class="btn-tactile px-3 py-1.5 rounded-lg bg-[#F8FAFC] hover:bg-[#EFF6FF] border border-[#E4E8EE] hover:border-[#BFDBFE] text-xs font-semibold text-[#111827] hover:text-[#0068E1] inline-flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>
                                    <span>Kunjungi Toko</span>
                                </a>
                            @endif
                        </div>

                        {{-- Scrollable Message Feed --}}
                        <div x-ref="chatFeed" class="flex-1 overflow-y-auto p-5 space-y-3.5 max-h-[430px] min-h-[340px]">
                            <template x-if="messages.length === 0">
                                <div class="h-full flex flex-col items-center justify-center text-center py-12 text-xs text-[#6B7280]">
                                    <div class="w-11 h-11 rounded-full bg-white border border-[#E4E8EE] text-[#0068E1] flex items-center justify-center mb-2.5">
                                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    </div>
                                    <p class="font-bold text-[#111827]">Mulai percakapan dengan {{ $headerName }}</p>
                                    <p class="mt-1 max-w-sm">Tanyakan ketersediaan stok, spesifikasi akun, atau detail pengiriman. Hindari berbagi nomor HP/rekening di luar NexaTrade.</p>
                                </div>
                            </template>

                            <template x-for="msg in messages" :key="msg.id">
                                <div class="flex flex-col" :class="msg.is_mine ? 'items-end' : 'items-start'">
                                    <div class="max-w-[85%] sm:max-w-[75%] space-y-1.5">
                                        {{-- Embedded Rich Product Context Card --}}
                                        <template x-if="msg.attached_product">
                                            <div class="bg-white border border-[#E4E8EE] rounded-xl p-2.5 flex items-center gap-3 shadow-2xs">
                                                <img :src="msg.attached_product.thumbnail_url" :alt="msg.attached_product.title"
                                                     class="w-16 h-12 rounded-lg object-cover border border-[#E4E8EE] shrink-0">
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-xs font-bold text-[#111827] line-clamp-1" x-text="msg.attached_product.title"></div>
                                                    <div class="font-mono-tabular text-xs font-extrabold text-[#F05A28] mt-0.5" x-text="msg.attached_product.price_formatted"></div>
                                                </div>
                                                <template x-if="msg.attached_product.url">
                                                    <a :href="msg.attached_product.url"
                                                       class="btn-tactile px-2.5 py-1.5 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] text-[11px] font-bold shrink-0">
                                                        Lihat Produk
                                                    </a>
                                                </template>
                                            </div>
                                        </template>

                                        {{-- Chat Bubble --}}
                                        <div :class="msg.is_mine ? 'bg-[#EFF6FF] border-[#BFDBFE] text-[#111827]' : 'bg-white border-[#E4E8EE] text-[#111827]'"
                                             class="border rounded-2xl px-4 py-2.5 text-xs leading-relaxed shadow-2xs">
                                            <div class="flex items-center justify-between gap-3 mb-1">
                                                <span class="text-[10px] font-bold" :class="msg.is_mine ? 'text-[#0068E1]' : 'text-[#4B5563]'" x-text="msg.sender_name"></span>
                                                <span class="font-mono-tabular text-[10px] text-[#6B7280]" x-text="msg.time_label"></span>
                                            </div>
                                            <p class="whitespace-pre-wrap break-words" x-text="msg.sanitized_message"></p>

                                            {{-- Subtle Amber Anti-Bypass Moderation Badge --}}
                                            <template x-if="msg.is_flagged_bypass">
                                                <div class="mt-2 px-2.5 py-1 rounded-lg bg-[#FFF4ED] border border-[#FED7AA] text-[10px] font-semibold text-[#C2410C] inline-flex items-center gap-1.5">
                                                    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                                    <span>Disensor Anti-Bypass (<span class="font-mono-tabular" x-text="msg.detected_violation_type"></span>)</span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Composer Footer --}}
                        <div class="p-3.5 bg-white border-t border-[#E4E8EE] space-y-2.5">
                            {{-- Pre-Attached Product Preview Pill --}}
                            <template x-if="selectedProduct">
                                <div class="flex items-center justify-between gap-3 p-2.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE]">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <img :src="selectedProduct.thumbnail_url" :alt="selectedProduct.title" class="w-12 h-9 rounded-md object-cover border border-white shrink-0">
                                        <div class="min-w-0">
                                            <div class="text-[10px] font-bold uppercase text-[#0068E1]">Produk Dilampirkan</div>
                                            <div class="text-xs font-bold text-[#111827] truncate" x-text="selectedProduct.title"></div>
                                            <div class="font-mono-tabular text-[11px] font-bold text-[#F05A28]" x-text="selectedProduct.price_formatted"></div>
                                        </div>
                                    </div>
                                    <button type="button" @click="selectedProductId = null" class="p-1 text-[#6B7280] hover:text-[#DC2626] cursor-pointer" title="Lepas lampiran">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                </div>
                            </template>

                            {{-- Optional Product Picker Drawer --}}
                            <div x-show="pickerOpen" x-cloak class="p-3 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-bold text-[#111827]">Lampirkan Produk Toko Ini</span>
                                    <button type="button" @click="pickerOpen = false" class="text-[#6B7280] hover:text-[#111827] cursor-pointer">Tutup</button>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-40 overflow-y-auto">
                                    <template x-for="prod in storeProducts" :key="prod.id">
                                        <button type="button" @click="selectedProductId = prod.id; pickerOpen = false"
                                                :class="selectedProductId === prod.id ? 'border-[#0068E1] bg-[#EFF6FF]' : 'border-[#E4E8EE] bg-white hover:border-[#0068E1]'"
                                                class="p-2 rounded-lg border text-left flex items-center gap-2.5 cursor-pointer transition-colors">
                                            <img :src="prod.thumbnail_url" :alt="prod.title" class="w-11 h-8 rounded object-cover shrink-0">
                                            <div class="min-w-0">
                                                <div class="text-[11px] font-bold text-[#111827] truncate" x-text="prod.title"></div>
                                                <div class="font-mono-tabular text-[10px] font-bold text-[#F05A28]" x-text="prod.price_formatted"></div>
                                            </div>
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <p x-show="sendError" x-cloak x-text="sendError" class="text-[11px] font-semibold text-[#DC2626]"></p>

                            <form method="POST" action="{{ route('inbox.send', $activeConversation) }}" @submit.prevent="submitMessage()" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="attached_product_id" :value="selectedProductId || ''">

                                @if($storeProducts->isNotEmpty())
                                    <button type="button" @click="pickerOpen = !pickerOpen" title="Lampirkan Produk Toko Ini"
                                            :class="pickerOpen || selectedProductId ? 'bg-[#EFF6FF] border-[#BFDBFE] text-[#0068E1]' : 'bg-[#F8FAFC] border-[#E4E8EE] text-[#4B5563] hover:text-[#0068E1]'"
                                            class="btn-tactile h-10 px-3 rounded-lg border text-xs font-semibold inline-flex items-center gap-1.5 shrink-0 cursor-pointer">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                        <span class="hidden sm:inline">Produk</span>
                                    </button>
                                @endif

                                <input type="text" name="message" x-model="draft" required maxlength="1500"
                                       placeholder="Tulis pesan Anda ke {{ $headerName }}..."
                                       class="flex-1 h-10 px-3.5 text-xs bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">

                                <button type="submit" :disabled="sending || !draft.trim()"
                                        class="btn-tactile h-10 px-4 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-1.5 shrink-0 cursor-pointer disabled:opacity-50">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    <span x-text="sending ? 'Mengirim...' : 'Kirim'"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="flex-1 flex flex-col items-center justify-center p-12 text-center text-xs text-[#6B7280]">
                        <div class="w-12 h-12 rounded-full bg-white border border-[#E4E8EE] text-[#0068E1] flex items-center justify-center mb-3">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                        <div class="text-sm font-bold text-[#111827]">Pilih atau Mulai Percakapan</div>
                        <p class="mt-1 max-w-md">Buka halaman produk atau etalase toko pilihan Anda lalu klik <strong>Tanya Penjual</strong> untuk langsung terhubung di sini.</p>
                        <a href="{{ route('marketplace.index') }}" class="btn-tactile mt-4 px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold">
                            Jelajahi Katalog Produk
                        </a>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
