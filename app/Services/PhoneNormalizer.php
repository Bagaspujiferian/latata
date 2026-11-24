<?php

namespace App\Services;

use DomainException;

class PhoneNormalizer
{
    /**
     * Normalize Indonesian mobile phone number into canonical E.164 digits ('628...').
     */
    public function normalize(string $rawPhone): string
    {
        $cleaned = preg_replace('/[\s\-\.\(\)]+/', '', trim($rawPhone)) ?? '';

        if ($cleaned === '') {
            throw new DomainException('Nomor telepon tidak boleh kosong.');
        }

        if (str_starts_with($cleaned, '+62')) {
            $cleaned = '62'.substr($cleaned, 3);
        } elseif (str_starts_with($cleaned, '08')) {
            $cleaned = '628'.substr($cleaned, 2);
        }

        if (! preg_match('/^628\d{7,12}$/', $cleaned)) {
            throw new DomainException('Format nomor telepon Indonesia tidak valid. Gunakan format 08xx atau +628xx (10-15 digit).');
        }

        return $cleaned;
    }

    /**
     * Compute deterministic HMAC-SHA256 blind index hash for a phone number.
     */
    public function computeBlindHash(string $canonicalPhone): string
    {
        $canonical = preg_match('/^628\d{7,12}$/', $canonicalPhone)
            ? $canonicalPhone
            : $this->normalize($canonicalPhone);

        return hash_hmac('sha256', 'phone:'.$canonical, (string) config('app.key'));
    }
}
