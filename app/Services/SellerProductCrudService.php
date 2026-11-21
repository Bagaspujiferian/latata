<?php

namespace App\Services;

use App\Models\AttributeOption;
use App\Models\CategoryAttribute;
use App\Models\GameCategory;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductVault;
use App\Models\SellerProfile;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SellerProductCrudService
{
    private const MAX_IMAGES_PER_PRODUCT = 6;

    public function __construct(
        private readonly VaultEncryptionService $vaultService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $uploadedImages
     */
    public function createProduct(int $sellerId, array $data, array $uploadedImages = []): Product
    {
        if (count($uploadedImages) > self::MAX_IMAGES_PER_PRODUCT) {
            throw new DomainException('Maksimal 6 foto yang dapat diunggah per produk.');
        }

        return DB::transaction(function () use ($sellerId, $data, $uploadedImages): Product {
            $profile = SellerProfile::where('user_id', $sellerId)->first();
            if (! $profile) {
                throw new DomainException('Hanya akun dengan profil Seller aktif yang dapat membuat produk.');
            }

            $category = GameCategory::with(['game', 'attributes.options'])->find((int) ($data['game_category_id'] ?? 0));
            if (! $category) {
                throw new DomainException('Kategori game tidak ditemukan.');
            }

            $title = trim((string) $data['title']);
            $baseSlug = Str::slug($title) ?: 'produk-digital';
            $slug = $baseSlug.'-'.strtolower(Str::random(5));
            while (Product::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.strtolower(Str::random(6));
            }

            $skuCode = strtoupper(Str::substr(Str::slug($category->game->name, ''), 0, 4)).'-'.strtoupper(Str::random(6));
            while (Product::withTrashed()->where('sku_code', $skuCode)->exists()) {
                $skuCode = 'SKU-'.strtoupper(Str::random(7));
            }

            // VAULT-DRIVEN STOCK LAW: instant_vault ALWAYS starts at 0 until AES-256 vault lines are ingested
            $stock = $category->delivery_method === 'instant_vault'
                ? 0
                : max(0, (int) ($data['stock'] ?? 0));

            $minOrder = max(1, (int) ($data['min_order'] ?? 1));
            $maxOrder = max($minOrder, (int) ($data['max_order'] ?? 10));

            $product = Product::create([
                'seller_id' => $sellerId,
                'game_category_id' => $category->id,
                'sku_code' => $skuCode,
                'title' => $title,
                'slug' => $slug,
                'description' => trim((string) $data['description']),
                'price' => number_format((float) $data['price'], 2, '.', ''),
                'stock' => $stock,
                'min_order' => $minOrder,
                'max_order' => $maxOrder,
                'sla_target_minutes' => max(1, (int) ($data['sla_target_minutes'] ?? 10)),
                'h2h_provider_sku' => $category->delivery_method === 'api_h2h' && ! empty($data['h2h_provider_sku'])
                    ? trim((string) $data['h2h_provider_sku'])
                    : null,
                'sold_count' => 0,
                'rating_avg' => '5.00',
                'is_featured' => false,
                'last_bumped_at' => now(),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $attributesInput = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            $this->syncAttributesWithinTransaction($product, $category->attributes, $attributesInput);

            $primaryUploadIndex = max(0, (int) ($data['primary_upload_index'] ?? 0));
            if ($primaryUploadIndex >= count($uploadedImages)) {
                $primaryUploadIndex = 0;
            }

            foreach (array_values($uploadedImages) as $idx => $file) {
                $storedPath = Storage::disk('public')->putFile("products/{$product->id}", $file);
                if (! is_string($storedPath)) {
                    throw new DomainException('Gagal menyimpan foto produk.');
                }

                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $storedPath,
                    'alt_text' => mb_substr($product->title, 0, 150),
                    'sort_order' => $idx,
                    'is_primary' => $idx === $primaryUploadIndex,
                ]);
            }

            return $product->fresh(['gameCategory.game', 'images', 'attributeValues.attribute', 'attributeValues.option']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $newImages
     * @param  list<int>  $deleteImageIds
     */
    public function updateProduct(
        int $sellerId,
        int $productId,
        array $data,
        array $newImages = [],
        array $deleteImageIds = [],
        ?int $primaryImageId = null,
    ): Product {
        return DB::transaction(function () use ($sellerId, $productId, $data, $newImages, $deleteImageIds, $primaryImageId): Product {
            $product = Product::with('gameCategory')->where('id', $productId)->lockForUpdate()->first();
            if (! $product) {
                throw new DomainException('Produk tidak ditemukan.');
            }

            if ((int) $product->seller_id !== $sellerId) {
                throw new AuthorizationException('Anda tidak berwenang mengubah produk milik toko lain.');
            }

            $targetCategoryId = isset($data['game_category_id'])
                ? (int) $data['game_category_id']
                : (int) $product->game_category_id;

            if ($targetCategoryId !== (int) $product->game_category_id) {
                $hasActiveVaults = ProductVault::where('product_id', $product->id)
                    ->whereIn('status', ['available', 'reserved'])
                    ->exists();
                if ($hasActiveVaults) {
                    throw new DomainException('Kategori tidak dapat diubah selama masih terdapat stok brankas AES-256 (available/reserved) pada produk ini.');
                }
            }

            $category = GameCategory::with('attributes.options')->find($targetCategoryId);
            if (! $category) {
                throw new DomainException('Kategori game tidak ditemukan.');
            }

            $product->game_category_id = $category->id;
            if (isset($data['title'])) {
                $product->title = trim((string) $data['title']);
            }
            if (isset($data['description'])) {
                $product->description = trim((string) $data['description']);
            }
            if (isset($data['price'])) {
                $product->price = number_format((float) $data['price'], 2, '.', '');
            }
            if (isset($data['min_order'])) {
                $product->min_order = max(1, (int) $data['min_order']);
            }
            if (isset($data['max_order'])) {
                $product->max_order = max($product->min_order, (int) $data['max_order']);
            }
            if (isset($data['sla_target_minutes'])) {
                $product->sla_target_minutes = max(1, (int) $data['sla_target_minutes']);
            }
            if (array_key_exists('h2h_provider_sku', $data)) {
                $product->h2h_provider_sku = $category->delivery_method === 'api_h2h' && ! empty($data['h2h_provider_sku'])
                    ? trim((string) $data['h2h_provider_sku'])
                    : null;
            }
            if (array_key_exists('is_active', $data)) {
                $product->is_active = (bool) $data['is_active'];
            }

            // VAULT-DRIVEN STOCK INTEGRITY LAW: ignore manual stock edits for instant_vault
            if ($category->delivery_method !== 'instant_vault' && array_key_exists('stock', $data) && $data['stock'] !== null) {
                $product->stock = max(0, (int) $data['stock']);
            }

            $product->save();

            if ($category->delivery_method === 'instant_vault') {
                $this->vaultService->syncVaultStockWithinLock($product->id);
            }

            if (array_key_exists('attributes', $data) && is_array($data['attributes'])) {
                $this->syncAttributesWithinTransaction($product, $category->attributes, $data['attributes']);
            }

            // Delete requested images safely
            $cleanDeleteIds = array_values(array_unique(array_filter(array_map('intval', $deleteImageIds))));
            if (! empty($cleanDeleteIds)) {
                $toDelete = ProductImage::where('product_id', $product->id)
                    ->whereIn('id', $cleanDeleteIds)
                    ->get();

                foreach ($toDelete as $img) {
                    if (! str_starts_with($img->image_path, '/') && ! str_starts_with($img->image_path, 'http')) {
                        Storage::disk('public')->delete($img->image_path);
                    }
                    $img->delete();
                }
            }

            // Append newly uploaded images enforcing max 6 total
            $remainingCount = ProductImage::where('product_id', $product->id)->count();
            if ($remainingCount + count($newImages) > self::MAX_IMAGES_PER_PRODUCT) {
                throw new DomainException('Maksimal total 6 foto diperbolehkan per produk. Hapus foto lama terlebih dahulu.');
            }

            $nextSort = (int) ProductImage::where('product_id', $product->id)->max('sort_order') + 1;
            foreach ($newImages as $file) {
                $storedPath = Storage::disk('public')->putFile("products/{$product->id}", $file);
                if (! is_string($storedPath)) {
                    throw new DomainException('Gagal menyimpan foto produk baru.');
                }

                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $storedPath,
                    'alt_text' => mb_substr($product->title, 0, 150),
                    'sort_order' => min(255, $nextSort++),
                    'is_primary' => false,
                ]);
            }

            // Guarantee strictly 1 primary image when any images exist
            $allImages = ProductImage::where('product_id', $product->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($allImages->isNotEmpty()) {
                $chosenPrimary = $primaryImageId !== null
                    ? $allImages->firstWhere('id', $primaryImageId)
                    : $allImages->firstWhere('is_primary', true);

                if (! $chosenPrimary) {
                    $chosenPrimary = $allImages->first();
                }

                ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
                $chosenPrimary->is_primary = true;
                $chosenPrimary->save();
            }

            return $product->fresh(['gameCategory.game', 'images', 'attributeValues.attribute', 'attributeValues.option']);
        });
    }

    public function archiveProduct(int $sellerId, int $productId): void
    {
        DB::transaction(function () use ($sellerId, $productId): void {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product) {
                throw new DomainException('Produk tidak ditemukan.');
            }

            if ((int) $product->seller_id !== $sellerId) {
                throw new AuthorizationException('Anda tidak berwenang mengarsipkan produk milik toko lain.');
            }

            $hasActiveOrders = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.product_id', $product->id)
                ->whereIn('orders.status', ['WAITING_PAYMENT', 'PROCESSING'])
                ->exists();

            if ($hasActiveOrders) {
                throw new DomainException('Produk tidak dapat diarsipkan karena masih ada pesanan aktif (WAITING_PAYMENT / PROCESSING).');
            }

            $hasReservedVaults = ProductVault::where('product_id', $product->id)
                ->where('status', 'reserved')
                ->exists();

            if ($hasReservedVaults) {
                throw new DomainException('Produk tidak dapat diarsipkan saat ada item brankas yang sedang direservasi checkout.');
            }

            $product->is_active = false;
            $product->save();
            $product->delete();
        });
    }

    public function restoreProduct(int $sellerId, int $productId): Product
    {
        return DB::transaction(function () use ($sellerId, $productId): Product {
            $product = Product::withTrashed()->with('gameCategory')->where('id', $productId)->lockForUpdate()->first();
            if (! $product) {
                throw new DomainException('Produk arsip tidak ditemukan.');
            }

            if ((int) $product->seller_id !== $sellerId) {
                throw new AuthorizationException('Anda tidak berwenang memulihkan produk milik toko lain.');
            }

            $product->restore();
            $product->is_active = true;
            $product->save();

            if ($product->gameCategory?->delivery_method === 'instant_vault') {
                $this->vaultService->syncVaultStockWithinLock($product->id);
            }

            return $product->fresh();
        });
    }

    public function toggleProductActive(int $sellerId, int $productId): Product
    {
        return DB::transaction(function () use ($sellerId, $productId): Product {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product) {
                throw new DomainException('Produk tidak ditemukan.');
            }

            if ((int) $product->seller_id !== $sellerId) {
                throw new AuthorizationException('Anda tidak berwenang mengubah status produk milik toko lain.');
            }

            $product->is_active = ! $product->is_active;
            $product->save();

            return $product->fresh();
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, CategoryAttribute>  $definitions
     * @param  array<int|string, mixed>  $attributeInputs
     */
    private function syncAttributesWithinTransaction(Product $product, $definitions, array $attributeInputs): void
    {
        $validDefinitionIds = $definitions->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Remove stale attribute values belonging to another category
        ProductAttributeValue::where('product_id', $product->id)
            ->when(! empty($validDefinitionIds), fn ($q) => $q->whereNotIn('category_attribute_id', $validDefinitionIds))
            ->when(empty($validDefinitionIds), fn ($q) => $q)
            ->delete();

        foreach ($definitions as $attr) {
            $rawVal = $attributeInputs[$attr->id]
                ?? $attributeInputs[(string) $attr->id]
                ?? $attributeInputs[$attr->slug]
                ?? null;

            $isEmpty = $rawVal === null || $rawVal === '';

            if ($attr->is_required && $isEmpty && $attr->input_type !== 'boolean') {
                throw new DomainException("Atribut [{$attr->name}] wajib diisi untuk kategori ini.");
            }

            if ($isEmpty && $attr->input_type !== 'boolean') {
                ProductAttributeValue::where('product_id', $product->id)
                    ->where('category_attribute_id', $attr->id)
                    ->delete();

                continue;
            }

            $payload = [
                'attribute_option_id' => null,
                'numeric_value' => null,
                'boolean_value' => null,
                'text_value' => null,
            ];

            if ($attr->input_type === 'select') {
                $option = is_numeric($rawVal)
                    ? AttributeOption::where('category_attribute_id', $attr->id)->where('id', (int) $rawVal)->first()
                    : AttributeOption::where('category_attribute_id', $attr->id)->where('value_slug', (string) $rawVal)->first();

                if (! $option) {
                    throw new DomainException("Opsi pilihan untuk atribut [{$attr->name}] tidak valid.");
                }

                $payload['attribute_option_id'] = $option->id;
            } elseif ($attr->input_type === 'number') {
                if (! is_numeric($rawVal)) {
                    throw new DomainException("Atribut [{$attr->name}] harus berupa angka.");
                }
                $payload['numeric_value'] = (int) $rawVal;
            } elseif ($attr->input_type === 'boolean') {
                $payload['boolean_value'] = filter_var($rawVal, FILTER_VALIDATE_BOOLEAN);
            } else {
                $payload['text_value'] = mb_substr(trim((string) $rawVal), 0, 150);
            }

            ProductAttributeValue::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'category_attribute_id' => $attr->id,
                ],
                $payload,
            );
        }
    }
}
