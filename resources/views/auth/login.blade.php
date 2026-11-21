@extends('layouts.app')

@section('title', 'Masuk ke NexaTrade — Marketplace Digital Gaming & Escrow')

@section('content')
    <div class="max-w-md mx-auto py-6 sm:py-10 animate-enter" style="--stagger-index: 0">
        <div class="bg-white border border-[#E4E8EE] rounded-2xl overflow-hidden shadow-[0_12px_32px_-12px_rgba(15,23,42,0.12)]">
            <div class="px-6 py-5 bg-[#F8FAFC] border-b border-[#E4E8EE] text-center">
                <div class="w-11 h-11 rounded-xl bg-[#0068E1] text-white mx-auto flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 18V6l9 12V6"/><path d="M18 6v12"/></svg>
                </div>
                <h1 class="text-lg font-extrabold text-[#111827]">Masuk ke Akun NexaTrade</h1>
                <p class="text-xs text-[#6B7280] mt-1">Transaksi aman dengan Brankas AES-256 dan Escrow otomatis.</p>
            </div>

            <form method="POST" action="{{ route('login.submit') }}" class="p-6 space-y-4 text-xs" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <div>
                    <label for="email" class="block font-semibold text-[#374151] mb-1">Alamat Email *</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                           placeholder="nama@email.com"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border {{ $errors->has('email') ? 'border-[#DC2626]' : 'border-[#D1D5DB]' }} rounded-lg focus:bg-white focus:border-[#0068E1] focus:ring-2 focus:ring-[#0068E1]/15 focus:outline-none">
                    @error('email')
                        <p class="text-[11px] text-[#DC2626] mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block font-semibold text-[#374151] mb-1">Kata Sandi *</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                           placeholder="Masukkan kata sandi akun Anda"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:ring-2 focus:ring-[#0068E1]/15 focus:outline-none">
                </div>

                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 text-[#4B5563] cursor-pointer">
                        <input type="checkbox" name="remember" value="1" class="accent-[#0068E1] rounded">
                        <span>Ingat sesi saya</span>
                    </label>
                    <span class="text-[11px] text-[#6B7280] inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                        <span>Rate-Limited Auth</span>
                    </span>
                </div>

                <button type="submit" :disabled="submitting"
                        class="btn-tactile w-full h-11 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                    <svg x-show="submitting" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span x-text="submitting ? 'Memverifikasi...' : 'Masuk Sekarang'"></span>
                </button>

                <div class="pt-4 border-t border-[#F1F5F9] text-center text-[#6B7280]">
                    Belum punya akun NexaTrade?
                    <a href="{{ route('register') }}" class="font-bold text-[#0068E1] hover:underline">Daftar Gratis</a>
                </div>
            </form>
        </div>
    </div>
@endsection
