<?php

namespace App\Services;

use App\Contracts\WhatsAppGatewayInterface;
use App\Enums\VerificationChannel;
use App\Exceptions\DuplicatePhoneNumberException;
use App\Mail\OtpVerificationMail;
use App\Models\User;
use App\Models\UserVerificationChallenge;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserVerificationService
{
    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;

    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly NotificationService $notificationService,
        private readonly OtpRateLimiterService $otpRateLimiter,
    ) {}

    /**
     * Normalize, blind-hash, encrypt, and bind a unique Indonesian phone number to the user.
     */
    public function bindPhoneNumber(User $user, string $rawPhone): string
    {
        $canonicalPhone = $this->phoneNormalizer->normalize($rawPhone);
        $blindHash = $this->phoneNormalizer->computeBlindHash($canonicalPhone);

        try {
            DB::transaction(function () use ($user, $canonicalPhone, $blindHash): void {
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                $collisionExists = User::where('phone_blind_hash', $blindHash)
                    ->where('id', '!=', $lockedUser->id)
                    ->lockForUpdate()
                    ->exists();

                if ($collisionExists) {
                    throw new DuplicatePhoneNumberException(
                        'Nomor telepon ini sudah terdaftar pada akun NexaTrade lain. Satu nomor telepon hanya dapat digunakan oleh satu akun.'
                    );
                }

                $phoneChanged = $lockedUser->phone_blind_hash !== $blindHash;

                $lockedUser->update([
                    'phone_encrypted' => Crypt::encryptString($canonicalPhone),
                    'phone_blind_hash' => $blindHash,
                    'phone_verified_at' => $phoneChanged ? null : $lockedUser->phone_verified_at,
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new DuplicatePhoneNumberException(
                    'Nomor telepon ini sudah terdaftar pada akun NexaTrade lain.'
                );
            }

            throw $e;
        }

        $user->refresh();

        return $canonicalPhone;
    }

    /**
     * Issue a new 6-digit OTP challenge (10-minute TTL, max 5 attempts), enforce 3-layer anti-drain rate limits,
     * deliver via WhatsApp Gateway or Transactional Email Mailable, and invalidate prior unconsumed challenges.
     */
    public function issueChallenge(User $user, VerificationChannel $channel, ?string $ipAddress = null): string
    {
        $user->refresh();
        $target = $this->resolveChannelTarget($user, $channel);
        $targetHash = $this->computeTargetHash($channel, $target);

        // Enforce 3-layer anti-drain rate limiter BEFORE generating OTP or touching external gateways
        $this->otpRateLimiter->ensureAllowedAndHit($channel, $targetHash, $ipAddress);

        $plainOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = $this->computeCodeHash((int) $user->id, $channel, $plainOtp);

        DB::transaction(function () use ($user, $channel, $target, $targetHash, $codeHash, $plainOtp): void {
            UserVerificationChallenge::where('user_id', $user->id)
                ->where('channel', $channel->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $challenge = UserVerificationChallenge::create([
                'user_id' => $user->id,
                'channel' => $channel,
                'target_hash' => $targetHash,
                'code_hash' => $codeHash,
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'consumed_at' => null,
                'created_at' => now(),
            ]);

            if ($channel === VerificationChannel::PHONE) {
                app(WhatsAppGatewayInterface::class)->sendOtp($target, $plainOtp, self::TTL_MINUTES);
            } else {
                Mail::to($target)->send(new OtpVerificationMail($user, $plainOtp, self::TTL_MINUTES));
            }

            $userId = (int) $user->id;
            $challengeId = (int) $challenge->id;
            $channelLabel = $channel === VerificationChannel::EMAIL ? 'Email' : 'Nomor Telepon';

            DB::afterCommit(function () use ($userId, $challengeId, $channelLabel, $plainOtp): void {
                $this->notificationService->notify(
                    userId: $userId,
                    category: 'system',
                    title: "Kode OTP Verifikasi {$channelLabel}",
                    body: "Kode OTP 6-digit Anda adalah {$plainOtp} (berlaku 10 menit). Jangan berikan kode ini kepada siapa pun.",
                    actionUrl: '/account/security',
                    refType: 'verification_challenge',
                    refId: $challengeId,
                );
            });
        });

        return $plainOtp;
    }

    /**
     * Verify a 6-digit OTP challenge under pessimistic row lock with 5-attempt brute-force lockout.
     */
    public function verifyChallenge(User $user, VerificationChannel $channel, string $plainCode): void
    {
        $cleanCode = trim($plainCode);

        /** @var array{ok: bool, error: ?string} $outcome */
        $outcome = DB::transaction(function () use ($user, $channel, $cleanCode): array {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $challenge = UserVerificationChallenge::where('user_id', $lockedUser->id)
                ->where('channel', $channel->value)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return [
                    'ok' => false,
                    'error' => 'Tidak ada tantangan OTP aktif untuk kanal ini. Silakan minta kode OTP baru.',
                ];
            }

            if ($challenge->expires_at->isPast()) {
                return [
                    'ok' => false,
                    'error' => 'Kode OTP telah kedaluwarsa (melewati batas 10 menit). Silakan minta kode OTP baru.',
                ];
            }

            if ((int) $challenge->attempts >= (int) $challenge->max_attempts) {
                return [
                    'ok' => false,
                    'error' => 'Kode OTP terkunci karena telah melewati batas maksimal 5 kali percobaan gagal. Silakan minta kode OTP baru.',
                ];
            }

            $currentTarget = $this->resolveChannelTarget($lockedUser, $channel);
            $expectedTargetHash = $this->computeTargetHash($channel, $currentTarget);

            if (! hash_equals((string) $challenge->target_hash, $expectedTargetHash)) {
                return [
                    'ok' => false,
                    'error' => 'Target verifikasi telah berubah sejak kode OTP diterbitkan. Silakan minta kode OTP baru.',
                ];
            }

            $expectedCodeHash = $this->computeCodeHash((int) $lockedUser->id, $channel, $cleanCode);

            if (! hash_equals((string) $challenge->code_hash, $expectedCodeHash)) {
                $newAttempts = (int) $challenge->attempts + 1;
                $challenge->update(['attempts' => $newAttempts]);

                $remaining = max(0, (int) $challenge->max_attempts - $newAttempts);
                if ($remaining === 0) {
                    $this->otpRateLimiter->clearResendCooldown($expectedTargetHash);

                    return [
                        'ok' => false,
                        'error' => 'Kode OTP tidak sesuai. Batas percobaan (5x) telah habis dan kode OTP ini dikunci.',
                    ];
                }

                return [
                    'ok' => false,
                    'error' => "Kode OTP tidak sesuai. Sisa kesempatan percobaan: {$remaining}x.",
                ];
            }

            $now = now();
            $challenge->update([
                'consumed_at' => $now,
            ]);

            if ($channel === VerificationChannel::EMAIL) {
                $lockedUser->update(['email_verified_at' => $now]);
            } else {
                $lockedUser->update(['phone_verified_at' => $now]);
            }

            return [
                'ok' => true,
                'error' => null,
            ];
        });

        if (! $outcome['ok']) {
            throw new DomainException((string) $outcome['error']);
        }

        $user->refresh();
    }

    private function resolveChannelTarget(User $user, VerificationChannel $channel): string
    {
        if ($channel === VerificationChannel::EMAIL) {
            return strtolower(trim($user->email));
        }

        $phone = $user->decryptedPhone();
        if ($phone === null || empty($user->phone_blind_hash)) {
            throw new DomainException('Nomor telepon belum disimpan pada akun Anda. Silakan simpan nomor telepon terlebih dahulu.');
        }

        return $phone;
    }

    private function computeTargetHash(VerificationChannel $channel, string $target): string
    {
        return hash_hmac('sha256', 'target:'.$channel->value.':'.$target, (string) config('app.key'));
    }

    private function computeCodeHash(int $userId, VerificationChannel $channel, string $plainCode): string
    {
        return hash_hmac('sha256', 'otp:'.$userId.':'.$channel->value.':'.$plainCode, (string) config('app.key'));
    }
}
