@extends('layouts.app')

@section('title', 'Jam Buka, Profil Toko & Ulasan — TokoKu')

@section('content')
    @php
        $effectiveOpen = $profile ? $profile->isOpenForManualTradeNow() : true;
    @endphp

    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-seller-sidebar :profile="$profile" />

        <div class="flex-1 min-w-0 space-y-6 w-full">
            {{-- Header Summary --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Profil Etalase, Jam Operasional &amp; Ulasan</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        Atur banner &amp; bio etalase publik, jadwal buka-tutup otomatis 7 hari, serta balas ulasan pembeli secara resmi.
                    </p>
                </div>

                <div class="flex items-center gap-2.5">
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold font-mono-tabular bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1]">
                        {{ number_format($followersCount) }} Pengikut
                    </span>
                    @if($profile)
                        <a href="{{ route('stores.show', $profile->slug) }}"
                           class="btn-tactile px-3.5 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-1.5">
                            <span>Lihat Etalase Publik</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
                        </a>
                    @endif
                </div>
            </div>

            {{-- Card 1: Store Profile + 7-Day Operating Hours --}}
            <form method="POST" action="{{ route('seller.settings.put') }}" enctype="multipart/form-data"
                  class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 1">
                @csrf
                @method('PUT')

                <div class="px-5 py-4 border-b border-[#F1F5F9] flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-[#0068E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/></svg>
                        <h2 class="text-sm font-bold text-[#111827]">Identitas Etalase &amp; Banner Toko</h2>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold {{ $effectiveOpen ? 'bg-[#ECFDF5] text-[#047857]' : 'bg-[#FEF2F2] text-[#B91C1C]' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $effectiveOpen ? 'bg-[#00AA5B]' : 'bg-[#DC2626]' }}"></span>
                        <span>Status Saat Ini: {{ $effectiveOpen ? 'Manual Trade BUKA' : 'Manual Trade TUTUP' }}</span>
                    </span>
                </div>

                <div class="p-5 space-y-5">
                    {{-- Current Banner Preview --}}
                    @if($profile?->bannerPublicUrl())
                        <div class="rounded-xl overflow-hidden border border-[#E4E8EE] bg-[#F8FAFC]">
                            <img src="{{ $profile->bannerPublicUrl() }}" alt="Banner {{ $profile->store_name }}" class="w-full h-32 sm:h-40 object-cover">
                        </div>
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Nama Toko *</label>
                            <input type="text" name="store_name" value="{{ old('store_name', $profile?->store_name) }}" required
                                   class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Tagline Singkat</label>
                            <input type="text" name="store_tagline" value="{{ old('store_tagline', $profile?->store_tagline) }}" maxlength="150"
                                   placeholder="Contoh: Voucher Resmi & Akun Verified Pengiriman Kilat"
                                   class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Bio &amp; Kebijakan Garansi Toko</label>
                            <textarea name="store_bio" rows="3" maxlength="500"
                                      placeholder="Jelaskan jam respon admin, garansi anti-hackback, atau ketentuan layanan toko..."
                                      class="w-full px-3 py-2 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">{{ old('store_bio', $profile?->store_bio) }}</textarea>
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Unggah Banner Baru (JPG/PNG/WEBP/SVG, maks 3MB)</label>
                            <input type="file" name="store_banner_file" accept=".jpg,.jpeg,.png,.webp,.svg"
                                   class="w-full text-xs text-[#4B5563] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-[#E4E8EE] file:bg-white file:text-xs file:font-semibold file:text-[#111827] hover:file:bg-[#F8FAFC]">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Atau URL/Path Banner</label>
                            <input type="text" name="store_banner_url" value="{{ old('store_banner_url', $profile?->store_banner_path) }}"
                                   placeholder="/images/stores/juragan-banner.svg"
                                   class="w-full h-9 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>

                    {{-- Manual Store Open + Auto-Schedule Engine Toggles --}}
                    <div class="pt-4 border-t border-[#F1F5F9] space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="flex items-center justify-between gap-3 p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE]">
                                <div>
                                    <div class="text-xs font-bold text-[#111827]">Status Operasional Manual</div>
                                    <p class="text-[11px] text-[#6B7280] mt-0.5">Nonaktifkan sementara jika toko sedang libur penuh.</p>
                                </div>
                                <input type="hidden" name="is_store_open" value="0">
                                <label class="inline-flex items-center gap-2 shrink-0 cursor-pointer select-none px-3 py-1.5 rounded-lg bg-white border border-[#D1D5DB]">
                                    <input type="checkbox" name="is_store_open" value="1"
                                           @checked((bool) old('is_store_open', $profile?->is_store_open ?? true))
                                           class="w-4 h-4 accent-[#00AA5B]">
                                    <span class="text-xs font-bold text-[#111827]">Toko Buka</span>
                                </label>
                            </div>

                            <div class="flex items-center justify-between gap-3 p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE]">
                                <div>
                                    <div class="text-xs font-bold text-[#111827]">Jadwal Buka-Tutup Otomatis 7 Hari (WIB)</div>
                                    <p class="text-[11px] text-[#6B7280] mt-0.5">Manual Trade ikuti jam buka; Brankas &amp; H2H tetap 24/7.</p>
                                </div>
                                <input type="hidden" name="auto_schedule_enabled" value="0">
                                <label class="inline-flex items-center gap-2 shrink-0 cursor-pointer select-none px-3 py-1.5 rounded-lg bg-white border border-[#D1D5DB]">
                                    <input type="checkbox" name="auto_schedule_enabled" value="1"
                                           @checked((bool) old('auto_schedule_enabled', $profile?->auto_schedule_enabled))
                                           class="w-4 h-4 accent-[#0068E1]">
                                    <span class="text-xs font-bold text-[#111827]">Otomatis</span>
                                </label>
                            </div>

                            <div class="flex items-center justify-between gap-3 p-3.5 rounded-xl bg-[#FFFBEB] border border-[#FDE68A]">
                                <div>
                                    <div class="text-xs font-bold text-[#92400E]">Mode Libur (Vacation Mode)</div>
                                    <p class="text-[11px] text-[#B45309] mt-0.5">Jeda pesanan Manual Trade saat libur; Brankas Instan &amp; H2H tetap jalan 24/7.</p>
                                </div>
                                <input type="hidden" name="is_on_vacation" value="0">
                                <label class="inline-flex items-center gap-2 shrink-0 cursor-pointer select-none px-3 py-1.5 rounded-lg bg-white border border-[#FDE68A]">
                                    <input type="checkbox" name="is_on_vacation" value="1"
                                           @checked((bool) old('is_on_vacation', $profile?->is_on_vacation))
                                           class="w-4 h-4 accent-[#F59E0B]">
                                    <span class="text-xs font-bold text-[#92400E]">Mode Libur</span>
                                </label>
                            </div>

                            <div class="flex items-center justify-between gap-3 p-3.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE]">
                                <div>
                                    <div class="text-xs font-bold text-[#1E3A8A]">Pergeseran SLA Jam Operasional (SLA-Aware)</div>
                                    <p class="text-[11px] text-[#1E40AF] mt-0.5">Pesanan manual di luar jam buka tetap diterima &amp; timer SLA digeser ke jam buka berikutnya.</p>
                                </div>
                                <input type="hidden" name="operating_hours_enabled" value="0">
                                <label class="inline-flex items-center gap-2 shrink-0 cursor-pointer select-none px-3 py-1.5 rounded-lg bg-white border border-[#BFDBFE]">
                                    <input type="checkbox" name="operating_hours_enabled" value="1"
                                           @checked((bool) old('operating_hours_enabled', $profile?->operating_hours_enabled))
                                           class="w-4 h-4 accent-[#0068E1]">
                                    <span class="text-xs font-bold text-[#0068E1]">SLA-Aware</span>
                                </label>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Catatan Mode Libur (Ditampilkan di Etalase)</label>
                                <input type="text" name="vacation_note" value="{{ old('vacation_note', $profile?->vacation_note) }}" maxlength="255"
                                       placeholder="Contoh: Admin sedang libur hingga Senin pukul 08:00 WIB. Produk Instan tetap aktif 24/7."
                                       class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold text-[#374151] mb-1">Zona Waktu Operasional Toko</label>
                                <select name="operating_timezone" class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                                    @foreach(['Asia/Jakarta' => 'Asia/Jakarta (WIB - UTC+7)', 'Asia/Makassar' => 'Asia/Makassar (WITA - UTC+8)', 'Asia/Jayapura' => 'Asia/Jayapura (WIT - UTC+9)'] as $tzKey => $tzLabel)
                                        <option value="{{ $tzKey }}" @selected(old('operating_timezone', $profile?->operating_timezone ?? 'Asia/Jakarta') === $tzKey)>{{ $tzLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- 7-Day Schedule Table --}}
                        <div class="overflow-x-auto border border-[#E4E8EE] rounded-xl">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] uppercase tracking-wider text-[#6B7280]">
                                    <tr>
                                        <th class="px-4 py-2.5 font-semibold">Hari</th>
                                        <th class="px-4 py-2.5 font-semibold">Jam Buka (WIB)</th>
                                        <th class="px-4 py-2.5 font-semibold">Jam Tutup (WIB)</th>
                                        <th class="px-4 py-2.5 font-semibold text-center">Libur / Tutup Seharian</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#F1F5F9]">
                                    @foreach($days as $dow => $dayName)
                                        @php
                                            $hourRow = $hoursMap->get($dow);
                                            $openVal = substr((string) ($hourRow?->open_time ?? '08:00:00'), 0, 5);
                                            $closeVal = substr((string) ($hourRow?->close_time ?? '22:00:00'), 0, 5);
                                            $closedVal = (bool) ($hourRow?->is_closed ?? false);
                                        @endphp
                                        <tr class="row-interactive">
                                            <td class="px-4 py-2.5 font-bold text-[#111827]">
                                                {{ $dayName }}
                                                @if($dow === (int) now()->dayOfWeek)
                                                    <span class="ml-1.5 px-1.5 py-0.5 rounded bg-[#EFF6FF] text-[#0068E1] text-[10px] font-bold">Hari Ini</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <input type="time" name="hours[{{ $dow }}][open_time]" value="{{ $openVal }}"
                                                       class="h-8 px-2.5 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <input type="time" name="hours[{{ $dow }}][close_time]" value="{{ $closeVal }}"
                                                       class="h-8 px-2.5 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                            </td>
                                            <td class="px-4 py-2.5 text-center">
                                                <input type="hidden" name="hours[{{ $dow }}][is_closed]" value="0">
                                                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                                    <input type="checkbox" name="hours[{{ $dow }}][is_closed]" value="1" @checked($closedVal)
                                                           class="w-4 h-4 accent-[#DC2626]">
                                                    <span class="text-[11px] font-medium {{ $closedVal ? 'text-[#DC2626] font-bold' : 'text-[#6B7280]' }}">Tutup</span>
                                                </label>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit"
                                class="btn-tactile px-5 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <span>Simpan Profil &amp; Jam Operasional</span>
                        </button>
                    </div>
                </div>
            </form>

            {{-- Card 2: Buyer Reviews & Official Seller Reply Console --}}
            <section class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 2">
                <div class="px-5 py-4 border-b border-[#F1F5F9] flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <h2 class="text-sm font-bold text-[#111827]">Ulasan Pembeli &amp; Balasan Resmi Toko</h2>
                    </div>
                    <span class="font-mono-tabular text-xs font-semibold text-[#6B7280]">{{ $reviews->count() }} Ulasan</span>
                </div>

                @if($reviews->isEmpty())
                    <div class="p-8 text-center text-xs text-[#6B7280]">
                        Belum ada ulasan pembeli yang masuk untuk toko Anda.
                    </div>
                @else
                    <div class="divide-y divide-[#F1F5F9]">
                        @foreach($reviews as $review)
                            <div class="p-5 space-y-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-[#111827]">{{ $review->buyer?->name ?? 'Pembeli' }}</span>
                                            <div class="flex items-center gap-0.5 text-[#F59E0B]">
                                                @for($s = 1; $s <= 5; $s++)
                                                    <svg class="w-3.5 h-3.5 {{ $s <= (int) $review->rating ? 'fill-[#F59E0B] text-[#F59E0B]' : 'text-[#E5E7EB]' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                @endfor
                                            </div>
                                            <span class="font-mono-tabular text-[11px] font-bold text-[#374151]">{{ $review->rating }}/5</span>
                                        </div>
                                        <div class="text-[11px] text-[#6B7280] mt-0.5">
                                            Produk: <strong class="text-[#374151]">{{ $review->product?->title ?? 'Produk Diarsipkan' }}</strong>
                                            @if($review->order)
                                                &middot; <span class="font-mono-tabular">{{ $review->order->order_code }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="text-[11px] text-[#6B7280] font-mono-tabular">
                                        {{ $review->created_at?->format('d M Y, H:i') }}
                                    </span>
                                </div>

                                <p class="text-xs text-[#1F2937] bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg px-3.5 py-2.5 leading-relaxed">
                                    {{ $review->comment ?: 'Pembeli tidak menuliskan komentar tambahan.' }}
                                </p>

                                @if($review->seller_reply)
                                    <div class="ml-4 pl-3.5 border-l-2 border-[#0068E1] bg-[#EFF6FF]/60 rounded-r-lg p-3 text-xs space-y-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-bold text-[#0068E1]">Balasan Penjual Terpublikasi</span>
                                            <span class="font-mono-tabular text-[10px] text-[#6B7280]">{{ $review->replied_at?->format('d M Y, H:i') }}</span>
                                        </div>
                                        <p class="text-[#1F2937]">{{ $review->seller_reply }}</p>
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('seller.reviews.reply', $review->id) }}" class="flex flex-col sm:flex-row gap-2">
                                    @csrf
                                    <input type="text" name="seller_reply" value="{{ $review->seller_reply }}" required minlength="3" maxlength="1000"
                                           placeholder="Tulis balasan resmi toko untuk ulasan pembeli ini..."
                                           class="flex-1 h-9 px-3 text-xs bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                    <button type="submit"
                                            class="btn-tactile px-4 h-9 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-bold shrink-0 cursor-pointer">
                                        {{ $review->seller_reply ? 'Perbarui Balasan' : 'Kirim Balasan' }}
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
