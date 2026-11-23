<?php

namespace App\Services;

use App\Models\OrderReview;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use DomainException;
use Illuminate\Support\Facades\DB;

class StorefrontEngagementService
{
    /**
     * @return array{wishlisted: bool, wishlist_count: int}
     */
    public function toggleWishlist(int $userId, int $productId): array
    {
        return DB::transaction(function () use ($userId, $productId): array {
            $product = Product::where('id', $productId)->first();
            if (! $product) {
                throw new DomainException('Produk tidak ditemukan atau sudah diarsipkan.');
            }

            $exists = DB::table('wishlists')
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                DB::table('wishlists')
                    ->where('user_id', $userId)
                    ->where('product_id', $productId)
                    ->delete();
                $wishlisted = false;
            } else {
                DB::table('wishlists')->insert([
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'created_at' => now(),
                ]);
                $wishlisted = true;
            }

            $count = (int) DB::table('wishlists')->where('product_id', $productId)->count();

            return [
                'wishlisted' => $wishlisted,
                'wishlist_count' => $count,
            ];
        });
    }

    /**
     * @return array{following: bool, followers_count: int}
     */
    public function toggleFollowStore(int $userId, int $sellerId): array
    {
        if ($userId === $sellerId) {
            throw new DomainException('Anda tidak dapat mengikuti toko milik sendiri.');
        }

        return DB::transaction(function () use ($userId, $sellerId): array {
            $store = SellerProfile::where('user_id', $sellerId)->first();
            if (! $store) {
                throw new DomainException('Profil toko tidak ditemukan.');
            }

            $exists = DB::table('store_followers')
                ->where('user_id', $userId)
                ->where('seller_id', $sellerId)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                DB::table('store_followers')
                    ->where('user_id', $userId)
                    ->where('seller_id', $sellerId)
                    ->delete();
                $following = false;
            } else {
                DB::table('store_followers')->insert([
                    'user_id' => $userId,
                    'seller_id' => $sellerId,
                    'created_at' => now(),
                ]);
                $following = true;
            }

            $count = (int) DB::table('store_followers')->where('seller_id', $sellerId)->count();

            return [
                'following' => $following,
                'followers_count' => $count,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<int, array{open_time?: string, close_time?: string, is_closed?: bool|int|string}>  $hoursByDay
     */
    public function updateStoreProfileAndHours(int $sellerId, array $profileData, array $hoursByDay): SellerProfile
    {
        return DB::transaction(function () use ($sellerId, $profileData, $hoursByDay): SellerProfile {
            $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->firstOrFail();

            $profile->store_name = trim((string) ($profileData['store_name'] ?? $profile->store_name));
            $profile->store_tagline = isset($profileData['store_tagline'])
                ? mb_substr(trim((string) $profileData['store_tagline']), 0, 150)
                : $profile->store_tagline;
            $profile->store_bio = isset($profileData['store_bio'])
                ? mb_substr(trim((string) $profileData['store_bio']), 0, 500)
                : $profile->store_bio;

            if (! empty($profileData['store_banner_path'])) {
                $profile->store_banner_path = (string) $profileData['store_banner_path'];
            }

            if (array_key_exists('auto_schedule_enabled', $profileData)) {
                $profile->auto_schedule_enabled = (bool) $profileData['auto_schedule_enabled'];
            }

            $profile->save();

            for ($day = 0; $day <= 6; $day++) {
                if (! isset($hoursByDay[$day])) {
                    continue;
                }

                $entry = $hoursByDay[$day];
                $openTime = $this->normalizeTime((string) ($entry['open_time'] ?? '08:00:00'));
                $closeTime = $this->normalizeTime((string) ($entry['close_time'] ?? '22:00:00'));
                $isClosed = filter_var($entry['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);

                StoreOperatingHour::updateOrCreate(
                    [
                        'seller_id' => $sellerId,
                        'day_of_week' => $day,
                    ],
                    [
                        'open_time' => $openTime,
                        'close_time' => $closeTime,
                        'is_closed' => $isClosed,
                    ],
                );
            }

            return $profile->refresh()->load('operatingHours');
        });
    }

    public function replyToReview(int $reviewId, int $sellerId, string $replyText): OrderReview
    {
        $trimmed = trim($replyText);
        if ($trimmed === '') {
            throw new DomainException('Isi balasan ulasan tidak boleh kosong.');
        }

        return DB::transaction(function () use ($reviewId, $sellerId, $trimmed): OrderReview {
            $review = OrderReview::where('id', $reviewId)->lockForUpdate()->first();
            if (! $review) {
                throw new DomainException("Ulasan #{$reviewId} tidak ditemukan.");
            }

            if ((int) $review->seller_id !== $sellerId) {
                throw new DomainException('Anda hanya dapat membalas ulasan untuk pesanan milik toko sendiri.');
            }

            $review->seller_reply = mb_substr($trimmed, 0, 1000);
            $review->replied_at = now();
            $review->save();

            return $review->refresh();
        });
    }

    private function normalizeTime(string $time): string
    {
        $trimmed = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed.':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }

        return '08:00:00';
    }
}
