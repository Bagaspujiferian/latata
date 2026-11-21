@extends('layouts.app')

@section('title', 'Verifikasi KYC & Audit Chat Bypass — Admin Risk')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start" x-data="{ lightboxUrl: null, lightboxTitle: '' }">
        <x-admin-sidebar :pending-kycs="$pendingCount" />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            {{-- Header Card --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Antrean Verifikasi KYC &amp; Audit Chat Bypass</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">
                        Tinjau pengajuan merchant baru dengan dokumen privat terenkripsi serta pantau percakapan pra-transaksi yang terindikasi pelanggaran anti-bypass.
                    </p>
                </div>

                <div class="flex items-center gap-2 font-mono-tabular text-xs">
                    <span class="px-2.5 py-1 rounded-full bg-[#FFF4ED] border border-[#FED7AA] text-[#C2410C] font-bold">
                        {{ $pendingCount }} Pending KYC
                    </span>
                    <span class="px-2.5 py-1 rounded-full bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] font-bold">
                        {{ $flaggedChatCount }} Chat Bypass
                    </span>
                </div>
            </div>

            {{-- Tab Strip --}}
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-2 flex flex-wrap gap-1.5 animate-enter" style="--stagger-index: 1">
                @php
                    $tabs = [
                        'pending' => ['label' => 'Pending Review', 'count' => $pendingCount, 'tone' => 'bg-[#FFF4ED] text-[#C2410C]'],
                        'approved' => ['label' => 'Disetujui', 'count' => $approvedCount, 'tone' => 'bg-[#ECFDF5] text-[#047857]'],
                        'rejected' => ['label' => 'Ditolak', 'count' => $rejectedCount, 'tone' => 'bg-[#FEF2F2] text-[#B91C1C]'],
                        'flagged_chat' => ['label' => 'Audit Chat Terindikasi Bypass', 'count' => $flaggedChatCount, 'tone' => 'bg-[#FEF2F2] text-[#B91C1C]'],
                    ];
                @endphp
                @foreach($tabs as $key => $meta)
                    <a href="{{ route('admin.kyc.index', ['tab' => $key]) }}"
                       class="btn-tactile px-3.5 py-2 rounded-lg text-xs font-bold inline-flex items-center gap-2 {{ $tab === $key ? 'bg-[#0068E1] text-white' : 'text-[#4B5563] hover:bg-[#F8FAFC]' }}">
                        <span>{{ $meta['label'] }}</span>
                        <span class="font-mono-tabular text-[10px] px-1.5 py-0.5 rounded-full {{ $tab === $key ? 'bg-white/20 text-white' : $meta['tone'] }}">
                            {{ $meta['count'] }}
                        </span>
                    </a>
                @endforeach
            </div>

            {{-- TAB A: Flagged Bypass Chat Audit --}}
            @if($tab === 'flagged_chat')
                <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 2">
                    <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                        <h2 class="text-sm font-bold text-[#111827]">Log Pesan Inbox Terindikasi Bypass (`is_flagged_bypass = true`)</h2>
                        <span class="font-mono-tabular text-[11px] text-[#6B7280]">{{ $flaggedMessages->count() }} pesan</span>
                    </div>

                    @if($flaggedMessages->isEmpty())
                        <div class="p-10 text-center text-xs text-[#6B7280]">
                            Belum ada pesan inbox yang terindikasi pelanggaran anti-bypass.
                        </div>
                    @else
                        <div class="divide-y divide-[#F1F5F9]">
                            @foreach($flaggedMessages as $msg)
                                <div class="p-5 space-y-2.5 text-xs">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-bold text-[#111827]">{{ $msg->sender?->name ?? 'Pengguna' }}</span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $msg->sender_role === 'seller' ? 'bg-[#FFF4ED] text-[#C2410C]' : 'bg-[#EFF6FF] text-[#0068E1]' }}">
                                                {{ $msg->sender_role }}
                                            </span>
                                            <span class="px-2 py-0.5 rounded-full bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C] font-mono-tabular text-[10px] font-bold">
                                                {{ $msg->detected_violation_type }}
                                            </span>
                                        </div>
                                        <span class="font-mono-tabular text-[11px] text-[#6B7280]">
                                            Thread #{{ $msg->conversation_id }} &middot; {{ $msg->created_at?->format('d M Y, H:i') }}
                                        </span>
                                    </div>

                                    <div class="text-[11px] text-[#6B7280]">
                                        Pembeli: <strong class="text-[#111827]">{{ $msg->conversation?->buyer?->name }}</strong>
                                        &middot; Toko: <strong class="text-[#111827]">{{ $msg->conversation?->sellerProfile?->store_name }}</strong>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                                        <div class="p-3 rounded-lg bg-[#FEF2F2] border border-[#FECACA]">
                                            <div class="text-[10px] font-bold uppercase text-[#B91C1C] mb-1">Pesan Asli (Audit Admin Only)</div>
                                            <p class="text-[#7F1D1D] break-words leading-relaxed">{{ $msg->raw_message }}</p>
                                        </div>
                                        <div class="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E8EE]">
                                            <div class="text-[10px] font-bold uppercase text-[#047857] mb-1">Tampil ke Penerima (Tersensor)</div>
                                            <p class="text-[#374151] break-words leading-relaxed">{{ $msg->sanitized_message }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            {{-- TAB B: KYC Verification List (Pending / Approved / Rejected) --}}
            @else
                @if($kycList->isEmpty())
                    <div class="bg-white border border-[#E4E8EE] rounded-xl p-10 text-center text-xs text-[#6B7280] animate-enter" style="--stagger-index: 2">
                        Tidak ada pengajuan KYC pada status <strong class="text-[#111827] uppercase">{{ $tab }}</strong> saat ini.
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($kycList as $idx => $kyc)
                            @php
                                $statusPill = match ($kyc->status) {
                                    'approved' => 'bg-[#ECFDF5] border-[#A7F3D0] text-[#047857]',
                                    'rejected' => 'bg-[#FEF2F2] border-[#FECACA] text-[#B91C1C]',
                                    default => 'bg-[#FFF4ED] border-[#FED7AA] text-[#C2410C]',
                                };
                                $idCardUrl = route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'id_card']);
                                $selfieUrl = route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'selfie']);
                            @endphp
                            <article style="--stagger-index: {{ min($idx + 2, 8) }}"
                                     class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter"
                                     x-data="{ showReject: false }">
                                <div class="px-5 py-3.5 bg-[#F8FAFC] border-b border-[#E4E8EE] flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono-tabular text-xs font-bold text-[#111827]">KYC #{{ $kyc->id }}</span>
                                        <span class="px-2 py-0.5 rounded-full border text-[10px] font-bold uppercase {{ $statusPill }}">
                                            {{ $kyc->status }}
                                        </span>
                                        <span class="text-xs font-bold text-[#0068E1]">{{ $kyc->proposed_store_name }}</span>
                                        <span class="font-mono-tabular text-[11px] text-[#6B7280]">(/toko/{{ $kyc->proposed_store_slug }})</span>
                                    </div>
                                    <span class="font-mono-tabular text-[11px] text-[#6B7280]">
                                        Diajukan {{ $kyc->created_at?->format('d M Y, H:i') }}
                                    </span>
                                </div>

                                <div class="p-5 grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                                    {{-- Applicant Details --}}
                                    <div class="xl:col-span-5 space-y-3 text-xs">
                                        <div class="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E4E8EE] space-y-1.5">
                                            <div class="flex justify-between gap-2">
                                                <span class="text-[#6B7280]">Akun Pemohon</span>
                                                <strong class="text-[#111827]">{{ $kyc->user?->name }} ({{ $kyc->user?->email }})</strong>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <span class="text-[#6B7280]">Nama Legal KTP</span>
                                                <strong class="text-[#111827]">{{ $kyc->full_legal_name }}</strong>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <span class="text-[#6B7280]">NIK Terenkripsi</span>
                                                <strong class="font-mono-tabular text-[#111827]">{{ $kyc->maskedNik() }}</strong>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <span class="text-[#6B7280]">Blind-Hash Lock</span>
                                                <strong class="font-mono-tabular text-[11px] {{ $kyc->active_nik_lock_hash ? 'text-[#047857]' : 'text-[#6B7280]' }}">
                                                    {{ $kyc->active_nik_lock_hash ? substr($kyc->active_nik_lock_hash, 0, 14).'...' : 'UNLOCKED (NULL)' }}
                                                </strong>
                                            </div>
                                            @if($kyc->proposed_store_tagline)
                                                <div class="pt-1.5 border-t border-[#E4E8EE] text-[#4B5563]">
                                                    Tagline: {{ $kyc->proposed_store_tagline }}
                                                </div>
                                            @endif
                                        </div>

                                        @if($kyc->rejection_reason)
                                            <div class="p-3 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[#B91C1C]">
                                                <strong>Alasan Penolakan:</strong> {{ $kyc->rejection_reason }}
                                            </div>
                                        @endif

                                        @if($kyc->reviewed_at)
                                            <div class="text-[11px] text-[#6B7280]">
                                                Ditinjau oleh <strong class="text-[#111827]">{{ $kyc->reviewer?->name ?? 'Admin' }}</strong>
                                                pada <span class="font-mono-tabular">{{ $kyc->reviewed_at->format('d M Y, H:i') }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Private Streamed Document Thumbnails --}}
                                    <div class="xl:col-span-7 space-y-4">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <button type="button" @click="lightboxUrl = '{{ $idCardUrl }}'; lightboxTitle = 'e-KTP — {{ addslashes($kyc->full_legal_name) }}'"
                                                    class="group text-left border border-[#E4E8EE] hover:border-[#0068E1] rounded-xl overflow-hidden bg-[#F8FAFC] cursor-pointer">
                                                <div class="aspect-[16/10] bg-white overflow-hidden">
                                                    <img src="{{ $idCardUrl }}" alt="e-KTP {{ $kyc->full_legal_name }}"
                                                         class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform">
                                                </div>
                                                <div class="px-3 py-2 flex items-center justify-between text-[11px]">
                                                    <span class="font-bold text-[#111827]">Foto e-KTP (Privat)</span>
                                                    <span class="text-[#0068E1] font-semibold">Perbesar</span>
                                                </div>
                                            </button>

                                            <button type="button" @click="lightboxUrl = '{{ $selfieUrl }}'; lightboxTitle = 'Selfie KTP — {{ addslashes($kyc->full_legal_name) }}'"
                                                    class="group text-left border border-[#E4E8EE] hover:border-[#0068E1] rounded-xl overflow-hidden bg-[#F8FAFC] cursor-pointer">
                                                <div class="aspect-[16/10] bg-white overflow-hidden">
                                                    <img src="{{ $selfieUrl }}" alt="Selfie {{ $kyc->full_legal_name }}"
                                                         class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform">
                                                </div>
                                                <div class="px-3 py-2 flex items-center justify-between text-[11px]">
                                                    <span class="font-bold text-[#111827]">Selfie + KTP (Privat)</span>
                                                    <span class="text-[#0068E1] font-semibold">Perbesar</span>
                                                </div>
                                            </button>
                                        </div>

                                        @if($kyc->status === 'pending')
                                            <div class="flex flex-wrap items-center gap-2.5 pt-1">
                                                <form method="POST" action="{{ route('admin.kyc.approve', $kyc->id) }}">
                                                    @csrf
                                                    <button type="submit"
                                                            class="btn-tactile px-4 py-2 rounded-lg bg-[#00AA5B] hover:bg-[#00924E] text-white text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer">
                                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                                                        <span>Setujui &amp; Aktifkan Toko Seller</span>
                                                    </button>
                                                </form>

                                                <button type="button" @click="showReject = !showReject"
                                                        class="btn-tactile px-4 py-2 rounded-lg bg-[#FEF2F2] hover:bg-[#FEE2E2] border border-[#FECACA] text-[#DC2626] text-xs font-bold cursor-pointer">
                                                    Tolak Pengajuan
                                                </button>
                                            </div>

                                            <form x-show="showReject" x-cloak method="POST" action="{{ route('admin.kyc.reject', $kyc->id) }}"
                                                  class="p-3.5 rounded-xl bg-[#FEF2F2] border border-[#FECACA] space-y-2.5 text-xs">
                                                @csrf
                                                <label class="block font-bold text-[#991B1B]">Alasan Penolakan Wajib Diisi (Kunci NIK akan dilepas):</label>
                                                <input type="text" name="rejection_reason" required minlength="5" maxlength="255"
                                                       placeholder="Contoh: Foto e-KTP buram pada bagian NIK, mohon unggah ulang di tempat terang."
                                                       class="w-full h-9 px-3 bg-white border border-[#FCA5A5] rounded-lg focus:outline-none focus:border-[#DC2626]">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" @click="showReject = false" class="px-3 py-1.5 text-[#6B7280] font-semibold cursor-pointer">Batal</button>
                                                    <button type="submit" class="btn-tactile px-4 py-1.5 rounded-lg bg-[#DC2626] hover:bg-[#B91C1C] text-white font-bold cursor-pointer">
                                                        Konfirmasi Tolak KYC
                                                    </button>
                                                </div>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- Lightbox Modal for Private KYC Document Inspection --}}
        <div x-show="lightboxUrl" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-[2px] p-4">
            <div @click.outside="lightboxUrl = null" class="bg-white border border-[#E4E8EE] rounded-xl shadow-2xl max-w-2xl w-full overflow-hidden">
                <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between">
                    <span class="text-xs font-bold text-[#111827]" x-text="lightboxTitle"></span>
                    <button type="button" @click="lightboxUrl = null" class="p-1 text-[#6B7280] hover:text-[#111827] cursor-pointer">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>
                <div class="p-4 bg-[#F4F6F9]">
                    <img :src="lightboxUrl" :alt="lightboxTitle" class="w-full max-h-[70vh] object-contain rounded-lg border border-[#E4E8EE] bg-white">
                </div>
            </div>
        </div>
    </div>
@endsection
