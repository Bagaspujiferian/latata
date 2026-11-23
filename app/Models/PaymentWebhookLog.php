<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $raw_payload
 */
class PaymentWebhookLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'idempotency_key',
        'signature_valid',
        'http_status',
        'raw_payload',
        'processing_notes',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'http_status' => 'integer',
            'raw_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
