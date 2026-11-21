<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationReport extends Model
{
    /** @var array<string, string> */
    public const REASON_LABELS = [
        'fraud_scam' => 'Indikasi Penipuan / Scam',
        'fake_listing' => 'Listing Palsu / Tidak Sesuai Deskripsi',
        'illegal_account' => 'Akun Ilegal / Hasil Hack / Carding',
        'off_platform_bypass' => 'Ajakan Transaksi di Luar NexaTrade',
        'abusive_content' => 'Konten Kasar / Melanggar Ketentuan',
    ];

    /** @var list<string> */
    protected $fillable = [
        'reporter_id',
        'target_type',
        'target_id',
        'reason_category',
        'details',
        'status',
        'resolution_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id', 'id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason_category] ?? $this->reason_category;
    }
}
