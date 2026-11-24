<?php

use App\Http\Controllers\AccountRecoveryController;
use App\Http\Controllers\AccountVerificationController;
use App\Http\Controllers\AdminChaosLabController;
use App\Http\Controllers\AdminCmsController;
use App\Http\Controllers\AdminFinanceController;
use App\Http\Controllers\AdminKycController;
use App\Http\Controllers\AdminWarrantyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BuyerOrderController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DemoAuthController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SellerAdCreditController;
use App\Http\Controllers\SellerDashboardController;
use App\Http\Controllers\SellerDebtController;
use App\Http\Controllers\SellerKycController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\SellerPromoController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\WalletTopUpController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// Public Marketplace Silo, Product Detail & Public Storefront
Route::get('/', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/p/{slug}', [MarketplaceController::class, 'show'])->name('marketplace.show');
Route::get('/stores/{slug}', [StorefrontController::class, 'show'])->name('stores.show');
Route::get('/toko/{slug}', [StorefrontController::class, 'show'])->name('stores.toko');

// Production Authentication (Login, Register, Logout & Self-Service Password Recovery)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login')->name('login.submit');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-login')->name('register.submit');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/forgot-password', [AccountRecoveryController::class, 'showForgotPassword'])->name('password.request');
Route::post('/forgot-password', [AccountRecoveryController::class, 'sendPasswordReset'])
    ->middleware('throttle:auth-recovery')
    ->name('password.email');
Route::get('/reset-password/{token}', [AccountRecoveryController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reset-password', [AccountRecoveryController::class, 'resetPassword'])
    ->middleware('throttle:auth-recovery')
    ->name('password.update');

// 1-Click Demo Account Switcher & Demo Wallet Top-Up (Guarded by simulation.only middleware -> HTTP 404 in production)
Route::post('/demo/switch-user', [DemoAuthController::class, 'switchUser'])
    ->middleware('simulation.only')
    ->name('demo.switch-user');
Route::post('/demo/wallet-topup', [DemoAuthController::class, 'demoTopup'])
    ->middleware(['auth', 'simulation.only'])
    ->name('demo.wallet-topup');

// Authenticated Buyer, Security/PIN, Inbox Chat, Notifications, Bank Accounts, KYC, Wishlist, Exports & Checkout
Route::middleware(['auth'])->group(function (): void {
    // Account Security & 6-Digit Wallet PIN + Self-Service PIN Recovery
    Route::get('/account/security', [AuthController::class, 'security'])->name('account.security');
    Route::post('/account/security/password', [AuthController::class, 'updatePassword'])->name('account.security.password');
    Route::post('/account/security/pin', [AuthController::class, 'updateWalletPin'])
        ->middleware('throttle:wallet-sensitive')
        ->name('account.security.pin');
    Route::post('/account/security/pin-reset/request', [AccountRecoveryController::class, 'requestPinReset'])
        ->middleware('throttle:pin-recovery')
        ->name('account.security.pin-reset.request');
    Route::post('/account/security/pin-reset/confirm', [AccountRecoveryController::class, 'confirmPinReset'])
        ->middleware('throttle:pin-recovery')
        ->name('account.security.pin-reset.confirm');
    Route::post('/account/security/phone', [AccountVerificationController::class, 'bindPhone'])
        ->middleware('throttle:wallet-sensitive')
        ->name('account.security.phone.bind');
    Route::post('/account/security/otp/send', [AccountVerificationController::class, 'sendOtp'])
        ->middleware('throttle:verification-otp')
        ->name('account.security.otp.send');
    Route::post('/account/security/otp/verify', [AccountVerificationController::class, 'verifyOtp'])
        ->middleware('throttle:wallet-sensitive')
        ->name('account.security.otp.verify');

    // Pre-Transaction Inbox Chat
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('/inbox/start', [InboxController::class, 'start'])->name('inbox.start');
    Route::get('/inbox/{conversation}/messages', [InboxController::class, 'messages'])->name('inbox.messages');
    Route::post('/inbox/{conversation}/messages', [InboxController::class, 'sendMessage'])
        ->middleware('throttle:chat-send')
        ->name('inbox.send');

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

    // Saved Bank Accounts
    Route::get('/wallet/bank-accounts', [BankAccountController::class, 'index'])->name('wallet.bank-accounts');
    Route::get('/buyer/bank-accounts', [BankAccountController::class, 'index'])->name('buyer.bank-accounts');
    Route::post('/wallet/bank-accounts', [BankAccountController::class, 'store'])->name('wallet.bank-accounts.store');
    Route::post('/wallet/bank-accounts/{id}/primary', [BankAccountController::class, 'setPrimary'])->name('wallet.bank-accounts.primary');
    Route::delete('/wallet/bank-accounts/{id}', [BankAccountController::class, 'destroy'])->name('wallet.bank-accounts.destroy');

    // Wishlist & Store Follow
    Route::get('/buyer/wishlist', [WishlistController::class, 'index'])->name('buyer.wishlist');
    Route::post('/wishlist/toggle/{product}', [WishlistController::class, 'toggleWishlist'])->name('wishlist.toggle.alt');
    Route::post('/wishlist/{product}/toggle', [WishlistController::class, 'toggleWishlist'])->name('wishlist.toggle');
    Route::post('/stores/{seller}/follow', [WishlistController::class, 'toggleFollow'])->name('stores.follow');

    // Cart, Split Checkout, Voucher Preview & Invoices + PDF
    Route::get('/cart', [CheckoutController::class, 'cart'])->name('cart.index');
    Route::post('/cart/add', [CheckoutController::class, 'addToCart'])->name('cart.add');
    Route::patch('/cart/{cartItem}', [CheckoutController::class, 'updateCartItem'])->name('cart.update');
    Route::delete('/cart/{cartItem}', [CheckoutController::class, 'removeCartItem'])->name('cart.remove');
    Route::post('/checkout/voucher-preview', [CheckoutController::class, 'previewVoucher'])
        ->middleware('throttle:voucher-preview')
        ->name('checkout.voucher-preview');
    Route::post('/checkout', [CheckoutController::class, 'processCheckout'])
        ->middleware('idempotent')
        ->name('checkout.process');

    Route::get('/invoices/{invoice_code}', [CheckoutController::class, 'showInvoice'])->name('invoices.show');
    Route::get('/invoices/{invoice_code}/pdf', [ExportController::class, 'invoicePdf'])->name('invoices.pdf');
    Route::post('/invoices/{invoice_code}/snap-token', [CheckoutController::class, 'issueSnapToken'])
        ->middleware('throttle:wallet-sensitive')
        ->name('invoices.snap-token');
    Route::post('/invoices/{invoice_code}/sync-status', [CheckoutController::class, 'syncPaymentStatus'])
        ->middleware('throttle:wallet-sensitive')
        ->name('invoices.sync-status');
    Route::post('/invoices/{invoice_code}/simulate-pay', [CheckoutController::class, 'simulateMidtransPay'])
        ->middleware('simulation.only')
        ->name('invoices.simulate-pay');

    // Buyer Orders, PDF Receipt, Anti-Hackback Warranties, Reports & Wallet
    Route::get('/buyer/warranties', [WarrantyController::class, 'index'])->name('buyer.warranties');
    Route::post('/buyer/orders/{order}/warranty-claim', [WarrantyController::class, 'fileClaim'])->name('buyer.orders.warranty-claim');
    Route::post('/reports', [WarrantyController::class, 'submitReport'])->name('reports.store');
    Route::get('/admin/warranties/{claim}/evidence/{index}', [AdminWarrantyController::class, 'streamEvidence'])
        ->whereNumber('index')
        ->name('admin.warranties.evidence');

    Route::get('/buyer/orders', [BuyerOrderController::class, 'index'])->name('buyer.orders.index');
    Route::get('/buyer/orders/{order_code}', [BuyerOrderController::class, 'show'])->name('buyer.orders.show');
    Route::get('/buyer/orders/{order_code}/pdf', [ExportController::class, 'orderPdf'])->name('buyer.orders.pdf');
    Route::post('/buyer/orders/{order_code}/decrypt/{orderItem}', [BuyerOrderController::class, 'decryptVaultItem'])->name('buyer.orders.decrypt');
    Route::post('/buyer/orders/{order_code}/claim-sla-refund', [BuyerOrderController::class, 'claimSlaRefund'])->name('buyer.orders.claim-sla');
    Route::post('/buyer/orders/{order_code}/cancel', [BuyerOrderController::class, 'cancelUnprocessedOrder'])
        ->middleware('idempotent')
        ->name('buyer.orders.cancel');
    Route::post('/buyer/orders/{order_code}/confirm-complete', [BuyerOrderController::class, 'confirmComplete'])->name('buyer.orders.confirm');
    Route::post('/buyer/orders/{order_code}/dispute', [BuyerOrderController::class, 'openDispute'])->name('buyer.orders.dispute');
    Route::post('/buyer/orders/{order_code}/messages', [BuyerOrderController::class, 'sendMessage'])
        ->middleware('throttle:chat-send')
        ->name('buyer.orders.messages');
    Route::get('/buyer/wallet', [BuyerOrderController::class, 'wallet'])->name('buyer.wallet');
    Route::post('/buyer/wallet/topup', [WalletTopUpController::class, 'store'])
        ->middleware(['idempotent', 'throttle:wallet-sensitive'])
        ->name('buyer.wallet.topup');
    Route::get('/buyer/wallet/topup/{topup_code}', [WalletTopUpController::class, 'show'])
        ->name('buyer.wallet.topup.show');
    Route::post('/buyer/wallet/topup/{topup_code}/snap-token', [WalletTopUpController::class, 'issueSnapToken'])
        ->middleware('throttle:wallet-sensitive')
        ->name('buyer.wallet.topup.snap-token');
    Route::post('/buyer/wallet/topup/{topup_code}/sync-status', [WalletTopUpController::class, 'syncPaymentStatus'])
        ->middleware('throttle:wallet-sensitive')
        ->name('buyer.wallet.topup.sync-status');
    Route::post('/buyer/wallet/topup/{topup_code}/simulate-pay', [WalletTopUpController::class, 'simulatePay'])
        ->middleware('simulation.only')
        ->name('buyer.wallet.topup.simulate-pay');
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
    Route::post('/products/{id}/vault/bulk-import', [SellerDashboardController::class, 'ingestVault'])->name('products.vault.bulk-import');

    Route::get('/orders', [SellerDashboardController::class, 'orders'])->name('orders');
    Route::post('/orders/{order_code}/accept', [SellerDashboardController::class, 'acceptManualOrder'])->name('orders.accept');
    Route::post('/orders/{order_code}/deliver', [SellerDashboardController::class, 'deliverOrder'])->name('orders.deliver');
    Route::post('/orders/{order_code}/messages', [SellerDashboardController::class, 'sendMessage'])
        ->middleware('throttle:chat-send')
        ->name('orders.messages');

    // Wallet, Withdrawals (PIN + Idempotency + Throttle), Security Deposit & Clawback Debt Repayment
    Route::get('/wallet', [SellerDashboardController::class, 'wallet'])->name('wallet');
    Route::post('/wallet/withdraw', [SellerDashboardController::class, 'requestWithdrawal'])
        ->middleware(['idempotent', 'throttle:wallet-sensitive'])
        ->name('wallet.withdraw');
    Route::post('/wallet/lock-deposit', [SellerPromoController::class, 'lockDeposit'])
        ->middleware('throttle:wallet-sensitive')
        ->name('wallet.lock-deposit');
    Route::post('/wallet/repay-debt', [SellerDebtController::class, 'repayFromBalance'])
        ->middleware(['idempotent', 'throttle:wallet-sensitive'])
        ->name('wallet.repay-debt');
    Route::post('/wallet/repay-debt-gateway', [SellerDebtController::class, 'repayViaGateway'])
        ->middleware(['simulation.only', 'throttle:wallet-sensitive'])
        ->name('wallet.repay-debt-gateway');
    Route::get('/bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts');

    // Seller Ad Credits, Store Level & CSV Exports
    Route::get('/ad-credits', [SellerAdCreditController::class, 'index'])->name('ad-credits');
    Route::post('/ad-credits/purchase', [SellerAdCreditController::class, 'purchase'])
        ->middleware(['idempotent', 'throttle:wallet-sensitive'])
        ->name('ad-credits.purchase');
    Route::post('/ad-credits/boost/{product}', [SellerAdCreditController::class, 'boostFeatured'])
        ->name('ad-credits.boost');
    Route::get('/exports/orders.csv', [ExportController::class, 'sellerOrdersCsv'])->name('exports.orders');
    Route::get('/exports/mutations.csv', [ExportController::class, 'sellerMutationsCsv'])->name('exports.mutations');

    Route::get('/vouchers', [SellerPromoController::class, 'vouchers'])->name('vouchers');
    Route::post('/vouchers', [SellerPromoController::class, 'storeVoucher'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}/toggle', [SellerPromoController::class, 'toggleVoucher'])->name('vouchers.toggle');

    Route::get('/settings', [SellerDashboardController::class, 'settings'])->name('settings');
    Route::post('/settings', [SellerDashboardController::class, 'updateSettings'])->name('settings.update');
    Route::put('/settings', [SellerDashboardController::class, 'updateSettings'])->name('settings.put');
    Route::post('/reviews/{reviewId}/reply', [SellerDashboardController::class, 'replyReview'])->name('reviews.reply');
});

// Admin Risk, CMS, Finance P&L & 8-Scenario Backend Chaos Lab (Protected by Granular Admin RBAC Matrix)
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin'])->group(function (): void {
    // Phase 4: Corporate Finance P&L, Global Ledger Audit & H2H Reconcile
    Route::get('/finance', [AdminFinanceController::class, 'index'])
        ->middleware('admin.perm:finance.view')
        ->name('finance.index');
    Route::post('/finance/audit-ledger', [AdminFinanceController::class, 'runLedgerAudit'])
        ->middleware('admin.perm:finance.audit')
        ->name('finance.audit-ledger');
    Route::post('/finance/reconcile-h2h', [AdminFinanceController::class, 'runH2HReconcile'])
        ->middleware('admin.perm:finance.audit')
        ->name('finance.reconcile-h2h');

    // KYC Officer & Super Admin
    Route::middleware('admin.perm:kyc.review')->group(function (): void {
        Route::get('/kyc', [AdminKycController::class, 'index'])->name('kyc.index');
        Route::post('/kyc/{kyc}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
        Route::post('/kyc/{kyc}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');
    });

    // CS Arbitrator & Super Admin — Disputes
    Route::middleware('admin.perm:disputes.resolve')->group(function (): void {
        Route::get('/disputes', [AdminChaosLabController::class, 'disputes'])->name('disputes');
        Route::post('/disputes/{order_code}/resolve', [AdminChaosLabController::class, 'resolveDispute'])->name('disputes.resolve');
        Route::post('/disputes/{order_code}/messages', [AdminChaosLabController::class, 'sendAdminMessage'])->name('disputes.messages');
    });

    // Finance & Super Admin — Maker-Checker Withdrawals
    Route::middleware('admin.perm:withdrawals.approve')->group(function (): void {
        Route::get('/withdrawals', [AdminChaosLabController::class, 'withdrawals'])->name('withdrawals');
        Route::post('/withdrawals/{id}/approve', [AdminChaosLabController::class, 'approveWithdrawal'])->name('withdrawals.approve');
        Route::post('/withdrawals/{id}/reject', [AdminChaosLabController::class, 'rejectWithdrawal'])->name('withdrawals.reject');
    });

    // Phase 3: Anti-Hackback Warranty Court (CS Arbitrator & Super Admin)
    Route::middleware('admin.perm:warranty.resolve')->group(function (): void {
        Route::get('/warranties', [AdminWarrantyController::class, 'index'])->name('warranties.index');
        Route::post('/warranties/{claim}/resolve', [AdminWarrantyController::class, 'resolve'])->name('warranties.resolve');
    });

    // Phase 3: Admin CMS — Master Catalog, Platform Vouchers & Flash Sales (Super Admin)
    Route::middleware('admin.perm:cms.manage')->group(function (): void {
        Route::get('/cms/catalog', [AdminCmsController::class, 'catalog'])->name('cms.catalog');
        Route::post('/cms/games', [AdminCmsController::class, 'storeGame'])->name('cms.games.store');
        Route::put('/cms/games/{id}', [AdminCmsController::class, 'updateGame'])->name('cms.games.update');
        Route::patch('/cms/games/{id}/toggle', [AdminCmsController::class, 'toggleGame'])->name('cms.games.toggle');
        Route::post('/cms/categories', [AdminCmsController::class, 'storeCategory'])->name('cms.categories.store');
        Route::put('/cms/categories/{id}', [AdminCmsController::class, 'updateCategory'])->name('cms.categories.update');
        Route::post('/cms/attributes', [AdminCmsController::class, 'storeAttribute'])->name('cms.attributes.store');
        Route::post('/cms/attributes/{attributeId}/options', [AdminCmsController::class, 'storeAttributeOption'])->name('cms.attributes.options.store');
        Route::delete('/cms/options/{optionId}', [AdminCmsController::class, 'deleteAttributeOption'])->name('cms.attributes.options.delete');

        Route::get('/cms/promos', [AdminCmsController::class, 'promos'])->name('cms.promos');
        Route::post('/cms/vouchers', [AdminCmsController::class, 'storePlatformVoucher'])->name('cms.vouchers.store');
        Route::patch('/cms/vouchers/{voucherId}/toggle', [AdminCmsController::class, 'toggleVoucher'])->name('cms.vouchers.toggle');
        Route::post('/cms/flash-sales', [AdminCmsController::class, 'storeFlashSale'])->name('cms.flash-sales.store');
        Route::post('/cms/flash-sales/{flashSaleId}/items', [AdminCmsController::class, 'attachFlashSaleItem'])->name('cms.flash-sales.items.attach');
    });

    // Phase 3: Moderation Reports & Audit Trail (CS Arbitrator & Super Admin)
    Route::middleware('admin.perm:moderation.manage')->group(function (): void {
        Route::get('/reports', [AdminCmsController::class, 'reports'])->name('reports.index');
        Route::post('/reports/{report}/resolve', [AdminCmsController::class, 'resolveReport'])->name('reports.resolve');
        Route::get('/audit-logs', [AdminCmsController::class, 'auditLogs'])->name('audit-logs');
    });

    Route::get('/chaos-lab', [AdminChaosLabController::class, 'chaosLab'])
        ->middleware(['simulation.only', 'admin.perm:chaos.run'])
        ->name('chaos-lab');
    Route::post('/chaos-lab/run/{scenario}', [AdminChaosLabController::class, 'runScenario'])
        ->middleware(['simulation.only', 'admin.perm:chaos.run'])
        ->name('chaos-lab.run');
});
