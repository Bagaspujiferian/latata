<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AttributeOption;
use App\Models\CategoryAttribute;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\ModerationReport;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Voucher;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCmsAndAuditService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordAudit(
        int $adminId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        ?string $notes = null,
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'admin_id' => $adminId,
            'action' => mb_substr(strtoupper(trim($action)), 0, 80),
            'entity_type' => mb_substr(trim($entityType), 0, 60),
            'entity_id' => $entityId,
            'before_state' => $before,
            'after_state' => $after,
            'ip_address' => request()?->ip(),
            'notes' => $notes ? mb_substr(trim($notes), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createGame(int $adminId, array $data): Game
    {
        return DB::transaction(function () use ($adminId, $data): Game {
            $name = trim((string) $data['name']);
            $slug = ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name);
            if ($slug === '' || Game::where('slug', $slug)->exists()) {
                throw new DomainException("Slug game [{$slug}] tidak valid atau sudah digunakan.");
            }

            $game = Game::create([
                'name' => $name,
                'slug' => $slug,
                'publisher' => trim((string) ($data['publisher'] ?? 'Official Publisher')),
                'thumbnail_url' => trim((string) ($data['thumbnail_url'] ?? '/images/games/steam-wallet.svg')),
                'requires_nickname_check' => (bool) ($data['requires_nickname_check'] ?? false),
                'nickname_provider_code' => ! empty($data['nickname_provider_code']) ? strtoupper(trim((string) $data['nickname_provider_code'])) : null,
                'is_popular' => (bool) ($data['is_popular'] ?? false),
                'is_active' => true,
            ]);

            $this->recordAudit($adminId, 'CMS_GAME_CREATED', 'game', $game->id, null, $game->toArray(), "Tambah game baru: {$game->name}");

            return $game;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGame(int $adminId, int $gameId, array $data): Game
    {
        return DB::transaction(function () use ($adminId, $gameId, $data): Game {
            $game = Game::where('id', $gameId)->lockForUpdate()->firstOrFail();
            $before = $game->toArray();

            $game->name = trim((string) ($data['name'] ?? $game->name));
            $game->publisher = trim((string) ($data['publisher'] ?? $game->publisher));
            if (! empty($data['thumbnail_url'])) {
                $game->thumbnail_url = trim((string) $data['thumbnail_url']);
            }
            if (array_key_exists('requires_nickname_check', $data)) {
                $game->requires_nickname_check = (bool) $data['requires_nickname_check'];
            }
            if (array_key_exists('is_popular', $data)) {
                $game->is_popular = (bool) $data['is_popular'];
            }
            if (array_key_exists('is_active', $data)) {
                $game->is_active = (bool) $data['is_active'];
            }
            $game->save();

            $this->recordAudit($adminId, 'CMS_GAME_UPDATED', 'game', $game->id, $before, $game->toArray(), "Perbarui game: {$game->name}");

            return $game->fresh();
        });
    }

    public function toggleGameActive(int $adminId, int $gameId): Game
    {
        return DB::transaction(function () use ($adminId, $gameId): Game {
            $game = Game::where('id', $gameId)->lockForUpdate()->firstOrFail();
            $before = ['is_active' => $game->is_active];
            $game->is_active = ! $game->is_active;
            $game->save();

            $this->recordAudit($adminId, 'CMS_GAME_TOGGLED', 'game', $game->id, $before, ['is_active' => $game->is_active], "Toggle aktif game {$game->name}");

            return $game;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(int $adminId, array $data): GameCategory
    {
        return DB::transaction(function () use ($adminId, $data): GameCategory {
            $game = Game::findOrFail((int) $data['game_id']);
            $name = trim((string) $data['name']);
            $slug = ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name);

            if (GameCategory::where('game_id', $game->id)->where('slug', $slug)->exists()) {
                throw new DomainException("Kategori [{$slug}] sudah ada pada game {$game->name}.");
            }

            $schema = $this->sanitizeCheckoutSchema($data['required_inputs_schema'] ?? []);

            $category = GameCategory::create([
                'game_id' => $game->id,
                'name' => $name,
                'slug' => $slug,
                'delivery_method' => (string) $data['delivery_method'],
                'platform_fee_percent' => number_format((float) ($data['platform_fee_percent'] ?? 5.00), 2, '.', ''),
                'escrow_hold_hours' => max(1, (int) ($data['escrow_hold_hours'] ?? 24)),
                'warranty_days' => max(0, (int) ($data['warranty_days'] ?? 0)),
                'required_inputs_schema' => $schema,
            ]);

            $this->recordAudit($adminId, 'CMS_CATEGORY_CREATED', 'game_category', $category->id, null, $category->toArray(), "Tambah kategori {$category->name} ({$game->name})");

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(int $adminId, int $categoryId, array $data): GameCategory
    {
        return DB::transaction(function () use ($adminId, $categoryId, $data): GameCategory {
            $category = GameCategory::where('id', $categoryId)->lockForUpdate()->firstOrFail();
            $before = $category->toArray();

            if (! empty($data['name'])) {
                $category->name = trim((string) $data['name']);
            }
            if (! empty($data['delivery_method'])) {
                $category->delivery_method = (string) $data['delivery_method'];
            }
            if (isset($data['platform_fee_percent'])) {
                $category->platform_fee_percent = number_format((float) $data['platform_fee_percent'], 2, '.', '');
            }
            if (isset($data['escrow_hold_hours'])) {
                $category->escrow_hold_hours = max(1, (int) $data['escrow_hold_hours']);
            }
            if (isset($data['warranty_days'])) {
                $category->warranty_days = max(0, (int) $data['warranty_days']);
            }
            if (array_key_exists('required_inputs_schema', $data)) {
                $category->required_inputs_schema = $this->sanitizeCheckoutSchema($data['required_inputs_schema']);
            }

            $category->save();

            $this->recordAudit($adminId, 'CMS_CATEGORY_UPDATED', 'game_category', $category->id, $before, $category->toArray(), "Perbarui kategori {$category->name}");

            return $category->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAttribute(int $adminId, array $data): CategoryAttribute
    {
        return DB::transaction(function () use ($adminId, $data): CategoryAttribute {
            $category = GameCategory::findOrFail((int) $data['game_category_id']);
            $name = trim((string) $data['name']);
            $slug = ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name);

            if (CategoryAttribute::where('game_category_id', $category->id)->where('slug', $slug)->exists()) {
                throw new DomainException("Atribut [{$slug}] sudah ada pada kategori {$category->name}.");
            }

            $nextOrder = (int) CategoryAttribute::where('game_category_id', $category->id)->max('sort_order') + 1;

            $attr = CategoryAttribute::create([
                'game_category_id' => $category->id,
                'name' => $name,
                'slug' => $slug,
                'input_type' => (string) $data['input_type'],
                'unit_label' => ! empty($data['unit_label']) ? trim((string) $data['unit_label']) : null,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'is_filterable' => array_key_exists('is_filterable', $data) ? (bool) $data['is_filterable'] : true,
                'sort_order' => min(255, $nextOrder),
            ]);

            // Optional comma-separated initial options for 'select' type
            if ($attr->input_type === 'select' && ! empty($data['options_csv'])) {
                $labels = array_filter(array_map('trim', explode(',', (string) $data['options_csv'])));
                foreach (array_values($labels) as $idx => $label) {
                    $valSlug = Str::slug($label) ?: 'opt-'.($idx + 1);
                    AttributeOption::firstOrCreate(
                        ['category_attribute_id' => $attr->id, 'value_slug' => $valSlug],
                        ['label' => mb_substr($label, 0, 80), 'sort_order' => $idx + 1],
                    );
                }
            }

            $this->recordAudit($adminId, 'CMS_ATTRIBUTE_CREATED', 'category_attribute', $attr->id, null, $attr->load('options')->toArray(), "Tambah atribut {$attr->name}");

            return $attr->load('options');
        });
    }

    public function addAttributeOption(int $adminId, int $attributeId, string $label): AttributeOption
    {
        return DB::transaction(function () use ($adminId, $attributeId, $label): AttributeOption {
            $attr = CategoryAttribute::findOrFail($attributeId);
            if ($attr->input_type !== 'select') {
                throw new DomainException('Opsi hanya dapat ditambahkan ke atribut bertipe select.');
            }

            $trimmed = mb_substr(trim($label), 0, 80);
            $slug = Str::slug($trimmed);
            if ($slug === '' || AttributeOption::where('category_attribute_id', $attr->id)->where('value_slug', $slug)->exists()) {
                throw new DomainException("Opsi [{$trimmed}] sudah ada atau tidak valid.");
            }

            $nextOrder = (int) AttributeOption::where('category_attribute_id', $attr->id)->max('sort_order') + 1;
            $option = AttributeOption::create([
                'category_attribute_id' => $attr->id,
                'label' => $trimmed,
                'value_slug' => $slug,
                'sort_order' => min(255, $nextOrder),
            ]);

            $this->recordAudit($adminId, 'CMS_ATTR_OPTION_ADDED', 'attribute_option', $option->id, null, $option->toArray(), "Tambah opsi {$option->label} pada {$attr->name}");

            return $option;
        });
    }

    public function deleteAttributeOption(int $adminId, int $optionId): void
    {
        DB::transaction(function () use ($adminId, $optionId): void {
            $option = AttributeOption::where('id', $optionId)->lockForUpdate()->firstOrFail();
            $before = $option->toArray();
            $option->delete();

            $this->recordAudit($adminId, 'CMS_ATTR_OPTION_DELETED', 'attribute_option', $optionId, $before, null, "Hapus opsi {$before['label']}");
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createVoucher(int $actorId, string $issuerType, array $data, ?int $sellerId = null): Voucher
    {
        return DB::transaction(function () use ($actorId, $issuerType, $data, $sellerId): Voucher {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9\-_]/', '', trim((string) $data['code'])) ?? '');
            if (strlen($code) < 4) {
                throw new DomainException('Kode voucher minimal 4 karakter alfanumerik.');
            }
            if (Voucher::where('code', $code)->exists()) {
                throw new DomainException("Kode voucher [{$code}] sudah digunakan.");
            }

            $discountType = (string) $data['discount_type'];
            $discountValue = (float) $data['discount_value'];
            if ($discountType === 'percent' && ($discountValue <= 0 || $discountValue > 90)) {
                throw new DomainException('Persentase diskon voucher harus antara 1% hingga 90%.');
            }

            $voucher = Voucher::create([
                'code' => $code,
                'title' => mb_substr(trim((string) $data['title']), 0, 120),
                'issuer_type' => $issuerType,
                'seller_id' => $issuerType === 'seller' ? $sellerId : null,
                'game_id' => ! empty($data['game_id']) ? (int) $data['game_id'] : null,
                'discount_type' => $discountType,
                'discount_value' => number_format($discountValue, 2, '.', ''),
                'max_discount_amount' => isset($data['max_discount_amount']) && $data['max_discount_amount'] !== '' ? number_format((float) $data['max_discount_amount'], 2, '.', '') : null,
                'min_purchase_amount' => number_format(max(0, (float) ($data['min_purchase_amount'] ?? 0)), 2, '.', ''),
                'quota_total' => max(1, (int) $data['quota_total']),
                'quota_used' => 0,
                'max_uses_per_user' => max(1, (int) ($data['max_uses_per_user'] ?? 1)),
                'starts_at' => $data['starts_at'] ?? now(),
                'ends_at' => $data['ends_at'] ?? now()->addDays(14),
                'is_active' => true,
                'created_by' => $actorId,
            ]);

            if ($issuerType === 'platform') {
                $this->recordAudit($actorId, 'CMS_PLATFORM_VOUCHER_CREATED', 'voucher', $voucher->id, null, $voucher->toArray(), "Terbitkan voucher platform {$voucher->code}");
            }

            return $voucher;
        });
    }

    public function toggleVoucher(int $actorId, int $voucherId, bool $isAdmin): Voucher
    {
        return DB::transaction(function () use ($actorId, $voucherId, $isAdmin): Voucher {
            $voucher = Voucher::where('id', $voucherId)->lockForUpdate()->firstOrFail();
            if (! $isAdmin && ($voucher->issuer_type !== 'seller' || (int) $voucher->seller_id !== $actorId)) {
                throw new DomainException('Anda tidak berwenang mengubah voucher milik pihak lain.');
            }

            $before = ['is_active' => $voucher->is_active];
            $voucher->is_active = ! $voucher->is_active;
            $voucher->save();

            if ($isAdmin) {
                $this->recordAudit($actorId, 'CMS_VOUCHER_TOGGLED', 'voucher', $voucher->id, $before, ['is_active' => $voucher->is_active], "Toggle voucher {$voucher->code}");
            }

            return $voucher;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFlashSale(int $adminId, array $data): FlashSale
    {
        return DB::transaction(function () use ($adminId, $data): FlashSale {
            $title = trim((string) $data['title']);
            $slug = Str::slug($title).'-'.strtolower(Str::random(4));

            $sale = FlashSale::create([
                'title' => mb_substr($title, 0, 140),
                'slug' => $slug,
                'banner_subtitle' => ! empty($data['banner_subtitle']) ? mb_substr(trim((string) $data['banner_subtitle']), 0, 200) : null,
                'starts_at' => $data['starts_at'] ?? now(),
                'ends_at' => $data['ends_at'] ?? now()->addHours(12),
                'is_active' => true,
                'created_by' => $adminId,
            ]);

            $this->recordAudit($adminId, 'CMS_FLASH_SALE_CREATED', 'flash_sale', $sale->id, null, $sale->toArray(), "Buat event Flash Sale: {$sale->title}");

            return $sale;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function attachFlashSaleItem(int $adminId, int $flashSaleId, array $data): FlashSaleItem
    {
        return DB::transaction(function () use ($adminId, $flashSaleId, $data): FlashSaleItem {
            $sale = FlashSale::findOrFail($flashSaleId);
            $product = Product::findOrFail((int) $data['product_id']);
            $flashPrice = (float) $data['flash_price'];

            if ($flashPrice <= 0 || $flashPrice >= (float) $product->price) {
                throw new DomainException('Harga Flash Sale harus lebih murah dari harga normal produk.');
            }

            $item = FlashSaleItem::updateOrCreate(
                ['flash_sale_id' => $sale->id, 'product_id' => $product->id],
                [
                    'flash_price' => number_format($flashPrice, 2, '.', ''),
                    'flash_quota' => max(1, (int) $data['flash_quota']),
                    'max_qty_per_user' => max(1, (int) ($data['max_qty_per_user'] ?? 1)),
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                ],
            );

            $this->recordAudit($adminId, 'CMS_FLASH_ITEM_ATTACHED', 'flash_sale_item', $item->id, null, $item->toArray(), "Pasang {$product->sku_code} ke Flash Sale {$sale->title}");

            return $item->load('product');
        });
    }

    /**
     * @param  array{target_type: string, target_id: int, reason_category: string, details: string}  $data
     */
    public function submitReport(int $reporterId, array $data): ModerationReport
    {
        $report = ModerationReport::create([
            'reporter_id' => $reporterId,
            'target_type' => (string) $data['target_type'],
            'target_id' => (int) $data['target_id'],
            'reason_category' => (string) $data['reason_category'],
            'details' => trim((string) $data['details']),
            'status' => 'open',
        ]);

        $this->notificationService->notifyAdmins(
            category: 'moderation',
            title: "Laporan Moderasi Baru ({$report->target_type} #{$report->target_id})",
            body: $report->reasonLabel().' — '.mb_substr($report->details, 0, 120),
            actionUrl: '/admin/reports',
            refType: 'moderation_report',
            refId: (int) $report->id,
        );

        return $report;
    }

    public function resolveReport(int $adminId, int $reportId, string $actionType, string $notes): ModerationReport
    {
        if (! in_array($actionType, ['dismiss', 'takedown_product', 'suspend_seller'], true)) {
            throw new DomainException("Tindakan moderasi tidak valid: [{$actionType}].");
        }

        return DB::transaction(function () use ($adminId, $reportId, $actionType, $notes): ModerationReport {
            $report = ModerationReport::where('id', $reportId)->lockForUpdate()->firstOrFail();
            if ($report->status !== 'open') {
                throw new DomainException("Laporan #{$report->id} sudah ditindaklanjuti sebelumnya.");
            }

            $before = ['report_status' => $report->status];
            $after = [];

            if ($actionType === 'dismiss') {
                $report->status = 'dismissed';
            } else {
                $report->status = 'action_taken';

                if ($actionType === 'takedown_product' && $report->target_type === 'product') {
                    $product = Product::withTrashed()->where('id', $report->target_id)->lockForUpdate()->first();
                    if ($product) {
                        $before['product_is_active'] = $product->is_active;
                        $product->is_active = false;
                        $product->save();
                        $after['product_is_active'] = false;

                        $this->notificationService->notify(
                            userId: (int) $product->seller_id,
                            category: 'moderation',
                            title: "Produk Dinonaktifkan oleh Tim Moderasi",
                            body: "Produk \"{$product->title}\" dinonaktifkan. Catatan: {$notes}",
                            actionUrl: '/seller/products',
                            refType: 'product',
                            refId: (int) $product->id,
                        );
                    }
                } elseif ($actionType === 'suspend_seller') {
                    $sellerId = $report->target_type === 'seller'
                        ? (int) $report->target_id
                        : (int) (Product::withTrashed()->where('id', $report->target_id)->value('seller_id') ?? 0);

                    if ($sellerId > 0) {
                        $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->first();
                        if ($profile) {
                            $before['seller_is_suspended'] = $profile->is_suspended;
                            $profile->is_suspended = true;
                            $profile->is_store_open = false;
                            $profile->suspension_reason = mb_substr($notes, 0, 255);
                            $profile->save();
                            $after['seller_is_suspended'] = true;

                            $this->notificationService->notify(
                                userId: $sellerId,
                                category: 'moderation',
                                title: 'Toko Anda Dibekukan Sementara (Suspended)',
                                body: "Alasan Moderasi: {$notes}",
                                actionUrl: '/seller/dashboard',
                                refType: 'seller_profile',
                                refId: $sellerId,
                            );
                        }
                    }
                }
            }

            $report->resolution_notes = mb_substr(trim($notes), 0, 255);
            $report->reviewed_by = $adminId;
            $report->reviewed_at = now();
            $report->save();

            $after['report_status'] = $report->status;
            $after['action_type'] = $actionType;

            $this->recordAudit(
                adminId: $adminId,
                action: 'MODERATION_'.strtoupper($actionType),
                entityType: 'moderation_report',
                entityId: (int) $report->id,
                before: $before,
                after: $after,
                notes: $report->resolution_notes,
            );

            return $report->fresh(['reporter', 'reviewer']);
        });
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function sanitizeCheckoutSchema(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $field) {
            if (! is_array($field) || empty($field['key'])) {
                continue;
            }
            $key = Str::snake(trim((string) $field['key']));
            if ($key === '') {
                continue;
            }
            $clean[] = [
                'key' => $key,
                'label' => trim((string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $key)))),
                'type' => in_array($field['type'] ?? 'text', ['text', 'number', 'email'], true) ? $field['type'] : 'text',
                'required' => filter_var($field['required'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'placeholder' => trim((string) ($field['placeholder'] ?? '')),
            ];
        }

        return $clean;
    }
}
