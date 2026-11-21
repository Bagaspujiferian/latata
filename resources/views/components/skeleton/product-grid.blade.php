@props(['count' => 10])

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3.5']) }} aria-busy="true" aria-label="Memuat katalog produk">
    @for($i = 0; $i < $count; $i++)
        <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden flex flex-col justify-between animate-enter"
             style="--stagger-index: {{ min($i, 9) }}">
            <div>
                {{-- 16:10 Image Placeholder --}}
                <div class="relative aspect-[16/10] skeleton-shimmer border-b border-[#F1F5F9]">
                    <div class="absolute top-2 left-2 h-4 w-14 rounded-md bg-white/80"></div>
                </div>

                <div class="p-3 space-y-2.5">
                    {{-- 2 Title Lines --}}
                    <div class="space-y-1.5">
                        <div class="h-3.5 w-11/12 rounded skeleton-shimmer"></div>
                        <div class="h-3.5 w-3/4 rounded skeleton-shimmer"></div>
                    </div>

                    {{-- Price Block --}}
                    <div class="h-4 w-24 rounded skeleton-shimmer"></div>

                    {{-- Compact Badge Row --}}
                    <div class="flex items-center gap-1.5">
                        <div class="h-4 w-20 rounded-md skeleton-shimmer"></div>
                        <div class="h-3.5 w-12 rounded skeleton-shimmer"></div>
                    </div>
                </div>
            </div>

            {{-- Store Footer --}}
            <div class="px-3 pb-3 pt-2 border-t border-[#F1F5F9] flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <div class="w-3.5 h-3.5 rounded-full skeleton-shimmer shrink-0"></div>
                    <div class="h-3 w-20 rounded skeleton-shimmer"></div>
                </div>
                <div class="h-3 w-12 rounded skeleton-shimmer shrink-0"></div>
            </div>
        </div>
    @endfor
</div>
