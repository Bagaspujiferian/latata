<?php

namespace App\Services;

use App\Exceptions\InvalidWalletPinException;
use App\Exceptions\WalletPinLockedException;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WalletPinService
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    /** @var list<string> */
    private const WEAK_PINS = [
        '000000',
        '111111',
        '222222',
        '333333',
        '444444',
        '555555',
        '666666',
        '777777',
        '888888',
        '999999',
        '123456',
        '654321',
        '012345',
    ];

    public function assertStrongPin(string $pin): void
    {
        $cleanPin = trim($pin);
        if (! preg_match('/^\d{6}$/', $cleanPin)) {
            throw new DomainException('PIN Dompet wajib terdiri dari tepat 6 digit angka.');
        }

        if (in_array($cleanPin, self::WEAK_PINS, true)) {
            throw new DomainException('PIN terlalu lemah dan mudah ditebak (hindari 000000, 111111, atau 123456).');
        }
    }

    public function setPin(int $userId, string $pin, ?string $currentPassword = null): void
    {
        $cleanPin = trim($pin);
        $this->assertStrongPin($cleanPin);

        DB::transaction(function () use ($userId, $cleanPin, $currentPassword): void {
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                throw new DomainException('Akun pengguna tidak ditemukan.');
            }

            if ($currentPassword !== null && ! Hash::check($currentPassword, (string) $user->password)) {
                throw new DomainException('Password akun saat ini tidak sesuai.');
            }

            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'wallet_pin_hash' => Hash::make($cleanPin),
                    'wallet_pin_attempts' => 0,
                    'wallet_pin_locked_until' => null,
                    'wallet_pin_set_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Verify 6-digit transaction PIN under row lock.
     * Commits attempt increments and 15-minute lockout BEFORE throwing so failed attempts are never rolled back.
     */
    public function verifyPinOrFail(int $userId, ?string $submittedPin, bool $requireOnlyIfSet = true): void
    {
        $outcome = DB::transaction(function () use ($userId, $submittedPin, $requireOnlyIfSet): array {
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return ['status' => 'error', 'type' => 'invalid', 'message' => 'Pengguna tidak ditemukan.'];
            }

            if (empty($user->wallet_pin_hash)) {
                if ($requireOnlyIfSet) {
                    return ['status' => 'ok'];
                }

                return [
                    'status' => 'error',
                    'type' => 'invalid',
                    'message' => 'Anda belum mengaktifkan PIN Dompet 6-digit. Atur PIN terlebih dahulu di Pengaturan Keamanan.',
                ];
            }

            if (! empty($user->wallet_pin_locked_until)) {
                $lockedUntil = Carbon::parse($user->wallet_pin_locked_until);
                if ($lockedUntil->isFuture()) {
                    $remainingMinutes = max(1, (int) ceil(now()->diffInSeconds($lockedUntil) / 60));

                    return [
                        'status' => 'error',
                        'type' => 'locked',
                        'message' => "PIN terkunci sementara karena 5x salah. Coba lagi dalam {$remainingMinutes} menit.",
                    ];
                }
            }

            $cleanPin = trim((string) ($submittedPin ?? ''));
            if ($cleanPin === '' || ! Hash::check($cleanPin, (string) $user->wallet_pin_hash)) {
                $nextAttempts = ((int) $user->wallet_pin_attempts) + 1;

                if ($nextAttempts >= self::MAX_ATTEMPTS) {
                    DB::table('users')->where('id', $userId)->update([
                        'wallet_pin_attempts' => self::MAX_ATTEMPTS,
                        'wallet_pin_locked_until' => now()->addMinutes(self::LOCKOUT_MINUTES),
                        'updated_at' => now(),
                    ]);

                    return [
                        'status' => 'error',
                        'type' => 'locked',
                        'message' => 'PIN terkunci sementara karena 5x salah. Coba lagi dalam '.self::LOCKOUT_MINUTES.' menit.',
                    ];
                }

                DB::table('users')->where('id', $userId)->update([
                    'wallet_pin_attempts' => $nextAttempts,
                    'updated_at' => now(),
                ]);

                $remainingTries = self::MAX_ATTEMPTS - $nextAttempts;

                return [
                    'status' => 'error',
                    'type' => 'invalid',
                    'message' => "PIN Dompet 6-digit tidak sesuai. Sisa percobaan sebelum terkunci: {$remainingTries}x.",
                ];
            }

            if ((int) $user->wallet_pin_attempts > 0 || $user->wallet_pin_locked_until !== null) {
                DB::table('users')->where('id', $userId)->update([
                    'wallet_pin_attempts' => 0,
                    'wallet_pin_locked_until' => null,
                    'updated_at' => now(),
                ]);
            }

            return ['status' => 'ok'];
        });

        if ($outcome['status'] === 'ok') {
            return;
        }

        if ($outcome['type'] === 'locked') {
            throw new WalletPinLockedException($outcome['message']);
        }

        throw new InvalidWalletPinException($outcome['message']);
    }
}
