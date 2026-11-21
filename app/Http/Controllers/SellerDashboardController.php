<?php

namespace App\Http\Controllers;

use App\Models\GameCategory;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\StoreOperatingHour;
use App\Models\UserBankAccount;
use App\Models\WalletMutation;
use App\Models\WithdrawalRequest;
use App\Services\ChatModerationService;
use App\Services\OrderStateAndSlaService;
use App\Services\ProductMerchandisingService;
use App\Services\SellerCatalogService;
use App\Services\StorefrontEngagementService;
use App\Services\StorefrontService;
use App\Services\VaultEncryptionService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SellerDashboardController extends Controller
{
    public function __construct(
        private readonly SellerCatalogService $catalogService,
        private readonly VaultEncryptionService $vaultService,
        private readonly OrderStateAndSlaService $orderStateService,
        private readonly ChatModerationService $chatModerationService,
        private readonly ProductMerchandisingService $merchandisingService,
        private readonly StorefrontEngagementService $engagementService,
        private readonly StorefrontService $storefrontService,
    ) {}

    public function dashboard(Request $request): View
    {
        $user = $request->user()->load(['sellerProfile.operatingHours', 'wallet']);

        $activeQueueCount = Order::where('seller_id', $user->id)
            ->where('status', 'PROCESSING')
            ->count();

        $disputedCount = Order::where('seller_id', $user->id)
            ->where('status', 'DISPUTED')
            ->count();

        $recentOrders = Order::with(['items.product', 'buyer'])
            ->where('seller_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'PROCESSING' THEN 0 WHEN status = 'DISPUTED' THEN 1 ELSE 2 END")
            ->orderBy('sla_deadline_at', 'asc')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $topProducts = Product::with('gameCategory.game')
            ->where('seller_id', $user->id)
            ->orderByDesc('sold_count')
            ->limit(5)
            ->get();

        $totalGrossRevenue = (float) Order::where('seller_id', $user->id)
            ->whereIn('status', ['DELIVERED', 'COMPLETED'])
            ->sum('seller_escrow_amount');

        return view('seller.dashboard', compact(
            'user',
            'activeQueueCount',
            'disputedCount',
            'recentOrders',
            'topProducts',
            'totalGrossRevenue',
        ));
    }

    public function toggleStoreOpen(Request $request): RedirectResponse
    {
        $profile = $this->catalogService->toggleStoreOpen($request->user()->id);
        $stateText = $profile->is_store_open ? 'BUKA (Menerima Manual Trade + 24/7 Instan)' : 'TUTUP (Manual Trade dijeda, Brankas Instan & H2H tetap aktif 24/7)';

        return back()->with('success', "Status toko diperbarui: {$stateText}.");
    }

    public function products(Request $request): View
    {
        $user = $request->user()->load('sellerProfile');

        $products = Product::with([
            'gameCategory.game',
            'gameCategory.attributes.options',
            'images',
            'attributeValues.option',
        ])
            ->withCount([
                'vaults as vault_available_count' => fn ($q) => $q->where('status', 'available'),
                'vaults as vault_reserved_count' => fn ($q) => $q->where('status', 'reserved'),
                'vaults as vault_sold_count' => fn ($q) => $q->where('status', 'sold'),
            ])
            ->where('seller_id', $user->id)
            ->orderByDesc('is_featured')
            ->orderByDesc('last_bumped_at')
            ->get();

        $archivedProducts = Product::onlyTrashed()
            ->with('gameCategory.game')
            ->where('seller_id', $user->id)
            ->orderByDesc('deleted_at')
            ->get();

        $categories = GameCategory::with('game')->orderBy('game_id')->get();

        return view('seller.products', compact('user', 'products', 'archivedProducts', 'categories'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'game_category_id' => ['required', 'integer', 'exists:game_categories,id'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'price' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'min_order' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_order' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sla_target_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'h2h_provider_sku' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $product = $this->catalogService->createProduct($request->user()->id, $validated);

            return back()->with('success', "Produk {$product->title} ({$product->sku_code}) berhasil dibuat.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function updateProduct(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'price' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'sla_target_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $product = $this->catalogService->updateProduct($id, $request->user()->id, $validated);

            return back()->with('success', "Produk {$product->title} berhasil diperbarui (Stok terverifikasi: {$product->stock}).");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function archiveProduct(Request $request, int $id): RedirectResponse
    {
        try {
            $this->merchandisingService->softDeleteProduct($id, $request->user()->id);

            return back()->with('success', 'Produk berhasil diarsipkan (soft-delete). Riwayat pesanan lama tetap utuh.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function restoreProduct(Request $request, int $id): RedirectResponse
    {
        try {
            $product = $this->merchandisingService->restoreProduct($id, $request->user()->id);

            return back()->with('success', "Produk \"{$product->title}\" berhasil dipulihkan ke etalase aktif.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function addProductImage(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'image_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:3072'],
            'image_url' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:150'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $imagePath = null;

        if ($request->hasFile('image_file')) {
            $file = $request->file('image_file');
            $filename = 'prod-'.$id.'-'.Str::random(10).'.'.$file->getClientOriginalExtension();
            $targetDir = public_path('uploads/products');
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $file->move($targetDir, $filename);
            $imagePath = '/uploads/products/'.$filename;
        } elseif (! empty($validated['image_url'])) {
            $imagePath = trim((string) $validated['image_url']);
        }

        if ($imagePath === null) {
            return back()->with('error', 'Unggah file gambar atau masukkan URL/path gambar produk.');
        }

        try {
            $this->merchandisingService->addProductImage(
                productId: $id,
                sellerId: $request->user()->id,
                imagePath: $imagePath,
                altText: $validated['alt_text'] ?? null,
                isPrimary: (bool) ($validated['is_primary'] ?? false),
            );

            return back()->with('success', 'Foto berhasil ditambahkan ke galeri multi-foto produk.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setPrimaryProductImage(Request $request, int $id, int $imageId): RedirectResponse
    {
        try {
            $this->merchandisingService->setPrimaryProductImage($id, $imageId, $request->user()->id);

            return back()->with('success', 'Foto utama produk berhasil diperbarui.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteProductImage(Request $request, int $id, int $imageId): RedirectResponse
    {
        try {
            $this->merchandisingService->deleteProductImage($id, $imageId, $request->user()->id);

            return back()->with('success', 'Foto dihapus dari galeri produk.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateProductAttributes(Request $request, int $id): RedirectResponse
    {
        $attributes = is_array($request->input('attributes')) ? $request->input('attributes') : [];

        try {
            $this->merchandisingService->syncProductAttributes($id, $request->user()->id, $attributes);

            return back()->with('success', 'Spesifikasi & atribut terindeks produk berhasil disimpan.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function bumpProduct(Request $request, int $id): RedirectResponse
    {
        try {
            $result = $this->catalogService->bumpProduct($id, $request->user()->id);

            return back()->with('success', "Produk \"{$result['product']->title}\" berhasil disundul ke posisi teratas! Sisa kredit sundul: {$result['remaining_credits']}.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function ingestVault(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'vault_lines' => ['required', 'string', 'min:3'],
        ]);

        $lines = preg_split('/\r\n|\r|\n/', $validated['vault_lines']) ?: [];

        try {
            $report = $this->vaultService->bulkIngestVaultItems($id, $request->user()->id, $lines);

            return back()
                ->with('vault_ingest_report', $report)
                ->with('success', "Brankas AES-256 Diperbarui: {$report['inserted_count']} kode baru terenkripsi, {$report['duplicate_count']} duplikat diblokir via HMAC-SHA256 Blind Index. Stok tersedia kini: {$report['current_available_stock']}.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function orders(Request $request): View
    {
        $user = $request->user()->load('sellerProfile');
        $statusFilter = $request->string('status')->toString();

        $ordersQuery = Order::with(['items.product.gameCategory.game', 'buyer', 'messages.sender', 'paymentInvoice'])
            ->where('seller_id', $user->id);

        if ($statusFilter !== '') {
            $ordersQuery->where('status', $statusFilter);
        }

        $orders = $ordersQuery
            ->orderByRaw("CASE WHEN status = 'PROCESSING' THEN 0 WHEN status = 'DISPUTED' THEN 1 WHEN status = 'DELIVERED' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN sla_deadline_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sla_deadline_at', 'asc')
            ->orderByDesc('id')
            ->get();

        return view('seller.orders', compact('user', 'orders', 'statusFilter'));
    }

    public function deliverOrder(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $validated = $request->validate([
            'delivery_notes' => ['required', 'string', 'min:5', 'max:500'],
            'proof_url' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->orderStateService->sellerSubmitManualDelivery(
                orderId: $order->id,
                sellerId: $request->user()->id,
                deliveryNotes: $validated['delivery_notes'],
                proofUrl: $validated['proof_url'] ?? null,
            );

            return back()->with('success', "Bukti pengiriman untuk {$order->order_code} terkirim! Metrik SLA toko diperbarui otomatis.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendMessage(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $user = $request->user();

        if ((int) $order->seller_id !== (int) $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $msg = $this->chatModerationService->postOrderMessage(
            order: $order,
            sender: $user,
            rawMessage: $validated['message'],
        );

        if ($msg->is_flagged_bypass) {
            return back()->with('warning', "Peringatan Anti-Bypass: Pola [{$msg->detected_violation_type}] otomatis disensor. Pelanggaran berulang dapat membekukan akun toko.");
        }

        return back()->with('success', 'Pesan terkirim ke pembeli.');
    }

    public function wallet(Request $request): View
    {
        $user = $request->user()->load(['wallet', 'sellerProfile']);

        $mutations = WalletMutation::with(['order', 'paymentInvoice'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $withdrawals = WithdrawalRequest::with('bankAccount')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $bankAccounts = UserBankAccount::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get();

        $adminFee = (float) config('nexatrade.withdrawal_admin_fee', 2500.00);

        return view('seller.wallet', compact('user', 'mutations', 'withdrawals', 'bankAccounts', 'adminFee'));
    }

    public function requestWithdrawal(Request $request): RedirectResponse
    {
        $hasSavedAccount = $request->filled('user_bank_account_id');

        $validated = $request->validate([
            'user_bank_account_id' => ['nullable', 'integer', 'exists:user_bank_accounts,id'],
            'bank_name' => [$hasSavedAccount ? 'nullable' : 'required', 'string', 'max:50'],
            'account_number' => [$hasSavedAccount ? 'nullable' : 'required', 'string', 'min:6', 'max:50'],
            'account_holder' => [$hasSavedAccount ? 'nullable' : 'required', 'string', 'min:3', 'max:100'],
            'amount' => ['required', 'numeric', 'min:10000'],
        ]);

        try {
            $withdrawal = $this->orderStateService->requestWithdrawal(
                userId: $request->user()->id,
                bankName: (string) ($validated['bank_name'] ?? ''),
                accountNumber: (string) ($validated['account_number'] ?? ''),
                accountHolder: (string) ($validated['account_holder'] ?? ''),
                amount: (float) $validated['amount'],
                userBankAccountId: $hasSavedAccount ? (int) $validated['user_bank_account_id'] : null,
            );

            return back()->with('success', "Pengajuan tarik saldo {$withdrawal->withdraw_code} berhasil! Saldo ditahan di muka (WITHDRAW_HOLD), nominal bersih Rp ".number_format((float) $withdrawal->net_payout, 0, ',', '.').'.');
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function settings(Request $request): View
    {
        $user = $request->user()->load(['sellerProfile.operatingHours']);
        $profile = $user->sellerProfile;

        $hoursMap = $profile?->operatingHours->keyBy('day_of_week') ?? collect();
        $days = StoreOperatingHour::DAY_NAMES;

        $reviews = OrderReview::with(['buyer', 'product', 'order'])
            ->where('seller_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $followersCount = $profile ? $profile->followers()->count() : 0;

        return view('seller.settings', compact('user', 'profile', 'hoursMap', 'days', 'reviews', 'followersCount'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_name' => ['required', 'string', 'min:3', 'max:120'],
            'store_tagline' => ['nullable', 'string', 'max:150'],
            'store_bio' => ['nullable', 'string', 'max:500'],
            'store_banner_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:3072'],
            'store_banner_url' => ['nullable', 'string', 'max:255'],
            'is_store_open' => ['nullable', 'boolean'],
            'auto_schedule_enabled' => ['nullable', 'boolean'],
            'hours' => ['nullable', 'array'],
        ]);

        $profileData = [
            'store_name' => $validated['store_name'],
            'store_tagline' => $validated['store_tagline'] ?? null,
            'store_bio' => $validated['store_bio'] ?? null,
            'auto_schedule_enabled' => $request->boolean('auto_schedule_enabled'),
        ];

        if ($request->has('is_store_open')) {
            $profileData['is_store_open'] = $request->boolean('is_store_open');
        }

        if (! empty($validated['store_banner_url'])) {
            $profileData['store_banner_path'] = trim((string) $validated['store_banner_url']);
        }

        $hours = is_array($request->input('hours')) ? $request->input('hours') : [];

        try {
            $this->storefrontService->updateStoreSettings(
                sellerId: $request->user()->id,
                profileData: $profileData,
                operatingHours: $hours,
                bannerFile: $request->file('store_banner_file'),
            );

            return back()->with('success', 'Profil toko & jadwal operasional 7 hari berhasil diperbarui.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function replyReview(Request $request, int $reviewId): RedirectResponse
    {
        $validated = $request->validate([
            'seller_reply' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $this->storefrontService->replyToReview($request->user()->id, $reviewId, $validated['seller_reply']);

            return back()->with('success', 'Balasan ulasan pembeli berhasil dipublikasikan.');
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
