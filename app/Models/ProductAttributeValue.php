<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeValue extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'category_attribute_id',
        'attribute_option_id',
        'numeric_value',
        'boolean_value',
        'text_value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'numeric_value' => 'integer',
            'boolean_value' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<CategoryAttribute, $this> */
    public function categoryAttribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class);
    }

    /** @return BelongsTo<CategoryAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class, 'category_attribute_id', 'id');
    }

    /** @return BelongsTo<AttributeOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'attribute_option_id', 'id');
    }

    public function formattedValue(): string
    {
        $attr = $this->categoryAttribute;
        if (! $attr) {
            return (string) ($this->text_value ?? $this->numeric_value ?? '');
        }

        return match ($attr->input_type) {
            'select' => $this->option->label ?? '-',
            'number' => $this->numeric_value !== null
                ? number_format($this->numeric_value, 0, ',', '.').($attr->unit_label ? ' '.$attr->unit_label : '')
                : '-',
            'boolean' => $this->boolean_value ? 'Ya' : 'Tidak',
            default => $this->text_value !== null && $this->text_value !== ''
                ? $this->text_value.($attr->unit_label ? ' '.$attr->unit_label : '')
                : '-',
        };
    }
}
