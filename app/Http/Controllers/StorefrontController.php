<?php

namespace App\Http\Controllers;

use App\Services\StorefrontService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly StorefrontService $storefrontService,
    ) {}

    public function show(Request $request, string $slug): View
    {
        $deliveryFilter = $request->string('delivery')->toString();
        $search = trim($request->string('q')->toString());
        $selectedSort = $request->string('sort', 'recommended')->toString();
        $activeTab = $request->string('tab', 'products')->toString();
        if (! in_array($activeTab, ['products', 'reviews'], true)) {
            $activeTab = 'products';
        }

        $data = $this->storefrontService->getPublicStoreData(
            storeSlug: $slug,
            filters: [
                'delivery_method' => $deliveryFilter,
                'q' => $search,
                'sort' => $selectedSort,
            ],
            viewerId: $request->user()?->id,
        );

        return view('stores.show', [
            'store' => $data['store'],
            'products' => $data['products'],
            'reviews' => $data['reviews'],
            'ratingSummary' => $data['ratingSummary'],
            'isFollowing' => $data['isFollowing'],
            'wishlistedIds' => $data['wishlistedIds'],
            'deliveryFilter' => $deliveryFilter,
            'search' => $search,
            'selectedSort' => $selectedSort,
            'activeTab' => $activeTab,
        ]);
    }
}
