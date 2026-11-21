@props(['rows' => 5, 'cols' => 6])

<div {{ $attributes->merge(['class' => 'bg-white border border-[#E4E8EE] rounded-xl overflow-hidden']) }} aria-busy="true" aria-label="Memuat baris data">
    <div class="px-4 py-3 bg-[#F8FAFC] border-b border-[#E4E8EE] flex items-center justify-between gap-4">
        <div class="h-3.5 w-44 rounded skeleton-shimmer"></div>
        <div class="h-3 w-24 rounded skeleton-shimmer"></div>
    </div>

    <div class="divide-y divide-[#F1F5F9]">
        @for($r = 0; $r < $rows; $r++)
            <div class="px-4 py-3.5 grid gap-4 items-center animate-enter"
                 style="grid-template-columns: repeat({{ max(2, (int) $cols) }}, minmax(0, 1fr)); --stagger-index: {{ min($r, 8) }};">
                @for($c = 0; $c < max(2, (int) $cols); $c++)
                    @if($c === 0)
                        <div class="space-y-1.5">
                            <div class="h-3.5 w-4/5 rounded skeleton-shimmer"></div>
                            <div class="h-2.5 w-1/2 rounded skeleton-shimmer"></div>
                        </div>
                    @elseif($c === 1)
                        <div>
                            <div class="h-5 w-24 rounded-full skeleton-shimmer"></div>
                        </div>
                    @elseif($c === max(2, (int) $cols) - 1)
                        <div class="flex justify-end">
                            <div class="h-4 w-24 rounded skeleton-shimmer"></div>
                        </div>
                    @elseif($c === max(2, (int) $cols) - 2)
                        <div class="flex justify-end">
                            <div class="h-3.5 w-28 rounded skeleton-shimmer"></div>
                        </div>
                    @else
                        <div>
                            <div class="h-3.5 rounded skeleton-shimmer" style="width: {{ 60 + (($r + $c) % 3) * 12 }}%"></div>
                        </div>
                    @endif
                @endfor
            </div>
        @endfor
    </div>
</div>
