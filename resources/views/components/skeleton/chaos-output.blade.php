<div {{ $attributes->merge(['class' => 'space-y-4']) }} aria-busy="true" aria-label="Menjalankan verifikasi skenario Chaos Lab">
    {{-- Status Header Pill + Telemetry --}}
    <div class="flex items-center justify-between gap-3 pb-3 border-b border-[#E4E8EE]">
        <div class="flex items-center gap-2">
            <div class="h-6 w-28 rounded-full skeleton-shimmer"></div>
            <div class="h-4 w-44 rounded skeleton-shimmer"></div>
        </div>
        <div class="h-4 w-16 rounded skeleton-shimmer"></div>
    </div>

    {{-- 4 Assertion Metric Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
        @for($m = 0; $m < 4; $m++)
            <div class="p-3 bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg flex items-center justify-between gap-2">
                <div class="h-3 rounded skeleton-shimmer" style="width: {{ 50 + ($m % 3) * 14 }}%"></div>
                <div class="h-4 w-14 rounded skeleton-shimmer"></div>
            </div>
        @endfor
    </div>

    {{-- Code-Block JSON/SQL Audit Shimmer --}}
    <div class="p-4 bg-[#F8FAFC] border border-[#E4E8EE] rounded-lg space-y-2.5">
        <div class="h-3 w-1/3 rounded skeleton-shimmer"></div>
        <div class="h-3 w-4/5 rounded skeleton-shimmer"></div>
        <div class="h-3 w-3/4 rounded skeleton-shimmer"></div>
        <div class="h-3 w-11/12 rounded skeleton-shimmer"></div>
        <div class="h-3 w-2/3 rounded skeleton-shimmer"></div>
        <div class="h-3 w-1/2 rounded skeleton-shimmer"></div>
    </div>
</div>
