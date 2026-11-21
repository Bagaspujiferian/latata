<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerKycVerification extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'active_user_lock',
        'proposed_store_name',
        'proposed_store_slug',
        'proposed_store_tagline',
        'full_legal_name',
        'nik_encrypted',
        'nik_last4',
        'nik_blind_hash',
        'active_nik_lock_hash',
        'id_card_private_path',
        'selfie_private_path',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'nik_encrypted',
        'nik_blind_hash',
        'active_nik_lock_hash',
        'id_card_private_path',
        'selfie_private_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active_user_lock' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    public function maskedNik(): string
    {
        return '************'.$this->nik_last4;
    }
}
