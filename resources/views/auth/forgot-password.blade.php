@extends('layouts.app')

@section('title', 'Lupa Kata Sandi — NexaTrade')

@section('content')
    <div class="max-w-md mx-auto py-6 sm:py-10 animate-enter" style="--stagger-index: 0">
        <div class="bg-white border border-[#E4E8EE] rounded-2xl overflow-hidden shadow-[0_12px_32px_-12px_rgba(15,23,42,0.12)]">
            <div class="px-6 py-5 bg-[#F8FAFC] border-b border-[#E4E8EE] text-center">
                <div class="w-11 h-11 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] mx-auto flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                </div>
                <h1 class="text-lg font-extrabold text-[#111827]">Pulihkan Kata Sandi Akun</h1>
                <p class="text-xs text-[#6B7280] mt-1">Masukkan email akun Anda. Tautan reset terenkripsi berlaku selama 30 menit.</p>
            </div>

            <div class="p-6 space-y-4 text-xs">
                @if(session('recovery_preview_url'))
                    <div class="p-3.5 rounded-xl bg-[#EFF6FF] border border-[#BFDBFE] space-y-2">
                        <div class="font-bold text-[#1E40AF]">Pratinjau Mode Demo Lokal (Tanpa SMTP)</div>
                        <p class="text-[11px] text-[#374151]">Tautan reset berikut hanya tampil saat Demo Mode aktif (tersembunyi 100% di produksi):</p>
                        <a href="{{ session('recovery_preview_url') }}"
                           class="btn-tactile block text-center py-2 px-3 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold">
                            Buka Halaman Reset Kata Sandi &rarr;
                        </a>
                        <div class="font-mono-tabular text-[10px] text-[#4B5563] break-all">Token: {{ session('recovery_preview_token') }}</div>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="space-y-4" x-data="{ submitting: false }" @submit="submitting = true">
                    @csrf
                    <div>
                        <label for="email" class="block font-semibold text-[#374151] mb-1">Alamat Email Terdaftar *</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                               placeholder="nama@email.com"
                               class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border {{ $errors->has('email') ? 'border-[#DC2626]' : 'border-[#D1D5DB]' }} rounded-lg focus:bg-white focus:border-[#0068E1] focus:ring-2 focus:ring-[#0068E1]/15 focus:outline-none">
                        @error('email')
                            <p class="text-[11px] text-[#DC2626] mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" :disabled="submitting"
                            class="btn-tactile w-full h-11 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                        <svg x-show="submitting" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        <span x-text="submitting ? 'Menerbitkan Token...' : 'Kirim Tautan Reset Kata Sandi'"></span>
                    </button>
                </form>

                <div class="pt-4 border-t border-[#F1F5F9] text-center text-[#6B7280]">
                    Ingat kata sandi Anda?
                    <a href="{{ route('login') }}" class="font-bold text-[#0068E1] hover:underline">Kembali ke Halaman Masuk</a>
                </div>
            </div>
        </div>
    </div>
@endsection
