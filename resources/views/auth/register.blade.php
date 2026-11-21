@extends('layouts.app')

@section('title', 'Daftar Akun Baru — NexaTrade')

@section('content')
    <div class="max-w-md mx-auto py-6 sm:py-10 animate-enter" style="--stagger-index: 0">
        <div class="bg-white border border-[#E4E8EE] rounded-2xl overflow-hidden shadow-[0_12px_32px_-12px_rgba(15,23,42,0.12)]">
            <div class="px-6 py-5 bg-[#F8FAFC] border-b border-[#E4E8EE] text-center">
                <div class="w-11 h-11 rounded-xl bg-[#00AA5B] text-white mx-auto flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                </div>
                <h1 class="text-lg font-extrabold text-[#111827]">Buat Akun NexaTrade</h1>
                <p class="text-xs text-[#6B7280] mt-1">Otomatis mengaktifkan Dompet Escrow &amp; Garansi Safe Trading.</p>
            </div>

            <form method="POST" action="{{ route('register.submit') }}" class="p-6 space-y-4 text-xs" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <div>
                    <label for="name" class="block font-semibold text-[#374151] mb-1">Nama Lengkap *</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="Nama lengkap sesuai identitas"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border {{ $errors->has('name') ? 'border-[#DC2626]' : 'border-[#D1D5DB]' }} rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    @error('name')
                        <p class="text-[11px] text-[#DC2626] mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block font-semibold text-[#374151] mb-1">Alamat Email *</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required placeholder="nama@email.com"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border {{ $errors->has('email') ? 'border-[#DC2626]' : 'border-[#D1D5DB]' }} rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    @error('email')
                        <p class="text-[11px] text-[#DC2626] mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block font-semibold text-[#374151] mb-1">Kata Sandi (Min. 8 Karakter) *</label>
                    <input id="password" type="password" name="password" required minlength="8" placeholder="Buat kata sandi kuat"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border {{ $errors->has('password') ? 'border-[#DC2626]' : 'border-[#D1D5DB]' }} rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    @error('password')
                        <p class="text-[11px] text-[#DC2626] mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block font-semibold text-[#374151] mb-1">Konfirmasi Kata Sandi *</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" placeholder="Ulangi kata sandi"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <button type="submit" :disabled="submitting"
                        class="btn-tactile w-full h-11 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                    <svg x-show="submitting" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span x-text="submitting ? 'Membuat Dompet Escrow...' : 'Daftar &amp; Aktifkan Akun'"></span>
                </button>

                <div class="pt-4 border-t border-[#F1F5F9] text-center text-[#6B7280]">
                    Sudah punya akun?
                    <a href="{{ route('login') }}" class="font-bold text-[#0068E1] hover:underline">Masuk di sini</a>
                </div>
            </form>
        </div>
    </div>
@endsection
