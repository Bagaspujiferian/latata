<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOperatingHour extends Model
{
    /** @var array<int, string> */
    public const DAY_NAMES = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'day_of_week',
        'open_time',
        'close_time',
        'is_closed',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    public function dayName(): string
    {
        return self::DAY_NAMES[$this->day_of_week] ?? 'Hari '.$this->day_of_week;
    }

    public function formattedWindow(): string
    {
        if ($this->is_closed) {
            return 'Tutup';
        }

        return substr((string) $this->open_time, 0, 5).' - '.substr((string) $this->close_time, 0, 5).' WIB';
    }
}
