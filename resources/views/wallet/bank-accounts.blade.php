@extends('layouts.app')

@section('title', 'Rekening Bank Tersimpan — NexaTrade')

@section('content')
    <div class="{{ $user->isSeller() ? 'flex flex-col lg:flex-row gap-6 items-start' : 'max-w-5xl mx-auto space-y-5' }}"
         x-data="{ showAddModal: false }">
        @if($user->isSeller())
            <x-seller-sidebar :profile="$user->sellerProfile" />
        @endif

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Rekening Bank &amp; E-Wallet Tersimpan</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        Simpan rekening tujuan penarikan saldo Anda. Rekening yang sedang dipakai pada penarikan <strong class="text-[#111827]">PENDING</strong> terlindungi dari penghapusan.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    @if($user->isSeller())
                        <a href="{{ route('seller.wallet') }}"
                           class="btn-tactile px-3.5 py-2 rounded-lg bg-white hover:bg-[#F8FAFC] border border-[#E4E8EE] text-xs font-semibold text-[#111827]">
                            Kembali ke Tarik Saldo
                        </a>
                    @endif
                    <button type="button" @click="showAddModal = true"
                            class="btn-tactile px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        <span>Tambah Rekening Baru</span>
                    </button>
                </div>
            </div>

            {{-- Saved Accounts Grid --}}
            @if($accounts->isEmpty())
                <div class="bg-white border border-[#E4E8EE] rounded-xl p-12 text-center animate-enter" style="--stagger-index: 1">
                    <div class="w-12 h-12 rounded-full bg-[#EFF6FF] text-[#0068E1] mx-auto flex items-center justify-center mb-3">
                        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    </div>
                    <h2 class="text-base font-bold text-[#111827]">Belum Ada Rekening Tersimpan</h2>
                    <p class="text-xs text-[#6B7280] mt-1 max-w-md mx-auto">
                        Tambahkan rekening bank (BCA, BRI, BNI, Mandiri, Bank Jago) atau e-wallet (GoPay, DANA, OVO) untuk pencairan saldo Escrow yang cepat dan aman.
                    </p>
                    <button type="button" @click="showAddModal = true"
                            class="btn-tactile mt-4 px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white text-xs font-bold cursor-pointer">
                        Tambah Rekening Pertama
                    </button>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($accounts as $idx => $acc)
                        @php $isLockedByPending = in_array((int) $acc->id, $pendingBankAccountIds, true); @endphp
                        <div style="--stagger-index: {{ min($idx + 1, 8) }}"
                             class="bg-white border {{ $acc->is_primary ? 'border-[#0068E1] ring-1 ring-[#0068E1]/20' : 'border-[#E4E8EE]' }} rounded-xl p-5 flex flex-col justify-between gap-4 card-elevate animate-enter">
                            <div class="space-y-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2.5">
                                        <div class="px-2.5 py-1.5 rounded-lg bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-mono-tabular text-xs font-extrabold">
                                            {{ $acc->bank_code }}
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold text-[#111827]">{{ $acc->bank_name }}</div>
                                            <div class="text-[10px] text-[#047857] font-semibold inline-flex items-center gap-1">
                                                <svg class="w-3 h-3 text-[#00AA5B]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                                                <span>Terverifikasi</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1.5">
                                        @if($acc->is_primary)
                                            <span class="px-2.5 py-0.5 rounded-full bg-[#ECFDF5] border border-[#A7F3D0] text-[#047857] text-[10px] font-bold">
                                                Utama
                                            </span>
                                        @endif
                                        @if($isLockedByPending)
                                            <span class="px-2 py-0.5 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] text-[10px] font-bold">
                                                Dipakai Pending WD
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                    <div class="text-[10px] uppercase tracking-wider text-[#6B7280] font-semibold">Nomor Rekening / E-Wallet</div>
                                    <div class="font-mono-tabular text-base font-extrabold text-[#111827] mt-0.5 tracking-wide">{{ $acc->account_number }}</div>
                                    <div class="text-xs font-semibold text-[#374151] mt-1">a.n. {{ $acc->account_holder_name }}</div>
                                </div>
                            </div>

                            <div class="pt-3 border-t border-[#F1F5F9] flex items-center justify-between gap-2 text-xs">
                                @if(! $acc->is_primary)
                                    <form method="POST" action="{{ route('wallet.bank-accounts.primary', $acc->id) }}">
                                        @csrf
                                        <button type="submit" class="btn-tactile px-3 py-1.5 rounded-lg bg-[#EFF6FF] hover:bg-[#DBEAFE] border border-[#BFDBFE] text-[#0068E1] font-bold cursor-pointer">
                                            Jadikan Utama
                                        </button>
                                    </form>
                                @else
                                    <span class="text-[11px] font-semibold text-[#047857]">Rekening Pencairan Utama</span>
                                @endif

                                <form method="POST" action="{{ route('wallet.bank-accounts.destroy', $acc->id) }}"
                                      onsubmit="return confirm('Hapus rekening ini dari daftar tersimpan?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="btn-tactile px-3 py-1.5 rounded-lg bg-[#FEF2F2] hover:bg-[#FEE2E2] border border-[#FECACA] text-[#DC2626] font-semibold cursor-pointer">
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Add New Bank Account Modal --}}
        <div x-show="showAddModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-[2px] p-4">
            <div @click.outside="showAddModal = false" class="bg-white border border-[#E4E8EE] shadow-2xl max-w-md w-full p-6 rounded-xl">
                <div class="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
                    <h2 class="text-base font-bold text-[#111827]">Tambah Rekening Bank / E-Wallet</h2>
                    <button type="button" @click="showAddModal = false" class="p-1 text-[#6B7280] hover:text-[#111827] rounded-lg cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('wallet.bank-accounts.store') }}" class="mt-4 space-y-3.5 text-xs">
                    @csrf
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Bank / E-Wallet *</label>
                        <select name="bank_code" required class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                            @foreach($supportedBanks as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Nomor Rekening / Nomor HP E-Wallet (6-20 digit) *</label>
                        <input type="text" name="account_number" required minlength="6" maxlength="20" inputmode="numeric"
                               placeholder="Contoh: 8830192841"
                               class="w-full h-10 px-3 font-mono-tabular bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-[#374151] mb-1">Nama Pemilik Rekening *</label>
                        <input type="text" name="account_holder_name" required minlength="3" maxlength="100" value="{{ strtoupper($user->name) }}"
                               class="w-full h-10 px-3 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg focus:bg-white focus:border-[#0068E1] focus:outline-none">
                    </div>
                    <label class="inline-flex items-center gap-2 cursor-pointer pt-1">
                        <input type="checkbox" name="is_primary" value="1" class="w-4 h-4 accent-[#0068E1]">
                        <span class="font-semibold text-[#111827]">Jadikan sebagai rekening pencairan utama</span>
                    </label>

                    <div class="flex justify-end gap-2 pt-3 border-t border-[#F1F5F9]">
                        <button type="button" @click="showAddModal = false" class="btn-tactile px-4 py-2 rounded-lg border border-[#E4E8EE] text-[#4B5563] font-semibold cursor-pointer">Batal</button>
                        <button type="submit" class="btn-tactile px-4 py-2 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">Simpan Rekening</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
