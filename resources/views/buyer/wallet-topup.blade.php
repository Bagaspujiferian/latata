@extends('layouts.app')

@section('title', 'Tagihan Top-Up Dompet '.$topup->topup_code.' — NexaTrade')

@section('content')
    <div class="max-w-2xl mx-auto space-y-5 animate-enter" style="--stagger-index: 0">
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('buyer.wallet') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#0068E1] hover:underline">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                <span>Kembali ke Dompet Pembeli</span>
            </a>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold font-mono-tabular {{ $topup->status === 'PAID' ? 'bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857]' : ($topup->status === 'UNPAID' ? 'bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C]' : 'bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C]') }}">
                {{ $topup->status }}
            </span>
        </div>

        <div class="bg-white border border-[#E4E8EE] rounded-2xl overflow-hidden shadow-[0_12px_32px_-12px_rgba(15,23,42,0.10)]">
            <div class="px-6 py-5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-[#6B7280]">Tagihan Top-Up Dompet Resmi</div>
                    <h1 class="font-mono-tabular text-lg font-extrabold text-[#111827] mt-0.5">{{ $topup->topup_code }}</h1>
                </div>
                <div class="text-right font-mono-tabular">
                    <div class="text-[11px] text-[#6B7280]">Nominal Top-Up</div>
                    <div class="text-2xl font-extrabold text-[#0068E1]">Rp {{ number_format((float) $topup->amount, 0, ',', '.') }}</div>
                </div>
            </div>

            <div class="p-6 space-y-5 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] font-semibold uppercase text-[#6B7280]">Kanal Pembayaran</div>
                        <div class="font-mono-tabular font-bold text-[#111827] mt-1 uppercase">{{ str_replace('_', ' ', $topup->payment_channel) }}</div>
                    </div>
                    <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE]">
                        <div class="text-[10px] font-semibold uppercase text-[#6B7280]">
                            {{ $topup->status === 'PAID' ? 'Waktu Pelunasan' : 'Batas Waktu Bayar' }}
                        </div>
                        @if($topup->status === 'PAID')
                            <div class="font-mono-tabular font-bold text-[#047857] mt-1">{{ $topup->paid_at?->format('d M Y, H:i:s') }} WIB</div>
                        @else
                            <div class="font-mono-tabular font-bold text-[#C2410C] mt-1 flex items-center gap-2">
                                <span>{{ $topup->expires_at?->format('d M Y, H:i') }} WIB</span>
                                <span class="px-1.5 py-0.5 rounded bg-[#FFF4ED] border border-[#FED7AA] text-[10px]"
                                      x-data="liveCountdown('{{ $topup->expires_at?->toIso8601String() }}')" x-text="formatted">--:--</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Anti-Gestun Policy Callout --}}
                <div class="p-4 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#1E40AF] flex items-start gap-3">
                    <svg class="w-5 h-5 text-[#0068E1] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                    <div class="space-y-1">
                        <div class="font-bold text-[#111827]">Kebijakan Anti-Gestun (Anti Money-Laundering)</div>
                        <p class="text-[11px] leading-relaxed text-[#374151]">
                            Dana hasil Top-Up hanya dapat digunakan untuk berbelanja di katalog NexaTrade (otomatis diprioritaskan saat checkout) dan tidak dapat ditarik tunai kembali ke rekening bank.
                        </p>
                    </div>
                </div>

                @php
                    $isLiveSnapDriver = in_array(config('nexatrade.midtrans.payment_driver', 'simulation'), ['midtrans_sandbox', 'midtrans_production'], true);
                @endphp

                @if($topup->status === 'UNPAID')
                    @if($isLiveSnapDriver)
                        <script src="{{ config('nexatrade.midtrans.snap_js_url') }}" data-client-key="{{ config('nexatrade.midtrans.client_key') }}"></script>
                        <div class="p-4 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] space-y-3"
                             x-data="{
                                 snapLoading: false,
                                 snapError: '',
                                 async payTopupWithSnap() {
                                     this.snapLoading = true;
                                     this.snapError = '';
                                     try {
                                         const res = await fetch('{{ route('buyer.wallet.topup.snap-token', $topup->topup_code) }}', {
                                             method: 'POST',
                                             headers: {
                                                 'Content-Type': 'application/json',
                                                 'Accept': 'application/json',
                                                 'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                             }
                                         });
                                         const data = await res.json();
                                         if (!res.ok || !data.ok) {
                                             this.snapError = data.message || 'Gagal memuat token Midtrans Snap.';
                                             this.snapLoading = false;
                                             return;
                                         }
                                         if (window.snap && typeof window.snap.pay === 'function') {
                                             window.snap.pay(data.snap_token, {
                                                 onSuccess: () => { this.$refs.syncTopupForm.submit(); },
                                                 onPending: () => { this.$refs.syncTopupForm.submit(); },
                                                 onError: () => { this.snapError = 'Pembayaran Midtrans gagal atau dibatalkan.'; this.snapLoading = false; },
                                                 onClose: () => { this.snapLoading = false; }
                                             });
                                         } else if (data.redirect_url) {
                                             window.location.href = data.redirect_url;
                                         }
                                     } catch (e) {
                                         this.snapError = 'Terjadi gangguan jaringan saat menghubungi Midtrans Snap.';
                                         this.snapLoading = false;
                                     }
                                 }
                             }">
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                                <button type="button" @click="payTopupWithSnap()" :disabled="snapLoading"
                                        class="btn-tactile flex-1 h-11 rounded-xl bg-[#0068E1] hover:bg-[#0055B8] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                                    <span x-text="snapLoading ? 'Memuat Midtrans Snap...' : 'Bayar Sekarang via Midtrans Snap'"></span>
                                </button>
                                <form x-ref="syncTopupForm" method="POST" action="{{ route('buyer.wallet.topup.sync-status', $topup->topup_code) }}">
                                    @csrf
                                    <button type="submit" class="btn-tactile w-full sm:w-auto h-11 px-4 rounded-xl bg-white hover:bg-[#F8FAFC] border border-[#0068E1] text-[#0068E1] text-xs font-bold cursor-pointer">
                                        Sinkronkan Status Pembayaran
                                    </button>
                                </form>
                            </div>
                            <div x-show="snapError" x-cloak class="p-2.5 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] text-xs font-semibold" x-text="snapError"></div>
                        </div>
                    @endif

                    @if(config('nexatrade.demo_switcher_enabled'))
                        <form method="POST" action="{{ route('buyer.wallet.topup.simulate-pay', $topup->topup_code) }}"
                              x-data="{ paying: false }" @submit="paying = true" class="pt-2">
                            @csrf
                            <button type="submit" :disabled="paying"
                                    class="btn-tactile w-full h-11 rounded-xl bg-[#00AA5B] hover:bg-[#00924E] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                                <svg x-show="paying" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                <span x-text="paying ? 'Memproses Pelunasan Idempoten...' : 'Simulasi Bayar Lunas Top-Up'"></span>
                            </button>
                        </form>
                    @elseif(! $isLiveSnapDriver)
                        <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] text-[#4B5563]">
                            Selesaikan pembayaran Top-Up melalui kanal resmi <strong class="uppercase text-[#111827]">{{ str_replace('_', ' ', $topup->payment_channel) }}</strong>. Saldo akan masuk otomatis setelah webhook Midtrans terverifikasi.
                        </div>
                    @endif
                @elseif($topup->status === 'PAID')
                    <div class="p-4 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <svg class="w-5 h-5 text-[#00AA5B] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                            <div>
                                <div class="font-bold text-[#065F46]">Tagihan Top-Up Lunas &amp; Masuk Buku Besar</div>
                                <div class="font-mono-tabular text-[11px] mt-0.5">Ref Gateway: {{ $topup->gateway_reference }}</div>
                            </div>
                        </div>
                        <a href="{{ route('marketplace.index') }}" class="btn-tactile px-3.5 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold shrink-0">
                            Mulai Belanja
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
