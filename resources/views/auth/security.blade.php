@extends('layouts.app')

@section('title', 'Keamanan Akun & PIN Dompet 6-Digit — NexaTrade')

@section('content')
    <div class="max-w-4xl mx-auto space-y-5">
        {{-- Header --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Keamanan Akun &amp; PIN Dompet 6-Digit</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Lindungi seluruh penarikan saldo, checkout memakai dompet, pelunasan tunggakan, dan pembelian kredit promosi dengan PIN terenkripsi.</p>
                </div>
            </div>

            @if($user->hasWalletPin())
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-xs font-bold">
                    <svg class="w-4 h-4 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                    <span>PIN Dompet Aktif</span>
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-xs font-bold">
                    <svg class="w-4 h-4 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <span>PIN Belum Diatur</span>
                </span>
            @endif
        </div>

        @if($user->isWalletPinLocked())
            <div class="p-4 rounded-xl bg-[#FEF2F2] border border-[#FECACA] text-xs text-[#991B1B] flex items-center justify-between gap-3">
                <div>
                    <div class="font-extrabold text-[#B91C1C]">PIN Dompet Terkunci Sementara (Proteksi 5x Salah)</div>
                    <p class="mt-0.5">PIN Anda terkunci hingga <strong class="font-mono-tabular">{{ $user->wallet_pin_locked_until?->format('d M Y H:i:s') }} WIB</strong> untuk melindungi saldo dompet Anda dari percobaan tidak sah.</p>
                </div>
                <span class="font-mono-tabular text-xs font-extrabold px-2.5 py-1 rounded-lg bg-white border border-[#FECACA] text-[#DC2626] shrink-0"
                      x-data="liveCountdown('{{ $user->wallet_pin_locked_until?->toIso8601String() }}')" x-text="formatted">--:--</span>
            </div>
        @endif

        {{-- Card 0: Email & Unique Phone OTP Verification (Anti-Bot Tuyul & Promo Guard) --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 text-xs animate-enter" style="--stagger-index: 1">
            <div class="pb-3 border-b border-[#F1F5F9] flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-[#111827]">Verifikasi Identitas OTP (Email &amp; Nomor Telepon Unik)</h2>
                    <p class="text-[11px] text-[#6B7280] mt-0.5">
                        Wajib terverifikasi keduanya untuk mengklaim <strong class="text-[#111827]">Voucher Subsidi Platform</strong> dan membeli produk <strong class="text-[#111827]">Flash Sale</strong> (Proteksi Anti-Tuyul Blind-Index).
                    </p>
                </div>
                @if($user->isFullyVerifiedForPromo())
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[11px] font-bold">
                        <svg class="w-3.5 h-3.5 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                        <span>Promo &amp; Flash Sale Eligible</span>
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[11px] font-bold">
                        <svg class="w-3.5 h-3.5 text-[#F05A28]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                        <span>Verifikasi OTP Belum Lengkap</span>
                    </span>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Email OTP Column --}}
                <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[#6B7280]">Kanal 1 &middot; Email Akun</div>
                            <div class="font-mono-tabular font-bold text-[#111827] mt-0.5">{{ $user->email }}</div>
                        </div>
                        @if($user->email_verified_at)
                            <span class="px-2 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[10px] font-bold">
                                Terverifikasi
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold">
                                Belum Verifikasi
                            </span>
                        @endif
                    </div>

                    @if($user->email_verified_at)
                        <div class="text-[11px] text-[#047857] font-mono-tabular">
                            Diverifikasi pada {{ $user->email_verified_at->format('d M Y, H:i') }} WIB
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <form method="POST" action="{{ route('account.security.otp.send') }}">
                                @csrf
                                <input type="hidden" name="channel" value="email">
                                <button type="submit" class="btn-tactile h-9 px-3 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] font-bold cursor-pointer">
                                    Kirim OTP Email
                                </button>
                            </form>

                            <form method="POST" action="{{ route('account.security.otp.verify') }}" class="flex items-center gap-1.5 flex-1">
                                @csrf
                                <input type="hidden" name="channel" value="email">
                                <input type="text" name="code" required inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="6-Digit OTP"
                                       class="w-full h-9 px-2.5 font-mono-tabular text-center bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                <button type="submit" class="btn-tactile h-9 px-3 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer shrink-0">
                                    Verifikasi
                                </button>
                            </form>
                        </div>
                    @endif
                </div>

                {{-- Phone OTP Column --}}
                <div class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[#6B7280]">Kanal 2 &middot; Nomor Telepon (AES-256 + Blind Hash)</div>
                            <div class="font-mono-tabular font-bold text-[#111827] mt-0.5">
                                {{ $user->maskedPhone() ?? 'Belum Ditautkan' }}
                            </div>
                        </div>
                        @if($user->phone_verified_at)
                            <span class="px-2 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[10px] font-bold">
                                Terverifikasi
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold">
                                Belum Verifikasi
                            </span>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('account.security.phone.bind') }}" class="flex items-center gap-2">
                        @csrf
                        <input type="text" name="phone" required value="{{ old('phone', $user->decryptedPhone() ?? '') }}" placeholder="Contoh: 081234567890"
                               class="w-full h-9 px-3 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                        <button type="submit" class="btn-tactile h-9 px-3 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#D1D5DB] text-[#111827] font-bold cursor-pointer shrink-0">
                            Simpan Nomor
                        </button>
                    </form>

                    @if($user->phone_verified_at)
                        <div class="text-[11px] text-[#047857] font-mono-tabular">
                            Diverifikasi pada {{ $user->phone_verified_at->format('d M Y, H:i') }} WIB
                        </div>
                    @elseif($user->phone_blind_hash)
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <form method="POST" action="{{ route('account.security.otp.send') }}">
                                @csrf
                                <input type="hidden" name="channel" value="phone">
                                <button type="submit" class="btn-tactile h-9 px-3 rounded-lg bg-white hover:bg-[#EFF6FF] border border-[#0068E1] text-[#0068E1] font-bold cursor-pointer">
                                    Kirim OTP Nomor
                                </button>
                            </form>

                            <form method="POST" action="{{ route('account.security.otp.verify') }}" class="flex items-center gap-1.5 flex-1">
                                @csrf
                                <input type="hidden" name="channel" value="phone">
                                <input type="text" name="code" required inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="6-Digit OTP"
                                       class="w-full h-9 px-2.5 font-mono-tabular text-center bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                                <button type="submit" class="btn-tactile h-9 px-3 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white font-bold cursor-pointer shrink-0">
                                    Verifikasi
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 items-start">
            {{-- Card 1: Set / Update 6-Digit Wallet PIN --}}
            <form method="POST" action="{{ route('account.security.pin') }}"
                  class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 text-xs animate-enter" style="--stagger-index: 1">
                @csrf
                <div class="pb-3 border-b border-[#F1F5F9]">
                    <h2 class="text-sm font-bold text-[#111827]">
                        {{ $user->hasWalletPin() ? 'Perbarui PIN Dompet 6-Digit' : 'Aktifkan PIN Dompet 6-Digit' }}
                    </h2>
                    <p class="text-[11px] text-[#6B7280] mt-0.5">
                        Hindari kombinasi mudah ditebak (`000000`, `111111`, `123456`). Salah 5x otomatis mengunci PIN selama 15 menit.
                    </p>
                </div>

                @if($user->wallet_pin_set_at)
                    <div class="p-2.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] font-mono-tabular text-[11px]">
                        Terakhir diatur: {{ $user->wallet_pin_set_at->format('d M Y, H:i') }} WIB &middot; Gagal saat ini: {{ $user->wallet_pin_attempts }}/5
                    </div>
                @endif

                <div>
                    <label class="block font-semibold text-[#374151] mb-1">Kata Sandi Akun Saat Ini *</label>
                    <input type="password" name="current_password" required placeholder="Konfirmasi password login Anda"
                           class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">PIN Baru (6 Angka) *</label>
                        <input type="password" name="pin" required inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="••••••"
                               class="w-full h-10 px-3 font-mono-tabular text-base tracking-[0.3em] text-center bg-[#F8FAFC] border border-[#0068E1] rounded-lg focus:bg-white focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Konfirmasi PIN *</label>
                        <input type="password" name="pin_confirmation" required inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="••••••"
                               class="w-full h-10 px-3 font-mono-tabular text-base tracking-[0.3em] text-center bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                </div>

                <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                    {{ $user->hasWalletPin() ? 'Simpan PIN Baru' : 'Aktifkan PIN Dompet Sekarang' }}
                </button>
            </form>

            {{-- Card 2: Change Account Password --}}
            <form method="POST" action="{{ route('account.security.password') }}"
                  class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 text-xs animate-enter" style="--stagger-index: 2">
                @csrf
                <div class="pb-3 border-b border-[#F1F5F9]">
                    <h2 class="text-sm font-bold text-[#111827]">Ubah Kata Sandi Login</h2>
                    <p class="text-[11px] text-[#6B7280] mt-0.5">Gunakan kata sandi minimal 8 karakter yang unik dan tidak dipakai di layanan lain.</p>
                </div>

                <div>
                    <label class="block font-semibold text-[#374151] mb-1">Kata Sandi Saat Ini *</label>
                    <input type="password" name="current_password" required
                           class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <div>
                    <label class="block font-semibold text-[#374151] mb-1">Kata Sandi Baru (Min. 8 Karakter) *</label>
                    <input type="password" name="password" required minlength="8"
                           class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <div>
                    <label class="block font-semibold text-[#374151] mb-1">Konfirmasi Kata Sandi Baru *</label>
                    <input type="password" name="password_confirmation" required minlength="8"
                           class="w-full h-9 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <button type="submit" class="btn-tactile w-full h-10 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#0068E1] text-[#0068E1] font-bold cursor-pointer">
                    Perbarui Kata Sandi
                </button>
            </form>
        </div>

        {{-- Card 3: Self-Service Wallet PIN 6-Digit Reset (Single-Use 15-Minute Token) --}}
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4 text-xs animate-enter" style="--stagger-index: 3">
            <div class="pb-3 border-b border-[#F1F5F9] flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-bold text-[#111827]">Lupa PIN Dompet? Ajukan Reset Token</h2>
                    <p class="text-[11px] text-[#6B7280] mt-0.5">
                        Terbitkan token pemulihan PIN sekali pakai (berlaku 15 menit, rate-limited 3x/15 menit) untuk mengatur ulang PIN 6-digit sekaligus membuka lockout 5x salah.
                    </p>
                </div>
                <span class="px-2.5 py-1 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] text-[10px] font-bold font-mono-tabular">
                    SINGLE-USE 15M TOKEN
                </span>
            </div>

            @if(session('pin_reset_preview_token'))
                <div class="p-3.5 rounded-xl bg-[#FFF4ED] border border-[#FED7AA] space-y-1 text-[#9A3412]">
                    <div class="font-bold">Pratinjau Token Reset PIN (Mode Demo Lokal)</div>
                    <div class="font-mono-tabular text-sm font-extrabold text-[#C2410C] select-all">{{ session('pin_reset_preview_token') }}</div>
                    <p class="text-[10px]">Salin token di atas ke formulir verifikasi di samping kanan (tersembunyi 100% di produksi).</p>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 items-start">
                <form method="POST" action="{{ route('account.security.pin-reset.request') }}" class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-3">
                    @csrf
                    <div class="font-bold text-[#111827]">Langkah 1: Minta Token Reset PIN</div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Kata Sandi Login Akun (Opsional bila email aktif)</label>
                        <input type="password" name="current_password" placeholder="Masukkan kata sandi akun Anda"
                               class="w-full h-9 px-3 bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <button type="submit" class="btn-tactile w-full h-9 rounded-lg bg-[#F05A28] hover:bg-[#D94B1C] text-white font-bold cursor-pointer">
                        Ajukan Token Reset PIN
                    </button>
                </form>

                <form method="POST" action="{{ route('account.security.pin-reset.confirm') }}" class="p-4 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-3">
                    @csrf
                    <div class="font-bold text-[#111827]">Langkah 2: Verifikasi Token &amp; Atur PIN Baru</div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Token Reset PIN *</label>
                        <input type="text" name="token" value="{{ session('pin_reset_preview_token', '') }}" required placeholder="Tempel token reset PIN..."
                               class="w-full h-9 px-3 font-mono-tabular bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">PIN Baru (6 Angka) *</label>
                            <input type="password" name="new_pin" required inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="••••••"
                                   class="w-full h-9 px-3 font-mono-tabular text-center tracking-[0.25em] bg-white border border-[#0068E1] rounded-lg focus:outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold text-[#374151] mb-1">Konfirmasi PIN *</label>
                            <input type="password" name="new_pin_confirmation" required inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="••••••"
                                   class="w-full h-9 px-3 font-mono-tabular text-center tracking-[0.25em] bg-white border border-[#D1D5DB] rounded-lg focus:border-[#0068E1] focus:outline-none">
                        </div>
                    </div>
                    <button type="submit" class="btn-tactile w-full h-9 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white font-bold cursor-pointer">
                        Reset PIN &amp; Buka Lockout
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
