<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'game_category_id',
        'sku_code',
        'title',
        'slug',
        'description',
        'price',
        'stock',
        'min_order',
        'max_order',
        'sla_target_minutes',
        'h2h_provider_sku',
        'sold_count',
        'rating_avg',
        'is_featured',
        'featured_until',
        'last_bumped_at',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'min_order' => 'integer',
            'max_order' => 'integer',
            'sla_target_minutes' => 'integer',
            'sold_count' => 'integer',
            'rating_avg' => 'decimal:2',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'last_bumped_at' => 'datetime',
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function boosts(): HasMany
    {
        return $this->hasMany(ProductBoost::class)->orderByDesc('id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    public function gameCategory(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(ProductVault::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrderReview::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function wishlistedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlists', 'product_id', 'user_id')
            ->withPivot('created_at');
    }

    public function wishlists(): BelongsToMany
    {
        return $this->wishlistedBy();
    }

    public function primaryThumbnailUrl(): string
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            return $this->images->first()->publicUrl();
        }

        if ($this->relationLoaded('primaryImage') && $this->primaryImage) {
            return $this->primaryImage->publicUrl();
        }

        return (string) ($this->gameCategory?->game?->thumbnail_url ?? '/images/games/steam-wallet.svg');
    }

    public function deliveryMethodLabel(): string
    {
        return match ($this->gameCategory?->delivery_method) {
            'instant_vault' => 'Brankas Instan AES-256',
            'api_h2h' => 'Top Up Otomatis H2H',
            'manual_trade' => 'Trade Manual + SLA',
            default => 'Digital Fulfillment',
        };
    }
}
