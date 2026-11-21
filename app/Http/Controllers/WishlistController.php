<?php

namespace App\Http\Controllers;

use App\Exceptions\SelfDealingException;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\WishlistAndFollowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function __construct(
        private readonly WishlistAndFollowService $wishlistService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = $request->string('tab', 'wishlist')->toString();
        if (! in_array($tab, ['wishlist', 'following'], true)) {
            $tab = 'wishlist';
        }

        $products = $user->wishlistedProducts()
            ->with(['gameCategory.game', 'sellerProfile', 'images', 'primaryImage'])
            ->withCount(['wishlists', 'wishlistedBy'])
            ->where('products.is_active', true)
            ->get();

        $followedStores = $user->followedStores()
            ->with(['operatingHours'])
            ->withCount(['followers', 'products' => fn ($q) => $q->where('is_active', true)])
            ->get();

        return view('buyer.wishlist', compact('products', 'followedStores', 'tab'));
    }

    public function toggleWishlist(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        try {
            $result = $this->wishlistService->toggleWishlist($request->user()->id, $product->id);

            if ($request->expectsJson()) {
                return response()->json(['ok' => true] + $result);
            }

            $msg = $result['wishlisted']
                ? "\"{$product->title}\" disimpan ke Wishlist Anda."
                : "\"{$product->title}\" dihapus dari Wishlist.";

            return back()->with('success', $msg);
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleFollow(Request $request, string $seller): JsonResponse|RedirectResponse
    {
        $store = ctype_digit($seller)
            ? SellerProfile::where('user_id', (int) $seller)->firstOrFail()
            : SellerProfile::where('slug', $seller)->firstOrFail();

        try {
            $result = $this->wishlistService->toggleFollowStore($request->user()->id, (int) $store->user_id);

            if ($request->expectsJson()) {
                return response()->json(['ok' => true] + $result);
            }

            $msg = $result['following']
                ? "Anda kini mengikuti toko {$store->store_name}."
                : "Anda berhenti mengikuti toko {$store->store_name}.";

            return back()->with('success', $msg);
        } catch (SelfDealingException|DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }
}
