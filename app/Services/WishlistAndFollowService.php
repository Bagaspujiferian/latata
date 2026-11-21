<?php

namespace App\Services;

use App\Exceptions\SelfDealingException;
use App\Models\Product;
use App\Models\SellerProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

class WishlistAndFollowService
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
            throw new SelfDealingException('Anda tidak dapat mengikuti toko milik sendiri.');
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
}
