<?php

namespace App\Services\Gateways;

use App\Contracts\WhatsAppGatewayInterface;
use App\Exceptions\OtpDeliveryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FonnteWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function sendOtp(string $normalizedPhone, string $otpCode, int $ttlMinutes): void
    {
        $token = trim((string) config('nexatrade.otp_gateway.fonnte_token', ''));
        $endpoint = trim((string) config('nexatrade.otp_gateway.fonnte_endpoint', 'https://api.fonnte.com/send'));

        if ($token === '' || $endpoint === '') {
            Log::error('[NexaTrade][WhatsAppOTP][Fonnte] Fonnte API token or endpoint is not configured.');

            throw new OtpDeliveryException(
                'Layanan pengiriman OTP WhatsApp sedang tidak tersedia (konfigurasi gateway belum lengkap). Silakan coba beberapa saat lagi.'
            );
        }

        $brand = (string) config('nexatrade.app_brand', 'NexaTrade Enterprise Engine');
        $message = "*[{$brand}]*\n\n"
            ."Kode OTP verifikasi keamanan akun Anda adalah: *{$otpCode}*\n"
            ."Berlaku selama *{$ttlMinutes} menit*.\n\n"
            .'_Jangan pernah membagikan kode rahasia ini kepada siapa pun termasuk pihak yang mengaku staf NexaTrade._';

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Authorization' => $token,
                ])
                ->asForm()
                ->post($endpoint, [
                    'target' => $normalizedPhone,
                    'message' => $message,
                    'countryCode' => '62',
                ]);
        } catch (Throwable $e) {
            Log::error('[NexaTrade][WhatsAppOTP][Fonnte] HTTP transport failure while dispatching WhatsApp OTP.', [
                'target_phone_tail' => substr($normalizedPhone, -4),
                'error' => $e->getMessage(),
            ]);

            throw new OtpDeliveryException(
                'Gagal menghubungi server gateway WhatsApp (koneksi timeout/gangguan jaringan). Silakan coba lagi.'
            );
        }

        if (! $response->successful()) {
            Log::error('[NexaTrade][WhatsAppOTP][Fonnte] Non-2xx HTTP status from Fonnte gateway.', [
                'status' => $response->status(),
                'target_phone_tail' => substr($normalizedPhone, -4),
            ]);

            throw new OtpDeliveryException(
                'Gagal mengirim kode OTP WhatsApp karena gangguan layanan gateway eksternal. Silakan coba beberapa saat lagi.'
            );
        }

        $payload = $response->json();
        if (is_array($payload) && array_key_exists('status', $payload) && $payload['status'] === false) {
            $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : 'Unknown gateway rejection';

            Log::warning('[NexaTrade][WhatsAppOTP][Fonnte] Fonnte API returned status=false.', [
                'target_phone_tail' => substr($normalizedPhone, -4),
                'reason' => $reason,
            ]);

            throw new OtpDeliveryException(
                'Pengiriman kode OTP WhatsApp ditolak oleh gateway operator. Pastikan nomor WhatsApp Anda aktif dan coba kembali.'
            );
        }
    }
}
