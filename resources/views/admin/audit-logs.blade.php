@extends('layouts.app')

@section('title', 'Admin Audit Trail Log — NexaTrade')

@section('content')
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        <x-admin-sidebar />

        <div class="flex-1 min-w-0 space-y-5 w-full">
            <div class="bg-white border border-[#E4E8EE] rounded-xl p-5 flex flex-wrap items-center justify-between gap-4 animate-enter" style="--stagger-index: 0">
                <div>
                    <h1 class="text-xl font-extrabold text-[#111827]">Immutable Admin Audit Trail</h1>
                    <p class="text-xs text-[#6B7280] mt-0.5">Rekam jejak permanen (append-only) seluruh perubahan CMS, putusan garansi hackback, dan penindakan moderasi beserta diff JSON Before &rarr; After.</p>
                </div>

                <form method="GET" action="{{ route('admin.audit-logs') }}" class="flex flex-wrap items-center gap-2 text-xs">
                    <select name="entity_type" class="h-9 px-2.5 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        <option value="">Semua Entitas</option>
                        @foreach($distinctEntities as $ent)
                            <option value="{{ $ent }}" @selected($entityFilter === $ent)>{{ $ent }}</option>
                        @endforeach
                    </select>
                    <select name="action" class="h-9 px-2.5 bg-[#F8FAFC] border border-[#D1D5DB] rounded-lg">
                        <option value="">Semua Aksi</option>
                        @foreach($distinctActions as $act)
                            <option value="{{ $act }}" @selected($actionFilter === $act)>{{ $act }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-tactile h-9 px-3.5 rounded-lg bg-[#0068E1] hover:bg-[#0055B8] text-white font-bold cursor-pointer">
                        Filter
                    </button>
                </form>
            </div>

            <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden animate-enter" style="--stagger-index: 1">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#F8FAFC] border-b border-[#E4E8EE] text-[11px] font-semibold uppercase tracking-wider text-[#6B7280]">
                            <tr>
                                <th class="px-4 py-3">Waktu</th>
                                <th class="px-4 py-3">Admin</th>
                                <th class="px-4 py-3">Aksi</th>
                                <th class="px-4 py-3">Entitas</th>
                                <th class="px-4 py-3">Ringkasan</th>
                                <th class="px-4 py-3 text-right">Diff JSON</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F1F5F9]">
                            @forelse($logs as $log)
                                <tr class="row-interactive" x-data="{ openDiff: false }">
                                    <td class="px-4 py-3 font-mono-tabular text-[11px] text-[#6B7280] whitespace-nowrap align-top">
                                        {{ $log->created_at?->format('d M Y H:i:s') }}
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-[#111827] whitespace-nowrap align-top">
                                        {{ $log->admin?->name ?? 'System' }}
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span class="px-2 py-0.5 rounded bg-[#EFF6FF] border border-[#BFDBFE] text-[#0068E1] font-mono-tabular text-[10px] font-bold">
                                            {{ $log->action }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-mono-tabular text-[11px] text-[#4B5563] whitespace-nowrap align-top">
                                        {{ $log->entity_type }}#{{ $log->entity_id }}
                                    </td>
                                    <td class="px-4 py-3 text-[#374151] align-top">
                                        <div>{{ $log->summary }}</div>
                                        <div x-show="openDiff" x-cloak class="mt-2.5 grid grid-cols-1 md:grid-cols-2 gap-2 font-mono-tabular text-[10px]">
                                            <div class="p-2.5 rounded-lg bg-[#FEF2F2] border border-[#FECACA] overflow-x-auto">
                                                <div class="font-bold text-[#991B1B] mb-1">BEFORE STATE</div>
                                                <pre class="whitespace-pre-wrap text-[#7F1D1D]">{{ json_encode($log->before_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                            <div class="p-2.5 rounded-lg bg-[#ECFDF5] border border-[#A7F3D0] overflow-x-auto">
                                                <div class="font-bold text-[#065F46] mb-1">AFTER STATE</div>
                                                <pre class="whitespace-pre-wrap text-[#047857]">{{ json_encode($log->after_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right align-top whitespace-nowrap">
                                        <button type="button" @click="openDiff = !openDiff"
                                                class="btn-tactile px-2.5 py-1 rounded border border-[#E4E8EE] hover:border-[#0068E1] bg-white text-[11px] font-semibold text-[#0068E1] cursor-pointer"
                                                x-text="openDiff ? 'Tutup Diff' : 'Lihat Diff'"></button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-10 text-center text-xs text-[#6B7280]">
                                        Belum ada rekaman audit log.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
