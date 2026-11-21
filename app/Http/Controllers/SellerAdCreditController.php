<?php

namespace App\Http\Controllers;

use App\Models\AdCreditPackage;
use App\Models\AdCreditPurchase;
use App\Models\Product;
use App\Services\AdCreditAndStoreLevelService;
use App\Services\WalletPinService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerAdCreditController extends Controller
{
    public function __construct(
        private readonly AdCreditAndStoreLevelService $adCreditService,
        private readonly WalletPinService $walletPinService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load(['wallet', 'sellerProfile']);
        $packages = AdCreditPackage::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        $purchases = AdCreditPurchase::with('package')
            ->where('seller_id', $user->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $products = Product::where('seller_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('is_featured')
            ->orderByDesc('last_bumped_at')
            ->get();

        $storeLevel = $this->adCreditService->recalculateStoreLevel($user->id, false);

        return view('seller.ad-credits', compact('user', 'packages', 'purchases', 'products', 'storeLevel'));
    }

    public function purchase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:ad_credit_packages,id'],
            'wallet_pin' => ['nullable', 'string', 'max:6'],
        ]);

        try {
            $this->walletPinService->verifyPinOrFail($request->user()->id, $request->input('wallet_pin'), true);

            $purchase = $this->adCreditService->purchasePackageWithWallet(
                sellerId: $request->user()->id,
                packageId: (int) $validated['package_id'],
            );

            return back()->with('success', "Berhasil membeli {$purchase->package->name} (+{$purchase->credits_granted} kredit sundul)! Ref: {$purchase->purchase_code}.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function boostFeatured(Request $request, Product $product): RedirectResponse
    {
        try {
            $updated = $this->adCreditService->boostProductFeatured($request->user()->id, $product->id, 3);

            return back()->with('success', "Produk \"{$updated->title}\" berhasil dipromosikan sebagai Featured Product (3 Kredit terpakai)!");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
