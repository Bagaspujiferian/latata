<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\FlashSale;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\ModerationReport;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\AdminCmsAndAuditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminCmsController extends Controller
{
    public function __construct(
        private readonly AdminCmsAndAuditService $cmsService,
    ) {}

    public function catalog(): View
    {
        $games = Game::with([
            'categories.attributes.options',
            'categories' => fn ($q) => $q->withCount('products'),
        ])
            ->withCount('categories')
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get();

        $allCategories = GameCategory::with(['game', 'attributes.options'])
            ->withCount('products')
            ->orderBy('game_id')
            ->orderBy('id')
            ->get();

        return view('admin.cms-catalog', compact('games', 'allCategories'));
    }

    public function storeGame(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120'],
            'publisher' => ['required', 'string', 'min:2', 'max:100'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'requires_nickname_check' => ['nullable', 'boolean'],
            'nickname_provider_code' => ['nullable', 'string', 'max:40'],
            'is_popular' => ['nullable', 'boolean'],
        ]);

        try {
            $validated['requires_nickname_check'] = $request->boolean('requires_nickname_check');
            $validated['is_popular'] = $request->boolean('is_popular');
            $game = $this->cmsService->createGame($this->requireUser($request)->id, $validated);

            return back()->with('success', "Game {$game->name} berhasil ditambahkan dan dicatat ke Admin Audit Log.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function updateGame(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'publisher' => ['required', 'string', 'min:2', 'max:100'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'is_popular' => ['nullable', 'boolean'],
        ]);

        try {
            $validated['is_popular'] = $request->boolean('is_popular');
            $game = $this->cmsService->updateGame($this->requireUser($request)->id, $id, $validated);

            return back()->with('success', "Game {$game->name} berhasil diperbarui.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleGame(Request $request, int $id): RedirectResponse
    {
        $game = $this->cmsService->toggleGameActive($this->requireUser($request)->id, $id);

        return back()->with('success', "Status aktif game {$game->name} diubah.");
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'delivery_method' => ['required', 'in:instant_vault,api_h2h,manual_trade'],
            'platform_fee_percent' => ['required', 'numeric', 'min:0', 'max:35'],
            'escrow_hold_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'warranty_days' => ['required', 'integer', 'min:0', 'max:365'],
            'required_inputs_schema' => ['nullable'],
        ]);

        try {
            $cat = $this->cmsService->createCategory($this->requireUser($request)->id, $validated);

            return back()->with('success', "Kategori {$cat->name} (Escrow {$cat->escrow_hold_hours}j, Garansi {$cat->warranty_days} hari) berhasil dibuat.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function updateCategory(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'delivery_method' => ['required', 'in:instant_vault,api_h2h,manual_trade'],
            'platform_fee_percent' => ['required', 'numeric', 'min:0', 'max:35'],
            'escrow_hold_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'warranty_days' => ['required', 'integer', 'min:0', 'max:365'],
            'required_inputs_schema' => ['nullable'],
        ]);

        try {
            $cat = $this->cmsService->updateCategory($this->requireUser($request)->id, $id, $validated);

            return back()->with('success', "Konfigurasi kategori {$cat->name} berhasil diperbarui & dicatat ke Audit Log.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function storeAttribute(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'game_category_id' => ['required', 'integer', 'exists:game_categories,id'],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'input_type' => ['required', 'in:select,number,boolean,text'],
            'unit_label' => ['nullable', 'string', 'max:30'],
            'is_required' => ['nullable', 'boolean'],
            'is_filterable' => ['nullable', 'boolean'],
            'options_csv' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $validated['is_required'] = $request->boolean('is_required');
            $validated['is_filterable'] = $request->boolean('is_filterable', true);
            $attr = $this->cmsService->createAttribute($this->requireUser($request)->id, $validated);

            return back()->with('success', "Atribut filter [{$attr->name}] berhasil ditambahkan.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function storeAttributeOption(Request $request, int $attributeId): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'min:1', 'max:80'],
        ]);

        try {
            $opt = $this->cmsService->addAttributeOption($this->requireUser($request)->id, $attributeId, $validated['label']);

            return back()->with('success', "Opsi [{$opt->label}] berhasil ditambahkan.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteAttributeOption(Request $request, int $optionId): RedirectResponse
    {
        $this->cmsService->deleteAttributeOption($this->requireUser($request)->id, $optionId);

        return back()->with('success', 'Opsi atribut dihapus.');
    }

    public function promos(): View
    {
        $platformVouchers = Voucher::with('game')
            ->withSum(['usages as applied_subsidy_total' => fn ($q) => $q->where('status', 'applied')], 'discount_amount')
            ->where('issuer_type', 'platform')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        $totalPlatformSubsidy = (float) $platformVouchers->sum('applied_subsidy_total');

        $flashSales = FlashSale::with(['items.product.gameCategory.game', 'items.product.sellerProfile'])
            ->orderByDesc('id')
            ->get();

        $games = Game::where('is_active', true)->orderBy('name')->get();
        $products = Product::with(['gameCategory.game', 'sellerProfile'])
            ->where('is_active', true)
            ->orderBy('title')
            ->get();

        return view('admin.cms-promos', compact(
            'platformVouchers',
            'totalPlatformSubsidy',
            'flashSales',
            'games',
            'products',
        ));
    }

    public function storePlatformVoucher(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:40'],
            'title' => ['required', 'string', 'min:4', 'max:120'],
            'game_id' => ['nullable', 'integer', 'exists:games,id'],
            'discount_type' => ['required', 'in:fixed,percent'],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:1000'],
            'min_purchase_amount' => ['nullable', 'numeric', 'min:0'],
            'quota_total' => ['required', 'integer', 'min:1', 'max:100000'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1', 'max:20'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        try {
            $voucher = $this->cmsService->createVoucher($this->requireUser($request)->id, 'platform', $validated);

            return back()->with('success', "Voucher Subsidi Platform [{$voucher->code}] resmi aktif!");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function toggleVoucher(Request $request, int $voucherId): RedirectResponse
    {
        $voucher = $this->cmsService->toggleVoucher($this->requireUser($request)->id, $voucherId, true);

        return back()->with('success', "Status voucher [{$voucher->code}] diperbarui.");
    }

    public function storeFlashSale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'banner_subtitle' => ['nullable', 'string', 'max:200'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $sale = $this->cmsService->createFlashSale($this->requireUser($request)->id, $validated);

        return back()->with('success', "Event Flash Sale \"{$sale->title}\" berhasil dibuat.");
    }

    public function attachFlashSaleItem(Request $request, int $flashSaleId): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'flash_price' => ['required', 'numeric', 'min:1000'],
            'flash_quota' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_qty_per_user' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $item = $this->cmsService->attachFlashSaleItem($this->requireUser($request)->id, $flashSaleId, $validated);

            $productTitle = $item->product->title ?? 'Produk';

            return back()->with('success', "Produk {$productTitle} ditambahkan ke Flash Sale dengan harga Rp ".number_format((float) $item->flash_price, 0, ',', '.').'.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reports(Request $request): View
    {
        $status = $request->string('status', 'open')->toString();
        if (! in_array($status, ['open', 'action_taken', 'dismissed', 'all'], true)) {
            $status = 'open';
        }

        $query = ModerationReport::with(['reporter', 'reviewer'])->orderByDesc('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $reports = $query->get();
        $openCount = ModerationReport::where('status', 'open')->count();

        // Preload target labels cleanly
        $productMap = Product::withTrashed()
            ->whereIn('id', $reports->where('target_type', 'product')->pluck('target_id'))
            ->get()
            ->keyBy('id');

        return view('admin.reports', compact('reports', 'status', 'openCount', 'productMap'));
    }

    public function resolveReport(Request $request, ModerationReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', 'in:dismiss,takedown_product,suspend_seller'],
            'resolution_notes' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        try {
            $this->cmsService->resolveReport($this->requireUser($request)->id, $report->id, $validated['action_type'], $validated['resolution_notes']);

            return back()->with('success', "Laporan moderasi #{$report->id} selesai ditindaklanjuti dan tercatat di Audit Log.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function auditLogs(Request $request): View
    {
        $actionFilter = trim($request->string('action')->toString());
        $entityFilter = trim($request->string('entity_type')->toString());

        $query = AdminAuditLog::with('admin')->orderByDesc('id');
        if ($actionFilter !== '') {
            $query->where('action', $actionFilter);
        }
        if ($entityFilter !== '') {
            $query->where('entity_type', $entityFilter);
        }

        $logs = $query->limit(150)->get();
        $distinctActions = AdminAuditLog::distinct()->orderBy('action')->pluck('action');
        $distinctEntities = AdminAuditLog::distinct()->orderBy('entity_type')->pluck('entity_type');

        return view('admin.audit-logs', compact(
            'logs',
            'actionFilter',
            'entityFilter',
            'distinctActions',
            'distinctEntities',
        ));
    }
}
