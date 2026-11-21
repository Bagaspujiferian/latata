<?php

namespace App\Services;

use App\Models\GameCategory;
use App\Models\Product;
use App\Models\SellerProfile;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerCatalogService
{
    public function __construct(
        private readonly VaultEncryptionService $vaultService,
    ) {}

    public function toggleStoreOpen(int $sellerId): SellerProfile
    {
        return DB::transaction(function () use ($sellerId): SellerProfile {
            $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->firstOrFail();
            $profile->is_store_open = ! $profile->is_store_open;
            $profile->save();

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createProduct(int $sellerId, array $data): Product
    {
        return DB::transaction(function () use ($sellerId, $data): Product {
            $category = GameCategory::findOrFail((int) $data['game_category_id']);
            $title = trim((string) $data['title']);
            $slug = Str::slug($title).'-'.strtolower(Str::random(5));
            $sku = 'SKU-'.strtoupper(Str::random(8));

            // Law #5: instant_vault stock is strictly vault-driven (starts at 0 until vault codes are ingested).
            $initialStock = $category->delivery_method === 'instant_vault'
                ? 0
                : max(0, (int) ($data['stock'] ?? 0));

            return Product::create([
                'seller_id' => $sellerId,
                'game_category_id' => $category->id,
                'sku_code' => $sku,
                'title' => $title,
                'slug' => $slug,
                'description' => trim((string) $data['description']),
                'price' => number_format((float) $data['price'], 2, '.', ''),
                'stock' => $initialStock,
                'min_order' => max(1, (int) ($data['min_order'] ?? 1)),
                'max_order' => max(1, (int) ($data['max_order'] ?? 10)),
                'sla_target_minutes' => max(1, (int) ($data['sla_target_minutes'] ?? 10)),
                'h2h_provider_sku' => $category->delivery_method === 'api_h2h'
                    ? trim((string) ($data['h2h_provider_sku'] ?? '')) ?: null
                    : null,
                'is_featured' => false,
                'last_bumped_at' => now(),
                'is_active' => true,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(int $productId, int $sellerId, array $data): Product
    {
        return DB::transaction(function () use ($productId, $sellerId, $data): Product {
            $productRow = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if (! $productRow || (int) $productRow->seller_id !== $sellerId) {
                throw new DomainException('Produk tidak ditemukan atau bukan milik toko Anda.');
            }

            $category = GameCategory::findOrFail((int) $productRow->game_category_id);

            $update = [
                'title' => trim((string) ($data['title'] ?? $productRow->title)),
                'description' => trim((string) ($data['description'] ?? $productRow->description)),
                'price' => number_format((float) ($data['price'] ?? $productRow->price), 2, '.', ''),
                'sla_target_minutes' => max(1, (int) ($data['sla_target_minutes'] ?? $productRow->sla_target_minutes)),
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : (bool) $productRow->is_active,
                'updated_at' => now(),
            ];

            // Law #5: manual stock edits are strictly forbidden for instant_vault products!
            if ($category->delivery_method !== 'instant_vault' && isset($data['stock'])) {
                $update['stock'] = max(0, (int) $data['stock']);
            }

            DB::table('products')->where('id', $productId)->update($update);

            if ($category->delivery_method === 'instant_vault') {
                $this->vaultService->syncVaultStockWithinLock($productId);
            }

            return Product::findOrFail($productId);
        });
    }

    /**
     * Sundul Produk: atomically consumes 1 ad_credit from seller_profiles and updates last_bumped_at.
     *
     * @return array{product: Product, remaining_credits: int}
     */
    public function bumpProduct(int $productId, int $sellerId): array
    {
        return DB::transaction(function () use ($productId, $sellerId): array {
            $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->firstOrFail();
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            if ((int) $product->seller_id !== $sellerId) {
                throw new DomainException('Anda hanya dapat menyundul produk milik toko sendiri.');
            }

            if ($profile->ad_credits < 1) {
                throw new DomainException('Kredit Sundul (ad_credits) habis. Selesaikan pesanan atau hubungi admin untuk isi ulang.');
            }

            $profile->ad_credits -= 1;
            $profile->save();

            $product->last_bumped_at = now();
            $product->save();

            return [
                'product' => $product,
                'remaining_credits' => $profile->ad_credits,
            ];
        });
    }
}
