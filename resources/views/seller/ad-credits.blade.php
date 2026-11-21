@extends('layouts.app')

@section('title', 'Kredit Sundul, Level Toko & Export CSV — TokoKu Studio')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$user->sellerProfile" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header & Store Level Progress Banner --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 animate-enter" style="--stagger-index: 0">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-extrabold text-[#111827]">Kredit Promosi, Level Toko &amp; Ekspor Laporan</h1>
                        <p class="text-xs text-[#6B7280] mt-0.5">Beli Kredit Sundul untuk menaikkan posisi produk di katalog, naikkan Level Toko demi diskon komisi, dan unduh laporan CSV pembukuan.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="px-3.5 py-2 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] text-right font-mono-tabular">
                            <div class="text-[10px] uppercase font-semibold text-[#9A3412]">Kredit Sundul Aktif</div>
                            <div class="text-base font-extrabold text-[#C2410C]">{{ (int) ($user->sellerProfile?->ad_credits ?? 0) }} Kredit</div>
                        </div>
                        <div class="px-3.5 py-2 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-right font-mono-tabular">
                            <div class="text-[10px] uppercase font-semibold text-[#065F46]">Saldo Aktif</div>
                            <div class="text-base font-extrabold text-[#047857]">Rp {{ number_format((float) ($user->wallet?->available_balance ?? 0), 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                {{-- Store Tier & Commission Discount Bar --}}
                <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2.5 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="px-2.5 py-1 rounded-lg bg-[#0068E1] text-white font-mono-tabular text-[11px] font-extrabold">
                                LEVEL {{ $storeLevel['level'] }}
                            </span>
                            <span class="font-extrabold text-[#111827] text-sm">{{ $storeLevel['tier_name'] }}</span>
                            <span class="px-2 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[10px] font-bold">
                                Diskon Komisi: {{ number_format((float) $storeLevel['commission_discount_percent'], 1) }}%
                            </span>
                        </div>
                        <div class="font-mono-tabular text-[11px] text-[#4B5563]">
                            Pesanan Selesai Terverifikasi: <strong class="text-[#111827]">{{ $storeLevel['completed_orders'] }}</strong>
                            &middot; SLA Rata-Rata: <strong class="text-[#111827]">{{ number_format((float) $storeLevel['avg_delivery_minutes'], 1) }} mnt</strong>
                        </div>
                    </div>

                    <div class="h-2 w-full rounded-full bg-[#E4E8EE] overflow-hidden">
                        <div class="h-full rounded-full bg-[#0068E1]" style="width: {{ max(6, (int) $storeLevel['progress_percent']) }}%"></div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between text-[11px] text-[#6B7280]">
                        @if($storeLevel['next_level_orders'])
                            <span>Target berikutnya: <strong class="text-[#111827]">{{ $storeLevel['next_level_label'] }}</strong> ({{ $storeLevel['completed_orders'] }}/{{ $storeLevel['next_level_orders'] }} pesanan)</span>
                        @else
                            <span class="text-[#047857] font-semibold">Anda telah mencapai tier tertinggi Juragan Sultan!</span>
                        @endif
                        <span class="font-mono-tabular font-bold text-[#0068E1]">{{ $storeLevel['progress_percent'] }}%</span>
                    </div>
                </div>
            </div>

            {{-- Ad Credit Packages Grid --}}
            <div class="space-y-3 animate-enter" style="--stagger-index: 1">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-[#111827]">Beli Paket Kredit Sundul &amp; Promosi</h2>
                    @if(! $user->hasWalletPin())
                        <a href="{{ route('account.security') }}" class="text-xs font-semibold text-[#F05A28] hover:underline">
                            Amankan transaksi dengan PIN 6-Digit &rarr;
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($packages as $pkg)
                        <form method="POST" action="{{ route('seller.ad-credits.purchase') }}"
                              class="card-elevate bg-white border {{ $pkg->badge_label ? 'border-[#0068E1] ring-1 ring-[#0068E1]/15' : 'border-[#E4E8EE]' }} rounded-xl p-5 flex flex-col justify-between gap-4 text-xs">
                            @csrf
                            <input type="hidden" name="package_id" value="{{ $pkg->id }}">

                            <div class="space-y-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono-tabular text-[10px] font-bold px-2 py-0.5 rounded bg-[#F1F5F9] text-[#4B5563]">{{ $pkg->code }}</span>
                                    @if($pkg->badge_label)
                                        <span class="px-2 py-0.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold">{{ $pkg->badge_label }}</span>
                                    @endif
                                </div>
                                <h3 class="text-base font-extrabold text-[#111827]">{{ $pkg->name }}</h3>
                                <div class="flex items-baseline gap-1.5 font-mono-tabular">
                                    <span class="text-2xl font-extrabold text-[#0068E1]">{{ $pkg->credits_amount }}</span>
                                    @if($pkg->bonus_credits > 0)
                                        <span class="text-xs font-bold text-[#00AA5B]">+ {{ $pkg->bonus_credits }} Bonus</span>
                                    @endif
                                    <span class="text-[#6B7280]">Kredit</span>
                                </div>
                                <div class="font-mono-tabular text-lg font-extrabold text-[#F05A28] pt-1">
                                    Rp {{ number_format((float) $pkg->price, 0, ',', '.') }}
                                </div>
                            </div>

                            <div class="space-y-2.5 pt-3 border-t border-[#F1F5F9]">
                                @if($user->hasWalletPin())
                                    <div>
                                        <label class="block text-[10px] font-semibold text-[#374151] mb-1">PIN Dompet 6-Digit *</label>
                                        <input type="password" name="wallet_pin" required inputmode="numeric" maxlength="6" placeholder="••••••"
                                               class="w-full h-9 px-3 font-mono-tabular text-center tracking-[0.25em] bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                    </div>
                                @endif
                                <button type="submit"
                                        class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                                    Beli via Saldo Aktif
                                </button>
                            </div>
                        </form>
                    @endforeach
                </div>
            </div>

            {{-- Featured Product Boost & CSV Exports Row --}}
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start animate-enter" style="--stagger-index: 2">
                {{-- Left 7/12: 1-Click Featured Boost (3 Credits) --}}
                <div class="xl:col-span-7 bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
                    <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-sm font-bold text-[#111827]">Promosikan Produk (Featured Boost — 3 Kredit)</h2>
                        <span class="text-[11px] text-[#6B7280]">Menempatkan produk di puncak katalog</span>
                    </div>
                    <div class="divide-y divide-[#F1F5F9]">
                        @forelse($products as $prod)
                            <div class="row-interactive p-3.5 flex items-center justify-between gap-3 text-xs">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-[#111827] truncate">{{ $prod->title }}</span>
                                        @if($prod->is_featured)
                                            <span class="px-1.5 py-0.2 rounded bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[10px] font-bold shrink-0">FEATURED</span>
                                        @endif
                                    </div>
                                    <div class="font-mono-tabular text-[11px] text-[#6B7280] mt-0.5">
                                        {{ $prod->sku_code }} &middot; Rp {{ number_format((float) $prod->price, 0, ',', '.') }}
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('seller.ad-credits.boost', $prod) }}" class="shrink-0">
                                    @csrf
                                    <button type="submit" class="btn-tactile px-3 py-1.5 rounded-lg bg-[#FFF4ED] hover:bg-[#FFEDD5] border border-[#FED7AA] text-[#C2410C] font-bold cursor-pointer">
                                        Boost (3 Kredit)
                                    </button>
                                </form>
                            </div>
                        @empty
                            <p class="p-6 text-xs text-[#6B7280]">Belum ada produk aktif untuk dipromosikan.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Right 5/12: CSV Export Accounting Center --}}
                <div class="xl:col-span-5 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 text-xs"
                     x-data="{ fromDate: '{{ now()->subDays(30)->format('Y-m-d') }}', toDate: '{{ now()->format('Y-m-d') }}' }">
                    <div class="pb-2.5 border-b border-[#F1F5F9]">
                        <h2 class="text-sm font-bold text-[#111827]">Ekspor Laporan CSV (Microsoft Excel)</h2>
                        <p class="text-[11px] text-[#6B7280] mt-0.5">File UTF-8 BOM siap olah untuk pembukuan dan pajak toko Anda.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Dari Tanggal</label>
                            <input type="date" x-model="fromDate" class="w-full h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Sampai Tanggal</label>
                            <input type="date" x-model="toDate" class="w-full h-9 px-2.5 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a :href="'{{ route('seller.exports.orders') }}?from=' + fromDate + '&to=' + toDate"
                           class="btn-tactile w-full h-10 px-4 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white font-bold flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Export Laporan Penjualan (CSV)</span>
                        </a>
                        <a :href="'{{ route('seller.exports.mutations') }}?from=' + fromDate + '&to=' + toDate"
                           class="btn-tactile w-full h-10 px-4 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] font-bold flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Export Mutasi Dompet (CSV)</span>
                        </a>
                    </div>
                </div>
            </div>

            {{-- Purchase History --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 3">
                <div class="px-4 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE]">
                    <h2 class="text-sm font-bold text-[#111827]">Riwayat Pembelian Kredit Promosi ({{ $purchases->count() }})</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] font-semibold uppercase text-[#6B7280]">
                            <tr>
                                <th class="px-4 py-3">Kode</th>
                                <th class="px-4 py-3">Paket</th>
                                <th class="px-4 py-3 text-right">Kredit</th>
                                <th class="px-4 py-3 text-right">Nominal</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F1F5F9]">
                            @forelse($purchases as $p)
                                <tr class="row-interactive">
                                    <td class="px-4 py-3 font-mono-tabular font-bold text-[#111827]">{{ $p->purchase_code }}</td>
                                    <td class="px-4 py-3 font-semibold text-[#374151]">{{ $p->package?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-right font-mono-tabular font-bold text-[#0068E1]">+{{ $p->credits_granted }}</td>
                                    <td class="px-4 py-3 text-right font-mono-tabular font-bold text-[#111827]">Rp {{ number_format((float) $p->amount_paid, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#ECFDF5] text-[#047857]">{{ $p->status }}</span>
                                    </td>
                                    <td class="px-4 py-3 font-mono-tabular text-[11px] text-[#6B7280]">{{ $p->created_at?->format('d M Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-[#6B7280]">Belum ada pembelian paket kredit promosi.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
