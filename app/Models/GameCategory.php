<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameCategory extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'game_id',
        'name',
        'slug',
        'delivery_method',
        'platform_fee_percent',
        'required_inputs_schema',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform_fee_percent' => 'decimal:2',
            'required_inputs_schema' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class)->orderBy('sort_order')->orderBy('id');
    }
}
