<?php

use App\Http\Controllers\AdminChaosLabController;
use App\Http\Controllers\AdminKycController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BuyerOrderController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DemoAuthController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SellerDashboardController;
use App\Http\Controllers\SellerKycController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// Public Marketplace Silo, Product Detail & Public Storefront
Route::get('/', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/p/{slug}', [MarketplaceController::class, 'show'])->name('marketplace.show');
Route::get('/stores/{slug}', [StorefrontController::class, 'show'])->name('stores.show');
Route::get('/toko/{slug}', [StorefrontController::class, 'show'])->name('stores.toko');

// 1-Click Demo Account Switcher & Demo Wallet Top-Up
Route::post('/demo/switch-user', [DemoAuthController::class, 'switchUser'])->name('demo.switch-user');
Route::post('/demo/wallet-topup', [DemoAuthController::class, 'demoTopup'])->middleware('auth')->name('demo.wallet-topup');

// Authenticated Buyer, Inbox Chat, Notifications, Bank Accounts, KYC, Wishlist & Checkout
Route::middleware(['auth'])->group(function (): void {
    // Pre-Transaction Inbox Chat
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('/inbox/start', [InboxController::class, 'start'])->name('inbox.start');
    Route::get('/inbox/{conversation}/messages', [InboxController::class, 'messages'])->name('inbox.messages');
    Route::post('/inbox/{conversation}/messages', [InboxController::class, 'sendMessage'])->name('inbox.send');

    // Notification Center & Bell Actions
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Buyer Open Store & KYC Verification Wizard + Authorized Private Document Stream
    Route::get('/buyer/open-store', [SellerKycController::class, 'show'])->name('buyer.open-store');
    Route::post('/buyer/open-store', [SellerKycController::class, 'store'])->name('buyer.open-store.submit');
    Route::get('/admin/kyc/{kyc}/document/{type}', [AdminKycController::class, 'streamDocument'])
        ->whereIn('type', ['id_card', 'selfie'])
        ->name('admin.kyc.document');

    // Saved Bank Accounts (Unified Wallet + Buyer/Seller paths)
    Route::get('/wallet/bank-accounts', [BankAccountController::class, 'index'])->name('wallet.bank-accounts');
    Route::get('/buyer/bank-accounts', [BankAccountController::class, 'index'])->name('buyer.bank-accounts');
    Route::post('/wallet/bank-accounts', [BankAccountController::class, 'store'])->name('wallet.bank-accounts.store');
    Route::post('/wallet/bank-accounts/{id}/primary', [BankAccountController::class, 'setPrimary'])->name('wallet.bank-accounts.primary');
    Route::delete('/wallet/bank-accounts/{id}', [BankAccountController::class, 'destroy'])->name('wallet.bank-accounts.destroy');

    // Wishlist & Store Follow (supports both /wishlist/toggle/{product} and /wishlist/{product}/toggle; {seller} accepts user_id OR slug)
    Route::get('/buyer/wishlist', [WishlistController::class, 'index'])->name('buyer.wishlist');
    Route::post('/wishlist/toggle/{product}', [WishlistController::class, 'toggleWishlist'])->name('wishlist.toggle.alt');
    Route::post('/wishlist/{product}/toggle', [WishlistController::class, 'toggleWishlist'])->name('wishlist.toggle');
    Route::post('/stores/{seller}/follow', [WishlistController::class, 'toggleFollow'])->name('stores.follow');

    // Cart, Split Checkout & Invoices
    Route::get('/cart', [CheckoutController::class, 'cart'])->name('cart.index');
    Route::post('/cart/add', [CheckoutController::class, 'addToCart'])->name('cart.add');
    Route::patch('/cart/{cartItem}', [CheckoutController::class, 'updateCartItem'])->name('cart.update');
    Route::delete('/cart/{cartItem}', [CheckoutController::class, 'removeCartItem'])->name('cart.remove');
    Route::post('/checkout', [CheckoutController::class, 'processCheckout'])->name('checkout.process');

    Route::get('/invoices/{invoice_code}', [CheckoutController::class, 'showInvoice'])->name('invoices.show');
    Route::post('/invoices/{invoice_code}/simulate-pay', [CheckoutController::class, 'simulateMidtransPay'])->name('invoices.simulate-pay');

    // Buyer Orders & Wallet
    Route::get('/buyer/orders', [BuyerOrderController::class, 'index'])->name('buyer.orders.index');
    Route::get('/buyer/orders/{order_code}', [BuyerOrderController::class, 'show'])->name('buyer.orders.show');
    Route::post('/buyer/orders/{order_code}/decrypt/{orderItem}', [BuyerOrderController::class, 'decryptVaultItem'])->name('buyer.orders.decrypt');
    Route::post('/buyer/orders/{order_code}/claim-sla-refund', [BuyerOrderController::class, 'claimSlaRefund'])->name('buyer.orders.claim-sla');
    Route::post('/buyer/orders/{order_code}/confirm-complete', [BuyerOrderController::class, 'confirmComplete'])->name('buyer.orders.confirm');
    Route::post('/buyer/orders/{order_code}/dispute', [BuyerOrderController::class, 'openDispute'])->name('buyer.orders.dispute');
    Route::post('/buyer/orders/{order_code}/messages', [BuyerOrderController::class, 'sendMessage'])->name('buyer.orders.messages');
    Route::get('/buyer/wallet', [BuyerOrderController::class, 'wallet'])->name('buyer.wallet');
});

// Seller Center / TokoKu Studio
Route::prefix('seller')->name('seller.')->middleware(['auth', 'role:seller'])->group(function (): void {
    Route::get('/dashboard', [SellerDashboardController::class, 'dashboard'])->name('dashboard');
    Route::post('/store/toggle', [SellerDashboardController::class, 'toggleStoreOpen'])->name('store.toggle');

    Route::get('/categories/{category}/attributes', [SellerProductController::class, 'categoryAttributes'])->name('categories.attributes');

    Route::get('/products', [SellerDashboardController::class, 'products'])->name('products');
    Route::get('/products/create', [SellerProductController::class, 'create'])->name('products.create');
    Route::post('/products', [SellerProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [SellerProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [SellerProductController::class, 'update'])->name('products.put');
    Route::patch('/products/{product}/toggle-active', [SellerProductController::class, 'toggleActive'])->name('products.toggle-active');
    Route::patch('/products/{id}', [SellerDashboardController::class, 'updateProduct'])->name('products.update');
    Route::delete('/products/{product}', [SellerProductController::class, 'destroy'])->name('products.archive');
    Route::post('/products/{id}/restore', [SellerProductController::class, 'restore'])->name('products.restore');

    Route::post('/products/{id}/images', [SellerDashboardController::class, 'addProductImage'])->name('products.images.store');
    Route::post('/products/{id}/images/{imageId}/primary', [SellerDashboardController::class, 'setPrimaryProductImage'])->name('products.images.primary');
    Route::delete('/products/{id}/images/{imageId}', [SellerDashboardController::class, 'deleteProductImage'])->name('products.images.delete');
    Route::post('/products/{id}/attributes', [SellerDashboardController::class, 'updateProductAttributes'])->name('products.attributes.sync');
    Route::post('/products/{id}/bump', [SellerDashboardController::class, 'bumpProduct'])->name('products.bump');
    Route::post('/products/{id}/vault', [SellerDashboardController::class, 'ingestVault'])->name('products.vault');

    Route::get('/orders', [SellerDashboardController::class, 'orders'])->name('orders');
    Route::post('/orders/{order_code}/deliver', [SellerDashboardController::class, 'deliverOrder'])->name('orders.deliver');
    Route::post('/orders/{order_code}/messages', [SellerDashboardController::class, 'sendMessage'])->name('orders.messages');

    Route::get('/wallet', [SellerDashboardController::class, 'wallet'])->name('wallet');
    Route::post('/wallet/withdraw', [SellerDashboardController::class, 'requestWithdrawal'])->name('wallet.withdraw');
    Route::get('/bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts');

    Route::get('/settings', [SellerDashboardController::class, 'settings'])->name('settings');
    Route::post('/settings', [SellerDashboardController::class, 'updateSettings'])->name('settings.update');
    Route::put('/settings', [SellerDashboardController::class, 'updateSettings'])->name('settings.put');
    Route::post('/reviews/{reviewId}/reply', [SellerDashboardController::class, 'replyReview'])->name('reviews.reply');
});

// Admin Dispute Court, KYC Queue, Withdrawals & 8-Scenario Backend Chaos Lab
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin'])->group(function (): void {
    Route::get('/kyc', [AdminKycController::class, 'index'])->name('kyc.index');
    Route::post('/kyc/{kyc}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
    Route::post('/kyc/{kyc}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');

    Route::get('/disputes', [AdminChaosLabController::class, 'disputes'])->name('disputes');
    Route::post('/disputes/{order_code}/resolve', [AdminChaosLabController::class, 'resolveDispute'])->name('disputes.resolve');
    Route::post('/disputes/{order_code}/messages', [AdminChaosLabController::class, 'sendAdminMessage'])->name('disputes.messages');

    Route::get('/withdrawals', [AdminChaosLabController::class, 'withdrawals'])->name('withdrawals');
    Route::post('/withdrawals/{id}/approve', [AdminChaosLabController::class, 'approveWithdrawal'])->name('withdrawals.approve');
    Route::post('/withdrawals/{id}/reject', [AdminChaosLabController::class, 'rejectWithdrawal'])->name('withdrawals.reject');

    Route::get('/chaos-lab', [AdminChaosLabController::class, 'chaosLab'])->name('chaos-lab');
    Route::post('/chaos-lab/run/{scenario}', [AdminChaosLabController::class, 'runScenario'])->name('chaos-lab.run');
});
