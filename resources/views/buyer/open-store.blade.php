@extends('layouts.app')

@section('title', 'Buka Toko & Verifikasi KYC — NexaTrade')

@section('content')
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Header Card --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-6 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
            <div class="flex items-start gap-3.5">
                <div class="w-11 h-11 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>
                </div>
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Buka Toko &amp; Verifikasi KYC Merchant</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        Mulai jualan voucher, top-up H2H, atau akun game dengan perlindungan Escrow otomatis dan enkripsi identitas e-KTP AES-256.
                    </p>
                </div>
            </div>

            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[11px] font-bold text-[#047857]">
                <svg class="w-3.5 h-3.5 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Private Storage &amp; AES-256</span>
            </span>
        </div>

        {{-- STATE 1: Already Verified Seller --}}
        @if($user->isSeller() && $user->sellerProfile)
            <div class="bg-white border border-[#A7F3D0] rounded-xl p-6 space-y-4 animate-enter" style="--stagger-index: 1">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#ECFDF5] text-[#00AA5B] flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-[#065F46]">Toko Anda Sudah Aktif &amp; Terverifikasi KYC</h2>
                        <p class="text-xs text-[#4B5563] mt-1">
                            Toko <strong class="text-[#111827]">{{ $user->sellerProfile->store_name }}</strong>
                            (<span class="font-mono-tabular text-[#0068E1]">/toko/{{ $user->sellerProfile->slug }}</span>)
                            siap melayani transaksi Brankas Instan, H2H, dan Manual Trade.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2.5 pt-1">
                    <a href="{{ route('seller.dashboard') }}"
                       class="btn-tactile px-4 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-1.5">
                        <span>Masuk ke TokoKu Studio</span>
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                    <a href="{{ route('stores.toko', $user->sellerProfile->slug) }}"
                       class="btn-tactile px-4 py-2.5 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#E4E8EE] text-[#111827] text-xs font-bold">
                        Lihat Etalase Publik
                    </a>
                </div>
            </div>

        {{-- STATE 2: Pending KYC Review Tracker --}}
        @elseif($latestKyc && $latestKyc->status === 'pending')
            <div class="bg-white border border-[#BFDBFE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 1">
                <div class="px-6 py-4 bg-[#EFF6FF] border-b border-[#BFDBFE] flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#0068E1] badge-live-pulse"></span>
                        <h2 class="text-sm font-extrabold text-[#111827]">Sedang Ditinjau Tim Keamanan NexaTrade</h2>
                    </div>
                    <span class="font-mono-tabular text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-white border border-[#BFDBFE] text-[#0068E1]">
                        KYC #{{ $latestKyc->id }} &middot; PENDING
                    </span>
                </div>

                <div class="p-6 space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-[#6B7280]">Pratinjau Toko Diajukan</div>
                            <div class="text-base font-extrabold text-[#111827]">{{ $latestKyc->proposed_store_name }}</div>
                            <div class="font-mono-tabular text-[11px] text-[#0068E1]">nexatrade.id/toko/{{ $latestKyc->proposed_store_slug }}</div>
                            @if($latestKyc->proposed_store_tagline)
                                <p class="text-[#4B5563]">{{ $latestKyc->proposed_store_tagline }}</p>
                            @endif
                        </div>

                        <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-2">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-[#6B7280]">Identitas Terenkripsi</div>
                            <div class="flex justify-between">
                                <span class="text-[#6B7280]">Nama Sesuai KTP</span>
                                <strong class="text-[#111827]">{{ $latestKyc->full_legal_name }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[#6B7280]">NIK Terlindungi</span>
                                <strong class="font-mono-tabular text-[#111827]">{{ $latestKyc->maskedNik() }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[#6B7280]">Diajukan Pada</span>
                                <strong class="font-mono-tabular text-[#111827]">{{ $latestKyc->created_at?->format('d M Y, H:i') }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-[#E4E8EE] rounded-xl p-3 bg-[#F8FAFC] space-y-2">
                            <div class="text-[11px] font-bold text-[#374151]">Foto e-KTP (Private Stream)</div>
                            <img src="{{ route('admin.kyc.document', ['kyc' => $latestKyc->id, 'type' => 'id_card']) }}" alt="Dokumen KTP"
                                 class="w-full h-40 object-cover rounded-lg border border-[#E4E8EE] bg-white">
                        </div>
                        <div class="border border-[#E4E8EE] rounded-xl p-3 bg-[#F8FAFC] space-y-2">
                            <div class="text-[11px] font-bold text-[#374151]">Selfie Memegang e-KTP (Private Stream)</div>
                            <img src="{{ route('admin.kyc.document', ['kyc' => $latestKyc->id, 'type' => 'selfie']) }}" alt="Selfie KTP"
                                 class="w-full h-40 object-cover rounded-lg border border-[#E4E8EE] bg-white">
                        </div>
                    </div>
                </div>
            </div>

        {{-- STATE 3: New Application OR Rejected Re-Submission Form --}}
        @else
            @if($latestKyc && $latestKyc->status === 'rejected')
                <div class="bg-[#FEF2F2] border border-[#FECACA] rounded-xl p-5 space-y-2 animate-enter" style="--stagger-index: 1">
                    <div class="flex items-center gap-2 text-xs font-extrabold text-[#B91C1C]">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                        <span>Pengajuan KYC Sebelumnya Ditolak ({{ $latestKyc->reviewed_at?->format('d M Y, H:i') }})</span>
                    </div>
                    <p class="text-xs text-[#991B1B] leading-relaxed">
                        <strong>Alasan Penolakan:</strong> {{ $latestKyc->rejection_reason }}
                    </p>
                    <p class="text-[11px] text-[#7F1D1D]">
                        Kunci NIK Anda telah otomatis dibuka kembali. Silakan unggah ulang dokumen yang lebih jelas pada formulir di bawah ini.
                    </p>
                </div>
            @endif

            <form method="POST" action="{{ route('buyer.open-store.submit') }}" enctype="multipart/form-data"
                  x-data="{
                      storeName: @js(old('proposed_store_name', $latestKyc?->proposed_store_name ?? '')),
                      storeSlug: @js(old('proposed_store_slug', $latestKyc?->proposed_store_slug ?? '')),
                      customSlug: false,
                      idCardPreview: null,
                      selfiePreview: null,
                      submitting: false,
                      slugify(val) {
                          return (val || '').toLowerCase().trim()
                              .replace(/[^a-z0-9\s-]/g, '')
                              .replace(/[\s_-]+/g, '-')
                              .replace(/^-+|-+$/g, '');
                      },
                      syncSlug() {
                          if (!this.customSlug) {
                              this.storeSlug = this.slugify(this.storeName);
                          }
                      },
                      previewFile(event, target) {
                          const file = event.target.files && event.target.files[0];
                          if (!file) { this[target] = null; return; }
                          const reader = new FileReader();
                          reader.onload = (e) => { this[target] = e.target.result; };
                          reader.readAsDataURL(file);
                      }
                  }"
                  @submit="submitting = true"
                  class="space-y-5 animate-enter" style="--stagger-index: 2">
                @csrf

                {{-- Section 1: Store Identity --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4">
                    <div class="pb-3 border-b border-[#F1F5F9]">
                        <h2 class="text-sm font-bold text-[#111827]">1. Identitas Toko Baru</h2>
                        <p class="text-[11px] text-[#6B7280] mt-0.5">Nama dan alamat etalase publik yang akan dilihat pembeli.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Nama Toko *</label>
                            <input type="text" name="proposed_store_name" x-model="storeName" @input="syncSlug()" required minlength="3" maxlength="100"
                                   placeholder="Contoh: Garuda TopUp Official"
                                   class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">URL Etalase Toko *</label>
                            <div class="flex items-center h-10 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg overflow-hidden focus-within:bg-white focus-within:border-[#0068E1]">
                                <span class="px-2.5 text-[11px] font-mono-tabular text-[#6B7280] bg-[#F1F5F9] h-full flex items-center border-r border-[#E4E8EE] shrink-0">
                                    nexatrade.id/toko/
                                </span>
                                <input type="text" name="proposed_store_slug" x-model="storeSlug" @input="customSlug = true" maxlength="120"
                                       placeholder="garuda-topup-official"
                                       class="flex-1 h-full px-2.5 font-mono-tabular text-xs bg-transparent focus:outline-none">
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block font-semibold text-[#374151] mb-1">Tagline Toko</label>
                            <input type="text" name="proposed_store_tagline" value="{{ old('proposed_store_tagline', $latestKyc?->proposed_store_tagline) }}" maxlength="150"
                                   placeholder="Contoh: Voucher Resmi & Akun Terverifikasi Pengiriman Kilat 24 Jam"
                                   class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>
                </div>

                {{-- Section 2: Legal Identity & Anti-Fraud NIK Protection --}}
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 sm:p-6 space-y-4">
                    <div class="pb-3 border-b border-[#F1F5F9]">
                        <h2 class="text-sm font-bold text-[#111827]">2. Verifikasi Identitas KTP (KYC Anti-Duplikasi)</h2>
                        <p class="text-[11px] text-[#6B7280] mt-0.5">Satu NIK hanya dapat digunakan untuk satu toko aktif demi mencegah penipuan multi-akun.</p>
                    </div>

                    <div class="p-3.5 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-[11px] text-[#065F46] flex items-start gap-2.5">
                        <svg class="w-4 h-4 text-[#00AA5B] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                        <div>
                            <strong>Keamanan Data Terenkripsi:</strong> NIK Anda dienkripsi menggunakan <strong>AES-256</strong> dan dilindungi <strong>HMAC-SHA256 Blind-Hash</strong>. Foto KTP &amp; Selfie disimpan di disk privat non-publik dan hanya dapat diakses oleh Tim Risiko Admin NexaTrade.
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Nama Lengkap Sesuai e-KTP *</label>
                            <input type="text" name="full_legal_name" value="{{ old('full_legal_name', $latestKyc?->full_legal_name ?? $user->name) }}" required minlength="3" maxlength="120"
                                   class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">NIK e-KTP (16 Digit Angka) *</label>
                            <input type="text" name="nik" required pattern="\d{16}" minlength="16" maxlength="16" inputmode="numeric"
                                   placeholder="3171012345678901"
                                   class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                        </div>

                        {{-- Upload Foto KTP --}}
                        <div class="space-y-2">
                            <label class="block font-semibold text-[#374151]">Unggah Foto e-KTP (JPG/PNG/WEBP, maks 3MB) *</label>
                            <input type="file" name="id_card_file" accept=".jpg,.jpeg,.png,.webp" required
                                   @change="previewFile($event, 'idCardPreview')"
                                   class="w-full text-xs text-[#4B5563] file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border file:border-[#D1D5DB] file:bg-white file:text-xs file:font-semibold file:text-[#111827] hover:file:bg-[#F8FAFC]">
                            <template x-if="idCardPreview">
                                <img :src="idCardPreview" alt="Preview KTP" class="w-full h-36 object-cover rounded-lg border border-[#BFDBFE]">
                            </template>
                        </div>

                        {{-- Upload Selfie KTP --}}
                        <div class="space-y-2">
                            <label class="block font-semibold text-[#374151]">Unggah Selfie Memegang e-KTP (JPG/PNG/WEBP, maks 3MB) *</label>
                            <input type="file" name="selfie_file" accept=".jpg,.jpeg,.png,.webp" required
                                   @change="previewFile($event, 'selfiePreview')"
                                   class="w-full text-xs text-[#4B5563] file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border file:border-[#D1D5DB] file:bg-white file:text-xs file:font-semibold file:text-[#111827] hover:file:bg-[#F8FAFC]">
                            <template x-if="selfiePreview">
                                <img :src="selfiePreview" alt="Preview Selfie KTP" class="w-full h-36 object-cover rounded-lg border border-[#BFDBFE]">
                            </template>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3 border-t border-[#F1F5F9]">
                        <button type="submit" :disabled="submitting"
                                class="btn-tactile px-6 py-2.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-2 cursor-pointer disabled:opacity-60">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                            <span x-text="submitting ? 'Mengenkripsi & Mengirim KYC...' : 'Kirim Pengajuan Buka Toko'"></span>
                        </button>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endsection
