<?php

namespace App\Services;

use App\Models\CategoryAttribute;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProductCatalogFilterService
{
    public function __construct(
        private readonly FulltextQuerySanitizer $fulltextSanitizer = new FulltextQuerySanitizer,
    ) {}

    /**
     * Build an indexed, N+1-free catalog query using whereExists subqueries on product_attribute_values.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function buildQuery(array $filters): Builder
    {
        $query = Product::query()
            ->with([
                'primaryImage',
                'images',
                'gameCategory.game',
                'sellerProfile.operatingHours',
                'seller',
                'attributeValues.attribute',
                'attributeValues.categoryAttribute',
                'attributeValues.option',
            ])
            ->withCount(['wishlists', 'wishlistedBy'])
            ->where('products.is_active', true);

        if (! empty($filters['seller_id'])) {
            $query->where('products.seller_id', (int) $filters['seller_id']);
        }

        if (! empty($filters['game_slug'])) {
            $gameSlug = (string) $filters['game_slug'];
            $query->whereHas('gameCategory.game', fn (Builder $q) => $q->where('slug', $gameSlug));
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('products.game_category_id', $categoryId);
        }

        $delivery = (string) ($filters['delivery_method'] ?? '');
        if (in_array($delivery, ['instant_vault', 'manual_trade', 'api_h2h'], true)) {
            $query->whereHas('gameCategory', fn (Builder $q) => $q->where('delivery_method', $delivery));
        }

        if (! empty($filters['sla_10m_only'])) {
            $query->whereHas('sellerProfile', fn (Builder $q) => $q->where('is_sla_10m_badge', true));
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '' && is_numeric($filters['min_price'])) {
            $query->where('products.price', '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '' && is_numeric($filters['max_price'])) {
            $query->where('products.price', '<=', (float) $filters['max_price']);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        $booleanQuery = null;

        if ($search !== '') {
            $booleanQuery = $this->fulltextSanitizer->buildBooleanQuery($search);
            $escapedLike = $this->fulltextSanitizer->escapeLike($search);

            if ($booleanQuery !== null && DB::connection()->getDriverName() === 'mysql') {
                $query->where(function (Builder $q) use ($booleanQuery, $escapedLike): void {
                    $q->whereRaw('MATCH(products.title, products.description) AGAINST(? IN BOOLEAN MODE)', [$booleanQuery]);

                    if ($escapedLike !== '') {
                        $q->orWhere('products.sku_code', 'like', "%{$escapedLike}%")
                            ->orWhereHas('sellerProfile', fn (Builder $sq) => $sq->where('store_name', 'like', "%{$escapedLike}%"))
                            ->orWhereHas('gameCategory.game', fn (Builder $gq) => $gq->where('name', 'like', "%{$escapedLike}%"));
                    }
                });
            } elseif ($escapedLike !== '') {
                $query->where(function (Builder $q) use ($escapedLike): void {
                    $q->where('products.title', 'like', "%{$escapedLike}%")
                        ->orWhere('products.description', 'like', "%{$escapedLike}%")
                        ->orWhere('products.sku_code', 'like', "%{$escapedLike}%")
                        ->orWhereHas('sellerProfile', fn (Builder $sq) => $sq->where('store_name', 'like', "%{$escapedLike}%"))
                        ->orWhereHas('gameCategory.game', fn (Builder $gq) => $gq->where('name', 'like', "%{$escapedLike}%"));
                });
            } else {
                // Search consisted purely of stripped operator characters -> return empty result set safely
                $query->whereRaw('1 = 0');
            }
        }

        // Dynamic Indexed Attribute Filters (supports BOTH attrs[attribute_id] AND legacy attr[slug])
        $attrsById = is_array($filters['attrs'] ?? null) ? $filters['attrs'] : [];
        $attrsBySlug = is_array($filters['attr'] ?? null) ? $filters['attr'] : [];

        if (! empty($attrsById) || ! empty($attrsBySlug)) {
            $attrDefsQuery = CategoryAttribute::with('options')->where('is_filterable', true);
            if ($categoryId > 0) {
                $attrDefsQuery->where('game_category_id', $categoryId);
            } else {
                $ids = array_filter(array_map('intval', array_keys($attrsById)));
                $slugs = array_filter(array_map('strval', array_keys($attrsBySlug)));
                $attrDefsQuery->where(function (Builder $q) use ($ids, $slugs): void {
                    if (! empty($ids)) {
                        $q->orWhereIn('id', $ids);
                    }
                    if (! empty($slugs)) {
                        $q->orWhereIn('slug', $slugs);
                    }
                });
            }

            $definitions = $attrDefsQuery->orderBy('sort_order')->get();

            foreach ($definitions as $attr) {
                $rawVal = $attrsById[$attr->id]
                    ?? $attrsById[(string) $attr->id]
                    ?? $attrsBySlug[$attr->slug]
                    ?? null;

                if ($rawVal === null || $rawVal === '') {
                    continue;
                }

                if ($attr->input_type === 'select') {
                    $optionId = is_numeric($rawVal)
                        ? (int) $rawVal
                        : $attr->options->firstWhere('value_slug', (string) $rawVal)?->id;

                    if ($optionId) {
                        $query->whereExists(function ($sub) use ($attr, $optionId): void {
                            $sub->select(DB::raw(1))
                                ->from('product_attribute_values')
                                ->whereColumn('product_attribute_values.product_id', 'products.id')
                                ->where('product_attribute_values.category_attribute_id', $attr->id)
                                ->where('product_attribute_values.attribute_option_id', $optionId);
                        });
                    }
                } elseif ($attr->input_type === 'number' && is_numeric($rawVal)) {
                    $minNumeric = (int) $rawVal;
                    $query->whereExists(function ($sub) use ($attr, $minNumeric): void {
                        $sub->select(DB::raw(1))
                            ->from('product_attribute_values')
                            ->whereColumn('product_attribute_values.product_id', 'products.id')
                            ->where('product_attribute_values.category_attribute_id', $attr->id)
                            ->where('product_attribute_values.numeric_value', '>=', $minNumeric);
                    });
                } elseif ($attr->input_type === 'boolean') {
                    $boolVal = filter_var($rawVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($boolVal !== null) {
                        $query->whereExists(function ($sub) use ($attr, $boolVal): void {
                            $sub->select(DB::raw(1))
                                ->from('product_attribute_values')
                                ->whereColumn('product_attribute_values.product_id', 'products.id')
                                ->where('product_attribute_values.category_attribute_id', $attr->id)
                                ->where('product_attribute_values.boolean_value', $boolVal ? 1 : 0);
                        });
                    }
                } else {
                    $textSearch = trim((string) $rawVal);
                    if ($textSearch !== '') {
                        $query->whereExists(function ($sub) use ($attr, $textSearch): void {
                            $sub->select(DB::raw(1))
                                ->from('product_attribute_values')
                                ->whereColumn('product_attribute_values.product_id', 'products.id')
                                ->where('product_attribute_values.category_attribute_id', $attr->id)
                                ->where('product_attribute_values.text_value', 'like', "%{$textSearch}%");
                        });
                    }
                }
            }
        }

        $sort = (string) ($filters['sort'] ?? 'recommended');
        if ($sort === 'recommended' && $booleanQuery !== null && DB::connection()->getDriverName() === 'mysql') {
            $query->orderByRaw('MATCH(products.title, products.description) AGAINST(? IN BOOLEAN MODE) DESC', [$booleanQuery])
                ->orderByDesc('products.is_featured')
                ->orderByDesc('products.last_bumped_at')
                ->orderByDesc('products.sold_count');
        } else {
            match ($sort) {
                'price_asc' => $query->orderBy('products.price', 'asc'),
                'price_desc' => $query->orderBy('products.price', 'desc'),
                'bestseller' => $query->orderByDesc('products.sold_count'),
                'fastest' => $query->orderBy('products.sla_target_minutes', 'asc'),
                'newest' => $query->orderByDesc('products.id'),
                default => $query->orderByDesc('products.is_featured')
                    ->orderByDesc('products.last_bumped_at')
                    ->orderByDesc('products.sold_count'),
            };
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Product>
     */
    public function getProducts(array $filters): Collection
    {
        return $this->buildQuery($filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateProducts(array $filters, int $perPage = 16): LengthAwarePaginator
    {
        return $this->buildQuery($filters)->paginate($perPage)->withQueryString();
    }
}
