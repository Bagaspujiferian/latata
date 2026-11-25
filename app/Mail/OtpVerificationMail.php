<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $otpCode,
        public readonly int $ttlMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        $brand = (string) config('nexatrade.app_brand', 'NexaTrade Enterprise Engine');

        return new Envelope(
            subject: "[{$brand}] Kode OTP Verifikasi Email Anda ({$this->otpCode})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp-verification',
            with: [
                'user' => $this->user,
                'otpCode' => $this->otpCode,
                'ttlMinutes' => $this->ttlMinutes,
                'appBrand' => (string) config('nexatrade.app_brand', 'NexaTrade Enterprise Engine'),
            ],
        );
    }
}
