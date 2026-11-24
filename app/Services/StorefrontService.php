<?php

namespace App\Services;

use App\Models\OrderReview;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StorefrontService
{
    public function __construct(
        private readonly ProductCatalogFilterService $catalogFilterService,
        private readonly SecureImageUploadService $imageUploadService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getPublicStoreData(string $storeSlug, array $filters = [], ?int $viewerId = null): array
    {
        $store = SellerProfile::with(['user', 'operatingHours'])
            ->withCount('followers')
            ->where('slug', $storeSlug)
            ->firstOrFail();

        $isFollowing = $viewerId !== null
            ? DB::table('store_followers')
                ->where('user_id', $viewerId)
                ->where('seller_id', $store->user_id)
                ->exists()
            : false;

        $wishlistedIds = $viewerId !== null
            ? DB::table('wishlists')
                ->where('user_id', $viewerId)
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];

        $productFilters = array_merge($filters, ['seller_id' => (int) $store->user_id]);
        $products = $this->catalogFilterService->paginateProducts($productFilters, 16);

        $reviews = OrderReview::with(['buyer', 'product', 'order'])
            ->where('seller_id', $store->user_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'reviews_page')
            ->withQueryString();

        $rawRatings = DB::table('order_reviews')
            ->selectRaw('rating, COUNT(*) as cnt')
            ->where('seller_id', $store->user_id)
            ->groupBy('rating')
            ->pluck('cnt', 'rating');

        $totalReviews = (int) $rawRatings->sum();
        $weightedSum = 0;
        $histogram = [];
        for ($star = 5; $star >= 1; $star--) {
            $count = (int) ($rawRatings->get($star) ?? 0);
            $weightedSum += $star * $count;
            $histogram[$star] = [
                'count' => $count,
                'percent' => $totalReviews > 0 ? round(($count / $totalReviews) * 100, 1) : 0.0,
            ];
        }

        $avgRating = $totalReviews > 0 ? round($weightedSum / $totalReviews, 2) : 5.00;

        return [
            'store' => $store,
            'isFollowing' => $isFollowing,
            'wishlistedIds' => $wishlistedIds,
            'products' => $products,
            'reviews' => $reviews,
            'ratingSummary' => [
                'average' => $avgRating,
                'total' => $totalReviews,
                'histogram' => $histogram,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<int, array{open_time?: string, close_time?: string, is_closed?: bool|int|string}>  $operatingHours
     */
    public function updateStoreSettings(
        int $sellerId,
        array $profileData,
        array $operatingHours,
        ?UploadedFile $bannerFile = null,
    ): SellerProfile {
        return DB::transaction(function () use ($sellerId, $profileData, $operatingHours, $bannerFile): SellerProfile {
            $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->firstOrFail();

            if (! empty($profileData['store_name'])) {
                $profile->store_name = mb_substr(trim((string) $profileData['store_name']), 0, 120);
            }
            if (array_key_exists('store_tagline', $profileData)) {
                $profile->store_tagline = $profileData['store_tagline'] !== null
                    ? mb_substr(trim((string) $profileData['store_tagline']), 0, 150)
                    : null;
            }
            if (array_key_exists('store_bio', $profileData)) {
                $profile->store_bio = $profileData['store_bio'] !== null
                    ? mb_substr(trim((string) $profileData['store_bio']), 0, 500)
                    : null;
            }
            if (array_key_exists('is_store_open', $profileData)) {
                $profile->is_store_open = (bool) $profileData['is_store_open'];
            }
            if (array_key_exists('auto_schedule_enabled', $profileData)) {
                $profile->auto_schedule_enabled = (bool) $profileData['auto_schedule_enabled'];
            }
            if (array_key_exists('is_on_vacation', $profileData)) {
                $profile->is_on_vacation = (bool) $profileData['is_on_vacation'];
            }
            if (array_key_exists('vacation_note', $profileData)) {
                $profile->vacation_note = $profileData['vacation_note'] !== null && trim((string) $profileData['vacation_note']) !== ''
                    ? mb_substr(trim((string) $profileData['vacation_note']), 0, 255)
                    : null;
            }
            if (array_key_exists('operating_hours_enabled', $profileData)) {
                $profile->operating_hours_enabled = (bool) $profileData['operating_hours_enabled'];
            }
            if (! empty($profileData['operating_timezone'])) {
                $profile->operating_timezone = mb_substr(trim((string) $profileData['operating_timezone']), 0, 40);
            }

            if ($bannerFile !== null) {
                $profile->store_banner_path = $this->imageUploadService->storeSanitizedImage(
                    $bannerFile,
                    'public',
                    "banners/{$sellerId}",
                    3072,
                );
            } elseif (! empty($profileData['store_banner_path'])) {
                $profile->store_banner_path = trim((string) $profileData['store_banner_path']);
            }

            $profile->save();

            for ($day = 0; $day <= 6; $day++) {
                if (! isset($operatingHours[$day])) {
                    continue;
                }

                $entry = $operatingHours[$day];
                StoreOperatingHour::updateOrCreate(
                    [
                        'seller_id' => $sellerId,
                        'day_of_week' => $day,
                    ],
                    [
                        'open_time' => $this->normalizeTime((string) ($entry['open_time'] ?? '08:00:00')),
                        'close_time' => $this->normalizeTime((string) ($entry['close_time'] ?? '22:00:00')),
                        'is_closed' => filter_var($entry['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    ],
                );
            }

            return $profile->refresh()->load('operatingHours');
        });
    }

    public function replyToReview(int $sellerId, int $reviewId, string $replyText): OrderReview
    {
        $trimmed = trim($replyText);
        if ($trimmed === '') {
            throw new DomainException('Isi balasan ulasan tidak boleh kosong.');
        }

        return DB::transaction(function () use ($sellerId, $reviewId, $trimmed): OrderReview {
            $review = OrderReview::where('id', $reviewId)->lockForUpdate()->first();
            if (! $review) {
                throw new DomainException("Ulasan #{$reviewId} tidak ditemukan.");
            }

            if ((int) $review->seller_id !== $sellerId) {
                throw new AuthorizationException('Anda tidak berwenang membalas ulasan milik toko lain.');
            }

            $review->seller_reply = mb_substr($trimmed, 0, 1000);
            $review->replied_at = now();
            $review->save();

            return $review->refresh()->load(['buyer', 'product', 'order']);
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
