<?php

namespace App\Services;

use App\Enums\VerificationChannel;
use App\Exceptions\OtpRateLimitExceededException;
use Illuminate\Support\Facades\RateLimiter;

class OtpRateLimiterService
{
    /**
     * Enforce 3-layer Anti-Drain OTP Rate Limiting before generating or dispatching any OTP:
     * 1. Per-target resend cooldown (default 60s)
     * 2. Per-target hourly cap (default 5/hour)
     * 3. Per-IP hourly cap (default 15/hour)
     *
     * @throws OtpRateLimitExceededException
     */
    public function ensureAllowedAndHit(
        VerificationChannel $channel,
        string $targetHash,
        ?string $ipAddress = null,
    ): void {
        $cooldownSeconds = max(1, (int) config('nexatrade.otp_gateway.resend_cooldown_seconds', 60));
        $maxPerTargetHour = max(1, (int) config('nexatrade.otp_gateway.max_per_target_per_hour', 5));
        $maxPerIpHour = max(1, (int) config('nexatrade.otp_gateway.max_per_ip_per_hour', 15));

        $cooldownKey = $this->cooldownKey($targetHash);
        $targetHourKey = $this->targetHourKey($targetHash);
        $normalizedIp = trim((string) ($ipAddress ?: '127.0.0.1'));
        $ipHourKey = $this->ipHourKey($normalizedIp);

        $channelLabel = $channel === VerificationChannel::EMAIL ? 'email' : 'nomor WhatsApp';

        // Layer 1: Per-target resend cooldown (60 seconds)
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $retryAfter = max(1, RateLimiter::availableIn($cooldownKey));

            throw new OtpRateLimitExceededException(
                message: "Mohon tunggu {$retryAfter} detik sebelum meminta pengiriman ulang kode OTP ke {$channelLabel} ini.",
                retryAfterSeconds: $retryAfter,
                limitLayer: 'cooldown',
            );
        }

        // Layer 2: Per-target hourly quota (max 5 OTPs / hour to the same phone/email target_hash)
        if (RateLimiter::tooManyAttempts($targetHourKey, $maxPerTargetHour)) {
            $retryAfter = max(1, RateLimiter::availableIn($targetHourKey));

            throw new OtpRateLimitExceededException(
                message: "Batas pengiriman OTP ke {$channelLabel} ini telah mencapai maksimal {$maxPerTargetHour} kali per jam. Silakan coba kembali dalam {$retryAfter} detik.",
                retryAfterSeconds: $retryAfter,
                limitLayer: 'target_hourly',
            );
        }

        // Layer 3: Per-IP hourly quota (max 15 OTPs / hour across any targets/accounts from the same IP)
        if ($ipAddress !== null && $ipAddress !== '' && RateLimiter::tooManyAttempts($ipHourKey, $maxPerIpHour)) {
            $retryAfter = max(1, RateLimiter::availableIn($ipHourKey));

            throw new OtpRateLimitExceededException(
                message: "Terlalu banyak permintaan pengiriman OTP dari jaringan/IP Anda (maksimal {$maxPerIpHour} kali per jam). Silakan coba kembali dalam {$retryAfter} detik.",
                retryAfterSeconds: $retryAfter,
                limitLayer: 'ip_hourly',
            );
        }

        // All 3 gates passed -> record hits atomically
        RateLimiter::hit($cooldownKey, $cooldownSeconds);
        RateLimiter::hit($targetHourKey, 3600);
        if ($ipAddress !== null && $ipAddress !== '') {
            RateLimiter::hit($ipHourKey, 3600);
        }
    }

    /**
     * Clear only the short 60-second resend cooldown when a challenge is permanently locked after 5 failed attempts,
     * while strictly preserving the 5/hour per-target and 15/hour per-IP anti-drain counters.
     */
    public function clearResendCooldown(string $targetHash): void
    {
        RateLimiter::clear($this->cooldownKey($targetHash));
    }

    private function cooldownKey(string $targetHash): string
    {
        return 'otp:cooldown:'.$targetHash;
    }

    private function targetHourKey(string $targetHash): string
    {
        return 'otp:target_hour:'.$targetHash;
    }

    private function ipHourKey(string $ipAddress): string
    {
        return 'otp:ip_hour:'.$ipAddress;
    }
}
