<?php

namespace App\Contracts;

use App\Exceptions\OtpDeliveryException;

interface WhatsAppGatewayInterface
{
    /**
     * Send a 6-digit OTP verification message to a canonical Indonesian phone number (e.g. 6281234567890).
     *
     * @throws OtpDeliveryException
     */
    public function sendOtp(string $normalizedPhone, string $otpCode, int $ttlMinutes): void;
}
