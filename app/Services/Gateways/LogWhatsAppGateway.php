<?php

namespace App\Services\Gateways;

use App\Contracts\WhatsAppGatewayInterface;
use Illuminate\Support\Facades\Log;

class LogWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function sendOtp(string $normalizedPhone, string $otpCode, int $ttlMinutes): void
    {
        $displayOtp = app()->isProduction()
            ? substr($otpCode, 0, 2).'****'
            : $otpCode;

        $maskedPhone = strlen($normalizedPhone) > 6
            ? substr($normalizedPhone, 0, 4).'****'.substr($normalizedPhone, -3)
            : $normalizedPhone;

        Log::info('[NexaTrade][WhatsAppOTP][LogDriver] Outbound WhatsApp OTP simulated.', [
            'driver' => 'log',
            'target_phone' => app()->isProduction() ? $maskedPhone : $normalizedPhone,
            'otp_code' => $displayOtp,
            'ttl_minutes' => $ttlMinutes,
        ]);
    }
}
