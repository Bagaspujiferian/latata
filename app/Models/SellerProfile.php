<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SellerProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'store_name',
        'slug',
        'store_tagline',
        'store_banner_path',
        'store_bio',
        'store_level',
        'total_completed_orders',
        'total_cancelled_orders',
        'avg_delivery_minutes',
        'is_sla_10m_badge',
        'is_store_open',
        'auto_schedule_enabled',
        'ad_credits',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'store_level' => 'integer',
            'total_completed_orders' => 'integer',
            'total_cancelled_orders' => 'integer',
            'avg_delivery_minutes' => 'decimal:2',
            'is_sla_10m_badge' => 'boolean',
            'is_store_open' => 'boolean',
            'auto_schedule_enabled' => 'boolean',
            'ad_credits' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id', 'user_id');
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(StoreOperatingHour::class, 'seller_id', 'user_id')
            ->orderBy('day_of_week');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_followers', 'seller_id', 'user_id')
            ->withPivot('created_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrderReview::class, 'seller_id', 'user_id');
    }

    public function bannerPublicUrl(): ?string
    {
        if (empty($this->store_banner_path)) {
            return null;
        }

        if (str_starts_with($this->store_banner_path, 'http://') || str_starts_with($this->store_banner_path, 'https://') || str_starts_with($this->store_banner_path, '/')) {
            return $this->store_banner_path;
        }

        return '/storage/'.ltrim($this->store_banner_path, '/');
    }

    /**
     * Evaluate effective store open status in Asia/Jakarta timezone combining manual toggle + optional 7-day auto schedule.
     */
    public function isCurrentlyOpen(?Carbon $now = null): bool
    {
        if (! $this->is_store_open) {
            return false;
        }

        if (! $this->auto_schedule_enabled) {
            return true;
        }

        $jakartaNow = ($now ?? now())->copy()->timezone('Asia/Jakarta');
        $dayOfWeek = (int) $jakartaNow->dayOfWeek;

        $hours = $this->relationLoaded('operatingHours')
            ? $this->operatingHours->firstWhere('day_of_week', $dayOfWeek)
            : $this->operatingHours()->where('day_of_week', $dayOfWeek)->first();

        if (! $hours) {
            return true;
        }

        if ($hours->is_closed) {
            return false;
        }

        $currentTime = $jakartaNow->format('H:i:s');
        $openTime = (string) $hours->open_time;
        $closeTime = (string) $hours->close_time;

        if ($openTime <= $closeTime) {
            return $currentTime >= $openTime && $currentTime <= $closeTime;
        }

        // Overnight schedule (e.g. 20:00:00 -> 04:00:00)
        return $currentTime >= $openTime || $currentTime <= $closeTime;
    }

    public function isOpenForManualTradeNow(?Carbon $at = null): bool
    {
        return $this->isCurrentlyOpen($at);
    }
}
