<?php

namespace App\Services;

use App\Exceptions\DuplicateKycNikException;
use App\Models\SellerKycVerification;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use App\Models\User;
use App\Models\Wallet;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SellerKycService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function computeNikBlindHash(string $nik): string
    {
        $normalized = preg_replace('/\D+/', '', trim($nik)) ?? '';
        if (! preg_match('/^\d{16}$/', $normalized)) {
            throw new DomainException('NIK wajib terdiri dari tepat 16 digit angka.');
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /**
     * @param  array{proposed_store_name: string, proposed_store_slug?: ?string, proposed_store_tagline?: ?string, full_legal_name: string, nik: string}  $data
     */
    public function submitApplication(
        int $userId,
        array $data,
        UploadedFile $idCardFile,
        UploadedFile $selfieFile,
    ): SellerKycVerification {
        $rawNik = preg_replace('/\D+/', '', trim((string) ($data['nik'] ?? ''))) ?? '';
        if (! preg_match('/^\d{16}$/', $rawNik)) {
            throw new DomainException('NIK wajib terdiri dari tepat 16 digit angka sesuai e-KTP.');
        }

        $nikBlindHash = $this->computeNikBlindHash($rawNik);
        $storeName = mb_substr(trim((string) $data['proposed_store_name']), 0, 100);
        $slugCandidate = ! empty($data['proposed_store_slug'])
            ? Str::slug((string) $data['proposed_store_slug'])
            : Str::slug($storeName);

        if ($slugCandidate === '') {
            throw new DomainException('URL slug toko tidak valid. Gunakan huruf atau angka.');
        }

        $tagline = isset($data['proposed_store_tagline']) && trim((string) $data['proposed_store_tagline']) !== ''
            ? mb_substr(trim((string) $data['proposed_store_tagline']), 0, 150)
            : null;
        $legalName = mb_substr(trim((string) $data['full_legal_name']), 0, 120);

        return DB::transaction(function () use (
            $userId,
            $rawNik,
            $nikBlindHash,
            $storeName,
            $slugCandidate,
            $tagline,
            $legalName,
            $idCardFile,
            $selfieFile,
        ): SellerKycVerification {
            $userRow = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (! $userRow) {
                throw new DomainException('Akun pengguna tidak ditemukan.');
            }

            if (SellerProfile::where('user_id', $userId)->exists()) {
                throw new DomainException('Akun Anda sudah memiliki toko terverifikasi.');
            }

            $existingActiveUserKyc = SellerKycVerification::where('active_user_lock', $userId)
                ->lockForUpdate()
                ->exists();
            if ($existingActiveUserKyc) {
                throw new DomainException('Anda sudah memiliki pengajuan KYC yang sedang ditinjau atau telah disetujui.');
            }

            $existingActiveNik = SellerKycVerification::where('active_nik_lock_hash', $nikBlindHash)
                ->lockForUpdate()
                ->exists();
            if ($existingActiveNik) {
                throw new DuplicateKycNikException('NIK ini sudah terdaftar pada pengajuan atau toko aktif lain.');
            }

            $slugTakenProfile = SellerProfile::where('slug', $slugCandidate)->exists();
            $slugTakenKyc = SellerKycVerification::where('proposed_store_slug', $slugCandidate)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();
            if ($slugTakenProfile || $slugTakenKyc) {
                throw new DomainException("URL toko [{$slugCandidate}] sudah digunakan. Silakan pilih slug lain.");
            }

            // Store KYC documents STRICTLY on private local disk (NEVER public!)
            $idCardPath = Storage::disk('local')->putFile("private/kyc/{$userId}", $idCardFile);
            $selfiePath = Storage::disk('local')->putFile("private/kyc/{$userId}", $selfieFile);

            if (! is_string($idCardPath) || ! is_string($selfiePath)) {
                throw new DomainException('Gagal menyimpan berkas verifikasi KYC secara aman.');
            }

            $kyc = SellerKycVerification::create([
                'user_id' => $userId,
                'active_user_lock' => $userId,
                'proposed_store_name' => $storeName,
                'proposed_store_slug' => $slugCandidate,
                'proposed_store_tagline' => $tagline,
                'full_legal_name' => $legalName,
                'nik_encrypted' => Crypt::encryptString($rawNik),
                'nik_last4' => substr($rawNik, -4),
                'nik_blind_hash' => $nikBlindHash,
                'active_nik_lock_hash' => $nikBlindHash,
                'id_card_private_path' => $idCardPath,
                'selfie_private_path' => $selfiePath,
                'status' => 'pending',
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            $this->notificationService->notifyAdmins(
                category: 'kyc',
                title: "Pengajuan KYC Baru: {$storeName}",
                body: "{$legalName} (NIK ****{$kyc->nik_last4}) mengajukan pembukaan toko baru. Segera tinjau dokumen.",
                actionUrl: route('admin.kyc.index', ['tab' => 'pending'], false),
                refType: 'seller_kyc',
                refId: (int) $kyc->id,
            );

            return $kyc;
        });
    }

    public function approveApplication(int $kycId, int $adminId): SellerKycVerification
    {
        return DB::transaction(function () use ($kycId, $adminId): SellerKycVerification {
            $admin = User::findOrFail($adminId);
            if (! $admin->isAdmin()) {
                throw new DomainException('Hanya Admin yang berwenang menyetujui verifikasi KYC.');
            }

            $kyc = SellerKycVerification::where('id', $kycId)->lockForUpdate()->first();
            if (! $kyc) {
                throw new DomainException("Data KYC #{$kycId} tidak ditemukan.");
            }

            if ($kyc->status !== 'pending') {
                throw new DomainException("Pengajuan KYC #{$kycId} sudah diproses sebelumnya (status: {$kyc->status}).");
            }

            $applicant = User::where('id', $kyc->user_id)->lockForUpdate()->firstOrFail();

            if (SellerProfile::where('slug', $kyc->proposed_store_slug)->exists()) {
                throw new DomainException("Slug toko [{$kyc->proposed_store_slug}] sudah dipakai toko lain.");
            }

            $kyc->status = 'approved';
            $kyc->rejection_reason = null;
            $kyc->reviewed_by = $adminId;
            $kyc->reviewed_at = now();
            $kyc->save();

            // Upgrade role buyer -> seller (never downgrade or overwrite admin)
            if ($applicant->role === 'buyer') {
                $applicant->role = 'seller';
                $applicant->save();
            }

            if (! SellerProfile::where('user_id', $applicant->id)->exists()) {
                SellerProfile::create([
                    'user_id' => $applicant->id,
                    'store_name' => $kyc->proposed_store_name,
                    'slug' => $kyc->proposed_store_slug,
                    'store_tagline' => $kyc->proposed_store_tagline ?? 'Merchant Terverifikasi KYC NexaTrade',
                    'store_banner_path' => '/images/stores/juragan-banner.svg',
                    'store_bio' => 'Toko terverifikasi KYC NexaTrade Safe Trading dengan jaminan Escrow otomatis.',
                    'store_level' => 1,
                    'total_completed_orders' => 0,
                    'total_cancelled_orders' => 0,
                    'avg_delivery_minutes' => '0.00',
                    'is_sla_10m_badge' => false,
                    'is_store_open' => true,
                    'auto_schedule_enabled' => false,
                    'ad_credits' => 5,
                ]);
            }

            Wallet::firstOrCreate(
                ['user_id' => $applicant->id],
                [
                    'pending_balance' => '0.00',
                    'available_balance' => '0.00',
                    'updated_at' => now(),
                ],
            );

            for ($day = 0; $day <= 6; $day++) {
                StoreOperatingHour::firstOrCreate(
                    [
                        'seller_id' => $applicant->id,
                        'day_of_week' => $day,
                    ],
                    [
                        'open_time' => '08:00:00',
                        'close_time' => '22:00:00',
                        'is_closed' => false,
                    ],
                );
            }

            $this->notificationService->notify(
                userId: (int) $applicant->id,
                category: 'kyc',
                title: 'Selamat! Toko Anda Resmi Disetujui',
                body: "Verifikasi KYC untuk {$kyc->proposed_store_name} telah disetujui. Anda kini dapat mulai berjualan di TokoKu Studio.",
                actionUrl: route('seller.dashboard', [], false),
                refType: 'seller_kyc',
                refId: (int) $kyc->id,
            );

            return $kyc->fresh(['user.sellerProfile', 'reviewer']);
        });
    }

    public function rejectApplication(int $kycId, int $adminId, string $reason): SellerKycVerification
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new DomainException('Alasan penolakan KYC wajib diisi.');
        }

        return DB::transaction(function () use ($kycId, $adminId, $trimmedReason): SellerKycVerification {
            $admin = User::findOrFail($adminId);
            if (! $admin->isAdmin()) {
                throw new DomainException('Hanya Admin yang berwenang menolak verifikasi KYC.');
            }

            $kyc = SellerKycVerification::where('id', $kycId)->lockForUpdate()->first();
            if (! $kyc) {
                throw new DomainException("Data KYC #{$kycId} tidak ditemukan.");
            }

            if ($kyc->status !== 'pending') {
                throw new DomainException("Pengajuan KYC #{$kycId} sudah diproses sebelumnya (status: {$kyc->status}).");
            }

            // Release both unique active locks so rejected applicant can legitimately re-apply
            $kyc->status = 'rejected';
            $kyc->rejection_reason = mb_substr($trimmedReason, 0, 255);
            $kyc->active_nik_lock_hash = null;
            $kyc->active_user_lock = null;
            $kyc->reviewed_by = $adminId;
            $kyc->reviewed_at = now();
            $kyc->save();

            $this->notificationService->notify(
                userId: (int) $kyc->user_id,
                category: 'kyc',
                title: 'Pengajuan KYC Buka Toko Ditolak',
                body: "Alasan: {$kyc->rejection_reason}. Anda dapat memperbaiki dokumen dan mengajukan ulang.",
                actionUrl: route('buyer.open-store', [], false),
                refType: 'seller_kyc',
                refId: (int) $kyc->id,
            );

            return $kyc->fresh(['user', 'reviewer']);
        });
    }
}
