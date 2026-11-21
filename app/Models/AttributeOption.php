<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeOption extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'category_attribute_id',
        'label',
        'value_slug',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function categoryAttribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class);
    }
}
