<?php

namespace App\Services;

use App\Exceptions\DuplicateBankAccountException;
use App\Models\UserBankAccount;
use App\Models\WithdrawalRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

class BankAccountService
{
    public function computeBankAccountBlindHash(string $bankCode, string $sanitizedAccountNumber): string
    {
        $canonical = strtoupper(trim($bankCode)).':'.trim($sanitizedAccountNumber);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

    /**
     * @param  array{bank_code: string, bank_name?: ?string, account_number: string, account_holder_name: string, is_primary?: bool}  $data
     */
    public function addBankAccount(int $userId, array $data): UserBankAccount
    {
        $bankCode = strtoupper(trim((string) $data['bank_code']));
        $bankName = ! empty($data['bank_name'])
            ? mb_substr(trim((string) $data['bank_name']), 0, 60)
            : (UserBankAccount::SUPPORTED_BANKS[$bankCode] ?? $bankCode);

        $accountNumber = preg_replace('/\D+/', '', trim((string) $data['account_number'])) ?? '';
        if (! preg_match('/^\d{6,20}$/', $accountNumber)) {
            throw new DomainException('Nomor rekening / e-wallet wajib berupa 6 hingga 20 digit angka.');
        }

        $holderName = mb_substr(strtoupper(trim((string) $data['account_holder_name'])), 0, 100);
        if (mb_strlen($holderName) < 3) {
            throw new DomainException('Nama pemilik rekening minimal 3 karakter.');
        }

        $blindHash = $this->computeBankAccountBlindHash($bankCode, $accountNumber);

        return DB::transaction(function () use ($userId, $bankCode, $bankName, $accountNumber, $holderName, $blindHash, $data): UserBankAccount {
            // Anti-Tuyul Cross-User Blind Hash Lock Check
            $activeCollision = UserBankAccount::where('active_account_lock_hash', $blindHash)
                ->lockForUpdate()
                ->first();

            if ($activeCollision) {
                if ((int) $activeCollision->user_id !== $userId) {
                    throw new DuplicateBankAccountException('Nomor rekening ini sudah terdaftar aktif pada akun NexaTrade lain.');
                }

                throw new DuplicateBankAccountException("Rekening {$bankCode} ({$accountNumber}) sudah tersimpan di akun Anda.");
            }

            $existingAccounts = UserBankAccount::where('user_id', $userId)
                ->lockForUpdate()
                ->get();

            $duplicate = $existingAccounts->first(function (UserBankAccount $acc) use ($bankCode, $accountNumber): bool {
                return $acc->bank_code === $bankCode && $acc->account_number === $accountNumber;
            });

            if ($duplicate) {
                throw new DuplicateBankAccountException("Rekening {$bankCode} ({$accountNumber}) sudah tersimpan di akun Anda.");
            }

            $makePrimary = $existingAccounts->isEmpty() || (bool) ($data['is_primary'] ?? false);

            if ($makePrimary) {
                UserBankAccount::where('user_id', $userId)->update(['is_primary' => false]);
            }

            return UserBankAccount::create([
                'user_id' => $userId,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_blind_hash' => $blindHash,
                'active_account_lock_hash' => $blindHash,
                'account_holder_name' => $holderName,
                'is_verified' => true,
                'is_primary' => $makePrimary,
            ]);
        });
    }

    public function setPrimary(int $userId, int $bankAccountId): UserBankAccount
    {
        return DB::transaction(function () use ($userId, $bankAccountId): UserBankAccount {
            $accounts = UserBankAccount::where('user_id', $userId)
                ->lockForUpdate()
                ->get();

            $target = $accounts->firstWhere('id', $bankAccountId);
            if (! $target) {
                throw new DomainException('Rekening tujuan tidak ditemukan di akun Anda.');
            }

            UserBankAccount::where('user_id', $userId)->update(['is_primary' => false]);

            $target->is_primary = true;
            $target->save();

            return $target->fresh();
        });
    }

    public function deleteBankAccount(int $userId, int $bankAccountId): void
    {
        DB::transaction(function () use ($userId, $bankAccountId): void {
            $target = UserBankAccount::where('id', $bankAccountId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $target) {
                throw new DomainException('Rekening tidak ditemukan di akun Anda.');
            }

            $hasPendingWithdrawal = WithdrawalRequest::where('user_bank_account_id', $bankAccountId)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->exists();

            if ($hasPendingWithdrawal) {
                throw new DomainException('Rekening sedang digunakan pada pengajuan penarikan yang masih diproses.');
            }

            $wasPrimary = $target->is_primary;
            $target->is_primary = false;
            $target->active_account_lock_hash = null;
            $target->save();
            $target->delete();

            if ($wasPrimary) {
                $replacement = UserBankAccount::where('user_id', $userId)
                    ->orderByDesc('id')
                    ->first();

                if ($replacement) {
                    $replacement->is_primary = true;
                    $replacement->save();
                }
            }
        });
    }
}
