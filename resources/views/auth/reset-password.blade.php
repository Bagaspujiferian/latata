@extends('layouts.app')

@section('title', 'Atur Ulang Kata Sandi — NexaTrade')

@section('content')
    <div class="max-w-md mx-auto py-6 sm:py-10 animate-enter" style="--stagger-index: 0">
        <div class="bg-white border border-[#E4E8EE] rounded-2xl overflow-hidden shadow-[0_12px_32px_-12px_rgba(15,23,42,0.12)]">
            <div class="px-6 py-5 bg-[#F8FAFC] border-b border-[#E4E8EE] text-center">
                <div class="w-11 h-11 rounded-xl bg-[#ECFDF5] border border-[#A7F3D0] text-[#00AA5B] mx-auto flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <h1 class="text-lg font-extrabold text-[#111827]">Atur Kata Sandi Baru</h1>
                <p class="text-xs text-[#6B7280] mt-1">Seluruh sesi lama akan otomatis dihentikan setelah kata sandi diperbarui.</p>
            </div>

            <form method="POST" action="{{ route('password.update') }}" class="p-6 space-y-4 text-xs" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="block font-semibold text-[#374151] mb-1">Alamat Email *</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <div>
                    <label for="password" class="block font-semibold text-[#374151] mb-1">Kata Sandi Baru (Min. 8 Karakter) *</label>
                    <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <div>
                    <label for="password_confirmation" class="block font-semibold text-[#374151] mb-1">Konfirmasi Kata Sandi Baru *</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"
                           class="w-full h-10 px-3.5 text-sm bg-[#F8FAFC] text-[#111827] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                </div>

                <button type="submit" :disabled="submitting"
                        class="btn-tactile w-full h-11 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-sm font-bold flex items-center justify-center gap-2 cursor-pointer disabled:opacity-65">
                    <span x-text="submitting ? 'Menyimpan...' : 'Simpan Kata Sandi Baru'"></span>
                </button>
            </form>
        </div>
    </div>
@endsection
