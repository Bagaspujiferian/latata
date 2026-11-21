<?php

namespace App\Http\Controllers;

use App\Models\CategoryAttribute;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\Product;
use App\Services\FlashSaleService;
use App\Services\ProductCatalogFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MarketplaceController extends Controller
{
    public function __construct(
        private readonly ProductCatalogFilterService $catalogFilterService,
        private readonly FlashSaleService $flashSaleService,
    ) {}

    public function index(Request $request): View
    {
        $games = Game::with('categories')
            ->where('is_active', true)
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get();

        $selectedGameSlug = $request->string('game')->toString();
        $selectedCategoryId = $request->integer('category');
        $selectedDelivery = $request->string('delivery')->toString();
        $selectedSort = $request->string('sort', 'recommended')->toString();
        $search = trim($request->string('q')->toString());
        $onlySla10m = $request->boolean('sla_10m');
        $minPrice = $request->string('min_price')->toString();
        $maxPrice = $request->string('max_price')->toString();
        $attrFilters = is_array($request->input('attr')) ? $request->input('attr') : [];
        $attrsById = is_array($request->input('attrs')) ? $request->input('attrs') : [];

        $filterableAttributes = $selectedCategoryId > 0
            ? CategoryAttribute::with('options')
                ->where('game_category_id', $selectedCategoryId)
                ->where('is_filterable', true)
                ->orderBy('sort_order')
                ->get()
            : collect();

        $products = $this->catalogFilterService->getProducts([
            'game_slug' => $selectedGameSlug,
            'category_id' => $selectedCategoryId,
            'delivery_method' => $selectedDelivery,
            'sla_10m_only' => $onlySla10m,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'q' => $search,
            'attr' => $attrFilters,
            'attrs' => $attrsById,
            'sort' => $selectedSort,
        ]);

        $categoriesForSelectedGame = $selectedGameSlug !== ''
            ? GameCategory::whereHas('game', fn ($q) => $q->where('slug', $selectedGameSlug))->orderBy('id')->get()
            : collect();

        $wishlistedProductIds = $request->user()
            ? DB::table('wishlists')->where('user_id', $request->user()->id)->pluck('product_id')->map(fn ($id): int => (int) $id)->all()
            : [];

        $activeFlashSale = $this->flashSaleService->getActiveFlashSale();

        return view('marketplace.index', compact(
            'games',
            'products',
            'categoriesForSelectedGame',
            'filterableAttributes',
            'selectedGameSlug',
            'selectedCategoryId',
            'selectedDelivery',
            'selectedSort',
            'search',
            'onlySla10m',
            'minPrice',
            'maxPrice',
            'attrFilters',
            'attrsById',
            'wishlistedProductIds',
            'activeFlashSale',
        ));
    }

    public function show(Request $request, string $slug): View
    {
        $product = Product::with([
            'gameCategory.game',
            'sellerProfile.operatingHours',
            'seller',
            'images',
            'attributeValues.attribute',
            'attributeValues.categoryAttribute',
            'attributeValues.option',
            'reviews.buyer',
        ])
            ->withCount([
                'vaults as available_vaults_count' => fn ($q) => $q->where('status', 'available'),
                'vaults as reserved_vaults_count' => fn ($q) => $q->where('status', 'reserved'),
                'vaults as sold_vaults_count' => fn ($q) => $q->where('status', 'sold'),
                'wishlists',
                'wishlistedBy',
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        $vaultStats = $product->gameCategory->delivery_method === 'instant_vault'
            ? [
                'available' => (int) $product->available_vaults_count,
                'reserved' => (int) $product->reserved_vaults_count,
                'sold' => (int) $product->sold_vaults_count,
            ]
            : null;

        $relatedProducts = Product::with(['gameCategory.game', 'sellerProfile', 'images'])
            ->where('game_category_id', $product->game_category_id)
            ->where('id', '!=', $product->id)
            ->where('is_active', true)
            ->orderByDesc('sold_count')
            ->limit(4)
            ->get();

        $userId = $request->user()?->id;
        $isWishlisted = $userId
            ? DB::table('wishlists')->where('user_id', $userId)->where('product_id', $product->id)->exists()
            : false;

        $followersCount = $product->sellerProfile
            ? (int) DB::table('store_followers')->where('seller_id', $product->seller_id)->count()
            : 0;

        $isFollowingStore = ($userId && $product->sellerProfile)
            ? DB::table('store_followers')->where('user_id', $userId)->where('seller_id', $product->seller_id)->exists()
            : false;

        $activeFlashItem = $this->flashSaleService->getActiveFlashItemForProduct($product->id);

        return view('marketplace.show', compact(
            'product',
            'vaultStats',
            'relatedProducts',
            'isWishlisted',
            'followersCount',
            'isFollowingStore',
            'activeFlashItem',
        ));
    }
}
