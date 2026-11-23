<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, string>|null $evidence_paths
 */
class WarrantyClaim extends Model
{
    /** @var array<string, string> */
    public const REASON_LABELS = [
        'account_reclaimed_hackback' => 'Akun Ditarik Kembali / Hackback Penjual',
        'password_email_changed' => 'Email / Sandi Akun Berubah Tanpa Izin',
        'banned_prior_violation' => 'Akun Terkena Ban Akibat Pelanggaran Sebelum Pembelian',
        'other' => 'Kendala Keamanan Akun Lainnya',
    ];

    /** @var list<string> */
    protected $fillable = [
        'claim_code',
        'order_warranty_id',
        'order_id',
        'buyer_id',
        'seller_id',
        'reason_category',
        'description',
        'evidence_paths',
        'status',
        'clawback_from_deposit',
        'clawback_from_available',
        'recorded_seller_debt',
        'admin_resolution_notes',
        'resolved_by',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evidence_paths' => 'array',
            'clawback_from_deposit' => 'decimal:2',
            'clawback_from_available' => 'decimal:2',
            'recorded_seller_debt' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OrderWarranty, $this> */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(OrderWarranty::class, 'order_warranty_id', 'id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by', 'id');
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason_category];
    }
}
