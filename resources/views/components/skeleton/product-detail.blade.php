<div {{ $attributes->merge(['class' => 'grid grid-cols-1 lg:grid-cols-12 gap-5 items-start']) }} aria-busy="true" aria-label="Memuat detail produk">
    {{-- Left Column (3/12): Artwork + Store Card + Guarantee Box --}}
    <div class="lg:col-span-3 space-y-4">
        <div class="bg-white border border-[#E4E8EE] rounded-xl overflow-hidden">
            <div class="aspect-[16/10] skeleton-shimmer"></div>
            <div class="p-3.5 space-y-2">
                <div class="h-3 w-24 rounded skeleton-shimmer"></div>
                <div class="h-4 w-36 rounded skeleton-shimmer"></div>
            </div>
        </div>
        <div class="bg-white border border-[#E4E8EE] rounded-xl p-4 space-y-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full skeleton-shimmer shrink-0"></div>
                <div class="space-y-1.5 flex-1">
                    <div class="h-3.5 w-3/4 rounded skeleton-shimmer"></div>
                    <div class="h-3 w-1/2 rounded skeleton-shimmer"></div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div class="h-10 rounded-lg skeleton-shimmer"></div>
                <div class="h-10 rounded-lg skeleton-shimmer"></div>
            </div>
        </div>
    </div>

    {{-- Center Column (5/12): Title, Price, Dynamic Form & Nickname Pill --}}
    <div class="lg:col-span-5 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-5">
        <div class="space-y-2.5">
            <div class="flex gap-2">
                <div class="h-5 w-24 rounded-md skeleton-shimmer"></div>
                <div class="h-5 w-28 rounded-md skeleton-shimmer"></div>
            </div>
            <div class="h-6 w-11/12 rounded skeleton-shimmer"></div>
            <div class="h-7 w-40 rounded skeleton-shimmer"></div>
        </div>

        <div class="p-4 bg-[#F8FAFC] border border-[#E4E8EE] rounded-xl space-y-3">
            <div class="h-3.5 w-36 rounded skeleton-shimmer"></div>
            <div class="h-10 w-full rounded-lg skeleton-shimmer"></div>
            <div class="h-10 w-full rounded-lg skeleton-shimmer"></div>
            <div class="h-9 w-40 rounded-lg skeleton-shimmer"></div>
        </div>

        <div class="space-y-2">
            <div class="h-3.5 w-full rounded skeleton-shimmer"></div>
            <div class="h-3.5 w-10/12 rounded skeleton-shimmer"></div>
            <div class="h-3.5 w-3/4 rounded skeleton-shimmer"></div>
        </div>
    </div>

    {{-- Right Column (4/12): Sticky Checkout Card --}}
    <div class="lg:col-span-4 bg-white border border-[#E4E8EE] rounded-xl p-5 space-y-4">
        <div class="h-5 w-44 rounded skeleton-shimmer"></div>
        <div class="h-12 w-full rounded-lg skeleton-shimmer"></div>
        <div class="h-16 w-full rounded-lg skeleton-shimmer"></div>
        <div class="h-10 w-full rounded-lg skeleton-shimmer"></div>
        <div class="h-10 w-full rounded-lg skeleton-shimmer"></div>
    </div>
</div>
