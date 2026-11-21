<?php

namespace App\Services;

use App\Models\AttributeOption;
use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductMerchandisingService
{
    public function __construct(
        private readonly VaultEncryptionService $vaultService,
    ) {}

    public function addProductImage(
        int $productId,
        int $sellerId,
        string $imagePath,
        ?string $altText = null,
        bool $isPrimary = false,
    ): ProductImage {
        return DB::transaction(function () use ($productId, $sellerId, $imagePath, $altText, $isPrimary): ProductImage {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product || (int) $product->seller_id !== $sellerId) {
                throw new DomainException('Produk tidak ditemukan atau bukan milik toko Anda.');
            }

            $existingCount = ProductImage::where('product_id', $productId)->count();
            $makePrimary = $isPrimary || $existingCount === 0;

            if ($makePrimary) {
                ProductImage::where('product_id', $productId)->update(['is_primary' => false]);
            }

            $nextSort = $makePrimary
                ? 0
                : ((int) ProductImage::where('product_id', $productId)->max('sort_order') + 1);

            return ProductImage::create([
                'product_id' => $productId,
                'image_path' => $imagePath,
                'alt_text' => $altText ? mb_substr(trim($altText), 0, 150) : $product->title,
                'sort_order' => min(255, $nextSort),
                'is_primary' => $makePrimary,
            ]);
        });
    }

    public function setPrimaryProductImage(int $productId, int $imageId, int $sellerId): ProductImage
    {
        return DB::transaction(function () use ($productId, $imageId, $sellerId): ProductImage {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product || (int) $product->seller_id !== $sellerId) {
                throw new DomainException('Produk tidak ditemukan atau bukan milik toko Anda.');
            }

            $target = ProductImage::where('product_id', $productId)->where('id', $imageId)->first();
            if (! $target) {
                throw new DomainException('Foto galeri tidak ditemukan pada produk ini.');
            }

            ProductImage::where('product_id', $productId)->update(['is_primary' => false]);

            $target->is_primary = true;
            $target->sort_order = 0;
            $target->save();

            return $target;
        });
    }

    public function deleteProductImage(int $productId, int $imageId, int $sellerId): void
    {
        DB::transaction(function () use ($productId, $imageId, $sellerId): void {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product || (int) $product->seller_id !== $sellerId) {
                throw new DomainException('Produk tidak ditemukan atau bukan milik toko Anda.');
            }

            $target = ProductImage::where('product_id', $productId)->where('id', $imageId)->first();
            if (! $target) {
                throw new DomainException('Foto galeri tidak ditemukan.');
            }

            $wasPrimary = $target->is_primary;
            $target->delete();

            if ($wasPrimary) {
                $next = ProductImage::where('product_id', $productId)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if ($next) {
                    $next->is_primary = true;
                    $next->save();
                }
            }
        });
    }

    /**
     * Save typed attribute values keyed by category_attribute_id or attribute slug.
     *
     * @param  array<int|string, mixed>  $attributeInputs
     */
    public function syncProductAttributes(int $productId, int $sellerId, array $attributeInputs): void
    {
        DB::transaction(function () use ($productId, $sellerId, $attributeInputs): void {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product || (int) $product->seller_id !== $sellerId) {
                throw new DomainException('Produk tidak ditemukan atau bukan milik toko Anda.');
            }

            $definitions = CategoryAttribute::with('options')
                ->where('game_category_id', $product->game_category_id)
                ->orderBy('sort_order')
                ->get();

            foreach ($definitions as $attr) {
                $rawVal = $attributeInputs[$attr->id]
                    ?? $attributeInputs[(string) $attr->id]
                    ?? $attributeInputs[$attr->slug]
                    ?? null;

                $isEmpty = $rawVal === null || $rawVal === '';

                if ($attr->is_required && $isEmpty && $attr->input_type !== 'boolean') {
                    throw new DomainException("Atribut [{$attr->name}] wajib diisi untuk kategori produk ini.");
                }

                if ($isEmpty && $attr->input_type !== 'boolean') {
                    ProductAttributeValue::where('product_id', $productId)
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
                        throw new DomainException("Pilihan untuk atribut [{$attr->name}] tidak valid.");
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
                        'product_id' => $productId,
                        'category_attribute_id' => $attr->id,
                    ],
                    $payload,
                );
            }
        });
    }

    public function softDeleteProduct(int $productId, int $sellerId): void
    {
        DB::transaction(function () use ($productId, $sellerId): void {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (! $product || (int) $product->seller_id !== $sellerId) {
                throw new DomainException('Produk tidak ditemukan atau bukan milik toko Anda.');
            }

            $product->is_active = false;
            $product->save();
            $product->delete();
        });
    }

    public function restoreProduct(int $productId, int $sellerId): Product
    {
        return DB::transaction(function () use ($productId, $sellerId): Product {
            $product = Product::withTrashed()->with('gameCategory')->where('id', $productId)->lockForUpdate()->first();
            if (! $product || (int) $product->seller_id !== $sellerId) {
                throw new DomainException('Produk arsip tidak ditemukan atau bukan milik toko Anda.');
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
}
