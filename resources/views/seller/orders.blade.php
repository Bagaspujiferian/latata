@extends('layouts.app')

@section('title', 'Antrean Pesanan SLA — TokoKu')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start" x-data="{ switchingTab: false }">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-4 w-full">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Antrean Pengiriman SLA</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Diurutkan otomatis berdasarkan batas waktu `sla_deadline_at` paling mendesak.</p>
                </div>

                <div class="flex flex-wrap gap-1.5 text-xs">
                    @foreach(['' => 'Semua', 'PROCESSING' => 'Wajib Kirim', 'DELIVERED' => 'Dikirim', 'DISPUTED' => 'Sengketa', 'COMPLETED' => 'Selesai', 'REFUNDED' => 'Refund'] as $k => $lbl)
                        <a href="{{ route('seller.orders', $k !== '' ? ['status' => $k] : []) }}"
                           @click="switchingTab = true"
                           class="btn-tactile px-3 py-1.5 rounded-lg border font-semibold {{ $statusFilter === $k ? 'bg-[#0068E1] text-white border-[#0068E1]' : 'bg-white text-[#4B5563] border-[#E4E8EE] hover:border-[#CBD5E1]' }}">
                            {{ $lbl }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div x-show="switchingTab" x-cloak>
                <x-skeleton.table-rows :rows="4" :cols="5" />
            </div>

            <div x-show="!switchingTab" class="space-y-4">
                @forelse($orders as $idx => $order)
                    <div style="--stagger-index: {{ min($idx, 8) }}"
                         class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter {{ $order->status === 'PROCESSING' ? 'border-l-4 border-l-[#F05A28]' : '' }}">
                        <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono-tabular font-bold text-[#111827]">{{ $order->order_code }}</span>
                                <span class="{{ in_array($order->status, ['PROCESSING', 'DISPUTED'], true) ? 'badge-live-pulse' : 'inline-flex' }} px-2 py-0.5 rounded-full text-[10px] font-bold {{ $order->status === 'PROCESSING' ? 'bg-[#FFF4ED] text-[#C2410C]' : ($order->status === 'DISPUTED' ? 'bg-[#FEF2F2] text-[#B91C1C]' : 'bg-[#ECFDF5] text-[#047857]') }}">
                                    {{ $order->status }}
                                </span>
                                <span class="px-2 py-0.5 rounded bg-white border border-[#E4E8EE] text-[10px] font-semibold text-[#4B5563] uppercase">{{ $order->delivery_method }}</span>
                                <span class="text-[#6B7280]">Pembeli: <strong class="text-[#111827]">{{ $order->buyer?->name }}</strong></span>
                            </div>

                            <div class="flex items-center gap-3 font-mono-tabular">
                                @if($order->status === 'PROCESSING' && $order->sla_deadline_at)
                                    <div x-data="liveCountdown('{{ $order->sla_deadline_at->toIso8601String() }}')"
                                         class="badge-live-pulse px-2.5 py-1 rounded-full text-[11px] font-bold border"
                                         :class="expired ? 'bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]' : 'bg-[#FFF4ED] text-[#C2410C] border-[#FED7AA]'">
                                        SLA: <span x-text="expired ? 'TERLEWATI' : formatted"></span>
                                    </div>
                                @endif
                                <span class="font-bold text-[#047857]">Escrow: Rp {{ number_format((float) $order->seller_escrow_amount, 0, ',', '.') }}</span>
                            </div>
                        </div>

                        <div class="p-4 grid grid-cols-1 xl:grid-cols-12 gap-5">
                            <div class="xl:col-span-5 space-y-3 text-xs">
                                @foreach($order->items as $item)
                                    <div class="font-bold text-sm text-[#111827]">
                                        {{ $item->product_title_snapshot }}
                                        <span class="font-mono-tabular text-xs font-normal text-[#6B7280]">&times;{{ $item->quantity }}</span>
                                    </div>
                                @endforeach

                                @if(! empty($order->buyer_game_inputs) || $order->verified_game_nickname)
                                    <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE] space-y-1">
                                        <div class="text-[11px] font-semibold text-[#4B5563]">Data Target Pengiriman</div>
                                        @if($order->verified_game_nickname)
                                            <div class="font-semibold text-[#047857]">Nickname: {{ $order->verified_game_nickname }}</div>
                                        @endif
                                        @foreach($order->buyer_game_inputs ?? [] as $k => $v)
                                            <div class="font-mono-tabular text-[11px]">{{ $k }}: <strong class="text-[#111827]">{{ $v }}</strong></div>
                                        @endforeach
                                    </div>
                                @endif

                                @if(in_array($order->status, ['PAID_ESCROW', 'PROCESSING'], true))
                                    @if($order->delivery_method === 'manual_trade' && $order->seller_accepted_at === null)
                                        <form method="POST" action="{{ route('seller.orders.accept', $order->order_code) }}" class="p-3 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] flex items-center justify-between gap-2">
                                            @csrf
                                            <div class="text-[11px] text-[#1E3A8A]">
                                                <strong class="block text-[#111827]">Kunci Pembatalan Sepihak</strong>
                                                <span>Klik untuk menandai pesanan mulai dikerjakan.</span>
                                            </div>
                                            <button type="submit" class="btn-tactile px-3.5 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold shrink-0 cursor-pointer">
                                                Mulai Proses Pesanan
                                            </button>
                                        </form>
                                    @elseif($order->delivery_method === 'manual_trade' && $order->seller_accepted_at !== null)
                                        <div class="px-3 py-2 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[11px] text-[#1E40AF] font-semibold">
                                            Sedang Diproses sejak {{ $order->seller_accepted_at->format('H:i:s') }} WIB (Pembatalan pembeli terkunci)
                                        </div>
                                    @endif

                                    <form method="POST" action="{{ route('seller.orders.deliver', $order->order_code) }}" class="p-3.5 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] space-y-2.5">
                                        @csrf
                                        <div class="text-xs font-bold text-[#065F46]">Kirim Bukti Pengiriman (Stop Timer SLA)</div>
                                        <input type="text" name="delivery_notes" required placeholder="Contoh: 5 BGL sudah didrop ke Donation Box world BAGASDROP88"
                                               class="w-full h-9 px-3 text-xs bg-white border border-[#A7F3D0] rounded-lg focus:border-[#00AA5B] focus:outline-none">
                                        <input type="text" name="proof_url" placeholder="URL Screenshot Bukti (opsional)"
                                               class="w-full h-9 px-3 text-xs font-mono-tabular bg-white border border-[#E4E8EE] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                        <button type="submit" class="btn-tactile w-full py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-bold cursor-pointer">
                                            Tandai Terkirim &amp; Hitung Ulang SLA
                                        </button>
                                    </form>
                                @endif
                            </div>

                            <div class="xl:col-span-7 border border-[#E4E8EE] rounded-xl flex flex-col justify-between bg-[#F8FAFC] overflow-hidden">
                                <div class="p-3 max-h-48 overflow-y-auto space-y-2 text-xs">
                                    @forelse($order->messages as $msg)
                                        <div class="p-2.5 bg-white border border-[#E4E8EE] rounded-lg">
                                            <div class="flex justify-between text-[10px] text-[#6B7280]">
                                                <strong class="uppercase text-[#111827]">{{ $msg->sender_role }} &middot; {{ $msg->sender?->name }}</strong>
                                                <span class="font-mono-tabular">{{ $msg->created_at?->format('H:i') }}</span>
                                            </div>
                                            <div class="mt-1 text-[#374151]">{{ $msg->sanitized_message }}</div>
                                            @if($msg->is_flagged_bypass)
                                                <div class="mt-1 text-[10px] text-[#DC2626] font-semibold">Disensor Anti-Bypass: {{ $msg->detected_violation_type }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-[#6B7280] text-center py-4">Belum ada pesan pada pesanan ini.</p>
                                    @endforelse
                                </div>

                                <form method="POST" action="{{ route('seller.orders.messages', $order->order_code) }}" class="p-2.5 bg-white border-t border-[#E4E8EE] flex gap-2">
                                    @csrf
                                    <input type="text" name="message" required placeholder="Balas pembeli (tanpa nomor HP/rekening luar)..."
                                           class="flex-1 h-8 px-3 text-xs bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                    <button type="submit" class="btn-tactile px-3.5 h-8 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-semibold cursor-pointer">Kirim</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center text-xs text-[#6B7280]">Tidak ada pesanan pada filter ini.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
