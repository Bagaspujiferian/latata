<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryAttribute extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'game_category_id',
        'name',
        'slug',
        'input_type',
        'unit_label',
        'is_required',
        'is_filterable',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function gameCategory(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
