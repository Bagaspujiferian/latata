<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<array<string, mixed>>|null $required_inputs_schema
 */
class GameCategory extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'game_id',
        'name',
        'slug',
        'delivery_method',
        'platform_fee_percent',
        'escrow_hold_hours',
        'warranty_days',
        'required_inputs_schema',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform_fee_percent' => 'decimal:2',
            'escrow_hold_hours' => 'integer',
            'warranty_days' => 'integer',
            'required_inputs_schema' => 'array',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<CategoryAttribute, $this> */
    public function attributes(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class)->orderBy('sort_order')->orderBy('id');
    }
}
