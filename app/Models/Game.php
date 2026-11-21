<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Game extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'publisher',
        'thumbnail_url',
        'requires_nickname_check',
        'nickname_provider_code',
        'is_popular',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requires_nickname_check' => 'boolean',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(GameCategory::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, GameCategory::class);
    }
}
