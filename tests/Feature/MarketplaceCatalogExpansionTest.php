<?php

namespace Tests\Feature;

use App\Exceptions\StoreClosedException;
use App\Models\CategoryAttribute;
use App\Models\OrderItem;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use App\Models\User;
use App\Services\CheckoutSplitOrderService;
use App\Services\ProductMerchandisingService;
use App\Services\StorefrontEngagementService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceCatalogExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_product_multi_photo_gallery_crud_and_primary_promotion(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $product = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();

        $initialCount = $product->images()->count();
        $this->assertGreaterThanOrEqual(2, $initialCount);

        // Add new photo marked as primary via HTTP endpoint
        $response = $this->actingAs($seller2)->post(route('seller.products.images.store', $product->id), [
            'image_url' => '/images/products/val-rank-proof.svg',
            'alt_text' => 'Screenshot Battlepass Baru',
            'is_primary' => true,
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $newPrimary = ProductImage::where('product_id', $product->id)
            ->where('alt_text', 'Screenshot Battlepass Baru')
            ->firstOrFail();
        $this->assertTrue($newPrimary->is_primary);
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('is_primary', true)->count());

        // Deleting current primary image must promote another remaining image to is_primary = true
        $deleteResp = $this->actingAs($seller2)->delete(route('seller.products.images.delete', [$product->id, $newPrimary->id]));
        $deleteResp->assertRedirect();
        $this->assertDatabaseMissing('product_images', ['id' => $newPrimary->id]);
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('is_primary', true)->count());

        // Cross-seller ownership guard
        $this->expectException(DomainException::class);
        app(ProductMerchandisingService::class)->addProductImage(
            productId: $product->id,
            sellerId: $seller1->id,
            imagePath: '/images/products/val-kuronami-locker.svg',
        );
    }

    public function test_dynamic_category_attributes_upsert_and_indexed_catalog_filtering(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $valProduct = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();
        $catId = $valProduct->game_category_id;

        $attrRank = CategoryAttribute::with('options')->where('game_category_id', $catId)->where('slug', 'rank-tertinggi')->firstOrFail();
        $attrSkins = CategoryAttribute::where('game_category_id', $catId)->where('slug', 'jumlah-skin-premium')->firstOrFail();
        $attrFe = CategoryAttribute::where('game_category_id', $catId)->where('slug', 'first-email')->firstOrFail();
        $radiantOpt = $attrRank->options->firstWhere('value_slug', 'radiant');

        // Upsert attributes via Seller endpoint
        $resp = $this->actingAs($seller2)->post(route('seller.products.attributes.sync', $valProduct->id), [
            'attributes' => [
                $attrRank->id => $radiantOpt->id,
                $attrSkins->id => 15,
                $attrFe->id => '1',
            ],
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('success');

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $valProduct->id,
            'category_attribute_id' => $attrRank->id,
            'attribute_option_id' => $radiantOpt->id,
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $valProduct->id,
            'category_attribute_id' => $attrSkins->id,
            'numeric_value' => 15,
        ]);

        // Matching indexed filter returns the product
        $matchResp = $this->get(route('marketplace.index', [
            'category' => $catId,
            'attr' => [
                'rank-tertinggi' => 'radiant',
                'jumlah-skin-premium' => 12,
                'first-email' => '1',
            ],
        ]));
        $matchResp->assertOk();
        $matchResp->assertSee($valProduct->title);

        // Non-matching numeric threshold filters it out
        $missResp = $this->get(route('marketplace.index', [
            'category' => $catId,
            'attr' => [
                'rank-tertinggi' => 'radiant',
                'jumlah-skin-premium' => 99,
            ],
        ]));
        $missResp->assertOk();
        $missResp->assertDontSee($valProduct->title);
    }

    public function test_auto_schedule_operating_hours_blocks_manual_trade_while_allowing_instant_vault_24_7(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();

        $manualProduct = Product::where('sku_code', 'MLBB-JOKI-MYTHIC')->firstOrFail();
        $instantProduct = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();

        $todayDow = (int) now()->dayOfWeek;
        $hoursPayload = [];
        for ($d = 0; $d <= 6; $d++) {
            $hoursPayload[$d] = [
                'open_time' => '09:00',
                'close_time' => '21:00',
                'is_closed' => $d === $todayDow ? '1' : '0',
            ];
        }

        // Seller enables auto-schedule and marks today closed
        $resp = $this->actingAs($seller2)->post(route('seller.settings.update'), [
            'store_name' => 'Sultan Vault Digital',
            'store_tagline' => 'Verified Store Updated',
            'store_bio' => 'Bio garansi anti hackback aktif.',
            'auto_schedule_enabled' => '1',
            'hours' => $hoursPayload,
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('success');

        $profile = SellerProfile::with('operatingHours')->where('user_id', $seller2->id)->firstOrFail();
        $this->assertTrue($profile->auto_schedule_enabled);
        $this->assertFalse($profile->isOpenForManualTradeNow());
        $this->assertSame('Bio garansi anti hackback aktif.', $profile->store_bio);
        $this->assertTrue((bool) StoreOperatingHour::where('seller_id', $seller2->id)->where('day_of_week', $todayDow)->value('is_closed'));

        // Instant Vault product from the exact same seller MUST STILL SUCCEED 24/7
        $checkoutService = app(CheckoutSplitOrderService::class);
        $instantInvoice = $checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'qris',
            explicitItems: [[
                'product_id' => $instantProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => [],
                'verified_game_nickname' => null,
            ]],
            clearCart: false,
        );
        $this->assertSame('PAID', $instantInvoice->status);

        // Manual Trade product from the exact same seller MUST THROW StoreClosedException
        $this->expectException(StoreClosedException::class);
        $checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'qris',
            explicitItems: [[
                'product_id' => $manualProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041', 'target_rank' => 'Mythic Honor'],
                'verified_game_nickname' => 'BagasPro',
            ]],
            clearCart: false,
        );
    }

    public function test_wishlist_and_store_follower_composite_key_toggles_and_self_follow_guard(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller1Profile = SellerProfile::where('user_id', $seller1->id)->firstOrFail();
        $product = Product::where('sku_code', 'STM-IDR-60K')->firstOrFail();

        // Ensure clean starting state for this buyer+product
        DB::table('wishlists')->where('user_id', $buyer->id)->where('product_id', $product->id)->delete();

        // Toggle ON wishlist
        $addWishlist = $this->actingAs($buyer)->postJson(route('wishlist.toggle', $product));
        $addWishlist->assertOk()->assertJson(['ok' => true, 'wishlisted' => true]);
        $this->assertDatabaseHas('wishlists', ['user_id' => $buyer->id, 'product_id' => $product->id]);

        // Visible in buyer wishlist page
        $wishlistPage = $this->actingAs($buyer)->get(route('buyer.wishlist'));
        $wishlistPage->assertOk()->assertSee($product->title);

        // Toggle OFF wishlist
        $removeWishlist = $this->actingAs($buyer)->postJson(route('wishlist.toggle', $product));
        $removeWishlist->assertOk()->assertJson(['ok' => true, 'wishlisted' => false]);
        $this->assertDatabaseMissing('wishlists', ['user_id' => $buyer->id, 'product_id' => $product->id]);

        // Store Follower toggle
        DB::table('store_followers')->where('user_id', $buyer->id)->where('seller_id', $seller1->id)->delete();

        $followOn = $this->actingAs($buyer)->postJson(route('stores.follow', $seller1Profile->slug));
        $followOn->assertOk()->assertJson(['ok' => true, 'following' => true]);
        $this->assertDatabaseHas('store_followers', ['user_id' => $buyer->id, 'seller_id' => $seller1->id]);

        $followOff = $this->actingAs($buyer)->postJson(route('stores.follow', $seller1Profile->slug));
        $followOff->assertOk()->assertJson(['ok' => true, 'following' => false]);
        $this->assertDatabaseMissing('store_followers', ['user_id' => $buyer->id, 'seller_id' => $seller1->id]);

        // Self-follow guard must reject 422
        $selfFollow = $this->actingAs($seller1)->postJson(route('stores.follow', $seller1Profile->slug));
        $selfFollow->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_product_soft_delete_preserves_order_history_blocks_checkout_and_restores_cleanly(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $product = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();

        // Confirm seeded historical order item references this product
        $historicalItem = OrderItem::where('product_id', $product->id)->first();
        $this->assertNotNull($historicalItem);

        // Soft-delete product via Seller archive endpoint
        $archiveResp = $this->actingAs($seller1)->delete(route('seller.products.archive', $product->id));
        $archiveResp->assertRedirect();
        $archiveResp->assertSessionHas('success');

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        // Hidden from public detail page (404)
        $this->get(route('marketplace.show', $product->slug))->assertNotFound();

        // Historical OrderItem still resolves soft-deleted product safely via withTrashed()
        $reloadedItem = OrderItem::with('product')->findOrFail($historicalItem->id);
        $this->assertNotNull($reloadedItem->product);
        $this->assertSame($product->sku_code, $reloadedItem->product->sku_code);

        // Checkout of archived product is rejected
        $checkoutBlocked = false;
        try {
            app(CheckoutSplitOrderService::class)->checkout(
                buyerId: $buyer->id,
                paymentMode: 'wallet_full',
                paymentChannel: 'qris',
                explicitItems: [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => [],
                    'verified_game_nickname' => null,
                ]],
                clearCart: false,
            );
        } catch (DomainException) {
            $checkoutBlocked = true;
        }
        $this->assertTrue($checkoutBlocked, 'Archived (soft-deleted) product must be blocked from checkout.');

        // Restore product cleanly
        $restoreResp = $this->actingAs($seller1)->post(route('seller.products.restore', $product->id));
        $restoreResp->assertRedirect();
        $restoreResp->assertSessionHas('success');

        $restored = Product::findOrFail($product->id);
        $this->assertNull($restored->deleted_at);
        $this->assertTrue($restored->is_active);
        $this->get(route('marketplace.show', $restored->slug))->assertOk();
    }

    public function test_seller_review_reply_authorization_and_persistence(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $review = OrderReview::where('seller_id', $seller1->id)->firstOrFail();

        $replyResp = $this->actingAs($seller1)->post(route('seller.reviews.reply', $review->id), [
            'seller_reply' => 'Terima kasih banyak kak, garansi voucher resmi 100% selalu aktif!',
        ]);
        $replyResp->assertRedirect();
        $replyResp->assertSessionHas('success');

        $review->refresh();
        $this->assertSame('Terima kasih banyak kak, garansi voucher resmi 100% selalu aktif!', $review->seller_reply);
        $this->assertNotNull($review->replied_at);

        // Product page displays seller reply
        $product = Product::withTrashed()->findOrFail($review->product_id);
        $this->get(route('marketplace.show', $product->slug))
            ->assertOk()
            ->assertSee('Terima kasih banyak kak, garansi voucher resmi 100% selalu aktif!');

        // Another seller cannot reply to seller1's review
        $this->expectException(DomainException::class);
        app(StorefrontEngagementService::class)->replyToReview($review->id, $seller2->id, 'Unauthorized hijack reply');
    }

    public function test_all_new_and_updated_light_mode_views_render_http_200(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $store = SellerProfile::where('user_id', $seller1->id)->firstOrFail();
        $product = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();

        $this->get(route('marketplace.index'))->assertOk();
        $this->get(route('marketplace.show', $product->slug))->assertOk();
        $this->get(route('stores.show', $store->slug))->assertOk()->assertSee($store->store_name);
        $this->actingAs($buyer)->get(route('buyer.wishlist'))->assertOk();
        $this->actingAs($seller1)->get(route('seller.products'))->assertOk();
        $this->actingAs($seller1)->get(route('seller.settings'))->assertOk()->assertSee('Jadwal Buka-Tutup Otomatis 7 Hari');
    }
}
