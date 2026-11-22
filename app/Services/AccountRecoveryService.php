<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletPinResetToken;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountRecoveryService
{
    private const PASSWORD_TOKEN_TTL_MINUTES = 30;

    private const PIN_TOKEN_TTL_MINUTES = 15;

    public function __construct(
        private readonly WalletPinService $walletPinService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Issue a hashed 30-minute password reset token in standard `password_reset_tokens`.
     *
     * @return array{dispatched: bool, preview_token: ?string, preview_url: ?string}
     */
    public function requestPasswordReset(string $email): array
    {
        $normalizedEmail = strtolower(trim($email));
        $user = User::where('email', $normalizedEmail)->where('is_active', true)->first();

        if (! $user) {
            return [
                'dispatched' => true,
                'preview_token' => null,
                'preview_url' => null,
            ];
        }

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $normalizedEmail],
            [
                'token' => $tokenHash,
                'created_at' => now(),
            ],
        );

        $isDemoPreview = (bool) config('nexatrade.demo_switcher_enabled', false);

        return [
            'dispatched' => true,
            'preview_token' => $isDemoPreview ? $plainToken : null,
            'preview_url' => $isDemoPreview
                ? route('password.reset', ['token' => $plainToken, 'email' => $normalizedEmail])
                : null,
        ];
    }

    public function resetPasswordWithToken(string $email, string $plainToken, string $newPassword): User
    {
        $normalizedEmail = strtolower(trim($email));
        if (mb_strlen($newPassword) < 8) {
            throw new DomainException('Kata sandi baru minimal 8 karakter.');
        }

        return DB::transaction(function () use ($normalizedEmail, $plainToken, $newPassword): User {
            $row = DB::table('password_reset_tokens')
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new DomainException('Token pemulihan kata sandi tidak ditemukan atau sudah digunakan.');
            }

            $createdAt = Carbon::parse($row->created_at);
            if ($createdAt->copy()->addMinutes(self::PASSWORD_TOKEN_TTL_MINUTES)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $normalizedEmail)->delete();
                throw new DomainException('Token pemulihan kata sandi telah kedaluwarsa (berlaku 30 menit). Silakan ajukan ulang.');
            }

            $expectedHash = hash('sha256', trim($plainToken));
            if (! hash_equals((string) $row->token, $expectedHash)) {
                throw new DomainException('Token pemulihan kata sandi tidak valid.');
            }

            $user = User::where('email', $normalizedEmail)->lockForUpdate()->first();
            if (! $user) {
                throw new DomainException('Akun pengguna tidak ditemukan.');
            }

            $user->forceFill([
                'password' => Hash::make($newPassword),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('password_reset_tokens')->where('email', $normalizedEmail)->delete();

            // Invalidate old DB sessions if session driver stores sessions in `sessions` table.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $userId = (int) $user->id;
            DB::afterCommit(function () use ($userId): void {
                $this->notificationService->notify(
                    userId: $userId,
                    category: 'system',
                    title: 'Kata Sandi Akun Berhasil Direset',
                    body: 'Kata sandi akun NexaTrade Anda baru saja diperbarui melalui pemulihan mandiri. Sesi lama telah dihentikan.',
                    actionUrl: '/account/security',
                    refType: 'security',
                );
            });

            return $user;
        });
    }

    /**
     * Issue a single-use 15-minute Wallet PIN reset token in `wallet_pin_reset_tokens`.
     *
     * @return array{expires_at: Carbon, preview_token: ?string}
     */
    public function requestWalletPinReset(int $userId, ?string $currentPassword = null): array
    {
        $user = User::findOrFail($userId);

        if ($currentPassword !== null && ! Hash::check($currentPassword, $user->password)) {
            throw new DomainException('Kata sandi login akun tidak sesuai.');
        }

        $plainToken = strtoupper(Str::random(12)).'-'.Str::random(20);
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = now()->addMinutes(self::PIN_TOKEN_TTL_MINUTES);

        WalletPinResetToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => now(),
        ]);

        $isDemoPreview = (bool) config('nexatrade.demo_switcher_enabled', false);

        return [
            'expires_at' => $expiresAt,
            'preview_token' => $isDemoPreview ? $plainToken : null,
        ];
    }

    /**
     * Verify single-use PIN reset token, validate strong 6-digit PIN, reset lockout, and mark token used.
     */
    public function resetWalletPinWithToken(int $userId, string $plainToken, string $newPin): void
    {
        $cleanPin = trim($newPin);
        $this->walletPinService->assertStrongPin($cleanPin);

        DB::transaction(function () use ($userId, $plainToken, $cleanPin): void {
            $tokenHash = hash('sha256', trim($plainToken));

            $tokenRow = DB::table('wallet_pin_reset_tokens')
                ->where('user_id', $userId)
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $tokenRow) {
                throw new DomainException('Token reset PIN tidak ditemukan atau tidak sesuai.');
            }

            if ($tokenRow->used_at !== null) {
                throw new DomainException('Token reset PIN ini sudah pernah digunakan (single-use).');
            }

            if (Carbon::parse($tokenRow->expires_at)->isPast()) {
                throw new DomainException('Token reset PIN telah kedaluwarsa (berlaku 15 menit). Ajukan token baru.');
            }

            DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->update([
                    'wallet_pin_hash' => Hash::make($cleanPin),
                    'wallet_pin_attempts' => 0,
                    'wallet_pin_locked_until' => null,
                    'wallet_pin_set_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('wallet_pin_reset_tokens')
                ->where('id', $tokenRow->id)
                ->update([
                    'used_at' => now(),
                ]);

            // Invalidate any other stale unused PIN reset tokens for this user.
            DB::table('wallet_pin_reset_tokens')
                ->where('user_id', $userId)
                ->where('id', '!=', $tokenRow->id)
                ->whereNull('used_at')
                ->delete();

            DB::afterCommit(function () use ($userId): void {
                $this->notificationService->notify(
                    userId: $userId,
                    category: 'system',
                    title: 'PIN Dompet 6-Digit Berhasil Direset',
                    body: 'PIN Dompet Anda telah diperbarui dan status penguncian PIN direset kembali aktif.',
                    actionUrl: '/account/security',
                    refType: 'security',
                );
            });
        });
    }
}
