<?php

namespace Tests\Feature;

use App\Exceptions\StoreClosedException;
use App\Models\CategoryAttribute;
use App\Models\GameCategory;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\CheckoutSplitOrderService;
use App\Services\VaultEncryptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Fase1CommerceUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seller_creates_manual_trade_product_with_dynamic_attributes_and_uploaded_images(): void
    {
        Storage::fake('public');

        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $manualCat = GameCategory::with('attributes.options')
            ->where('delivery_method', 'manual_trade')
            ->where('slug', 'item-koin-in-game')
            ->firstOrFail();

        // JSON category attributes endpoint works cleanly for Alpine dynamic swap
        $this->actingAs($seller2)
            ->getJson(route('seller.categories.attributes', $manualCat))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('delivery_method', 'manual_trade');

        $methodAttr = $manualCat->attributes->firstWhere('slug', 'metode-drop');
        $dboxOption = $methodAttr->options->firstWhere('value_slug', 'donation-box');

        $resp = $this->actingAs($seller2)->post(route('seller.products.store'), [
            'game_category_id' => $manualCat->id,
            'title' => '10 Diamond Locks Growtopia — Drop Kilat Bergaransi',
            'description' => 'Drop langsung ke Donation Box world pembeli maksimal 10 menit sesuai SLA toko.',
            'price' => 45000,
            'stock' => 25,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'attributes' => [
                $methodAttr->id => $dboxOption->id,
            ],
            'images' => [
                UploadedFile::fake()->create('dl-proof-1.jpg', 120, 'image/jpeg'),
                UploadedFile::fake()->create('dl-proof-2.png', 140, 'image/png'),
            ],
        ]);

        $resp->assertRedirect(route('seller.products'));
        $resp->assertSessionHas('success');

        $created = Product::where('title', '10 Diamond Locks Growtopia — Drop Kilat Bergaransi')->firstOrFail();
        $this->assertSame(25, $created->stock);

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $created->id,
            'category_attribute_id' => $methodAttr->id,
            'attribute_option_id' => $dboxOption->id,
        ]);

        $images = ProductImage::where('product_id', $created->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);

        Storage::disk('public')->assertExists($images[0]->image_path);
        Storage::disk('public')->assertExists($images[1]->image_path);
    }

    public function test_instant_vault_product_enforces_vault_driven_stock_integrity_on_create_and_edit(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $vaultCat = GameCategory::with('attributes.options')
            ->where('delivery_method', 'instant_vault')
            ->where('slug', 'voucher-otomatis')
            ->firstOrFail();

        $regionAttr = $vaultCat->attributes->firstWhere('slug', 'region-voucher');
        $idrOption = $regionAttr->options->firstWhere('value_slug', 'indonesia-idr');

        // Attempt to forge stock = 999 during creation -> MUST be forced to 0
        $createResp = $this->actingAs($seller1)->post(route('seller.products.store'), [
            'game_category_id' => $vaultCat->id,
            'title' => 'Steam Wallet IDR 90.000 — Brankas Anti-Forge',
            'description' => 'Voucher Steam resmi terenkripsi AES-256 dengan validasi blind-index.',
            'price' => 94000,
            'stock' => 999,
            'sla_target_minutes' => 1,
            'attributes' => [
                $regionAttr->id => $idrOption->id,
            ],
        ]);
        $createResp->assertRedirect(route('seller.products'));

        $product = Product::where('title', 'Steam Wallet IDR 90.000 — Brankas Anti-Forge')->firstOrFail();
        $this->assertSame(0, $product->stock);

        // Ingest 2 real AES-256 vault lines -> stock automatically syncs to 2
        app(VaultEncryptionService::class)->bulkIngestVaultItems($product->id, $seller1->id, [
            'STM90-ANTI-FORGE-0001',
            'STM90-ANTI-FORGE-0002',
        ]);
        $this->assertSame(2, $product->fresh()->stock);

        // Attempt to forge stock = 999 during PUT edit -> MUST stay synchronized at 2
        $editResp = $this->actingAs($seller1)->put(route('seller.products.put', $product), [
            'title' => 'Steam Wallet IDR 90.000 — Brankas Verified',
            'description' => 'Voucher Steam resmi terenkripsi AES-256 dengan validasi blind-index.',
            'price' => 95000,
            'stock' => 999,
            'sla_target_minutes' => 1,
        ]);
        $editResp->assertRedirect(route('seller.products'));
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame('Steam Wallet IDR 90.000 — Brankas Verified', $product->fresh()->title);
    }

    public function test_dynamic_multi_attribute_catalog_filtering_returns_exact_matches_without_duplicates(): void
    {
        $valProduct = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();
        $catId = $valProduct->game_category_id;

        $attrRank = CategoryAttribute::with('options')->where('game_category_id', $catId)->where('slug', 'rank-tertinggi')->firstOrFail();
        $attrSkins = CategoryAttribute::where('game_category_id', $catId)->where('slug', 'jumlah-skin-premium')->firstOrFail();
        $attrFe = CategoryAttribute::where('game_category_id', $catId)->where('slug', 'first-email')->firstOrFail();
        $immortalOpt = $attrRank->options->firstWhere('value_slug', 'immortal');

        // Exact multi-attribute filter (select + number >= min + boolean)
        $matchResp = $this->get(route('marketplace.index', [
            'category' => $catId,
            'attrs' => [
                $attrRank->id => $immortalOpt->id,
                $attrSkins->id => 5,
                $attrFe->id => '1',
            ],
        ]));
        $matchResp->assertOk();
        $matchResp->assertSee($valProduct->title);
        $this->assertSame(1, $matchResp->viewData('products')->count());
        $this->assertSame($valProduct->id, $matchResp->viewData('products')->first()->id);

        // Higher numeric threshold excludes the product
        $missResp = $this->get(route('marketplace.index', [
            'category' => $catId,
            'attrs' => [
                $attrRank->id => $immortalOpt->id,
                $attrSkins->id => 50,
                $attrFe->id => '1',
            ],
        ]));
        $missResp->assertOk();
        $missResp->assertDontSee($valProduct->title);
    }

    public function test_archiving_product_with_historical_orders_succeeds_and_buyer_order_page_renders_http_200(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $genshinProduct = Product::where('sku_code', 'GI-ACC-AR58-FURINA')->firstOrFail();

        $historicalOrder = Order::where('buyer_id', $buyer2->id)
            ->where('status', 'COMPLETED')
            ->whereHas('items', fn ($q) => $q->where('product_id', $genshinProduct->id))
            ->firstOrFail();

        // Archive (soft-delete) product with completed order history
        $archiveResp = $this->actingAs($seller2)->delete(route('seller.products.archive', $genshinProduct));
        $archiveResp->assertRedirect()->assertSessionHas('success');
        $this->assertSoftDeleted('products', ['id' => $genshinProduct->id]);

        // Historical buyer order page MUST still render HTTP 200 without 500 error
        $this->actingAs($buyer2)
            ->get(route('buyer.orders.show', $historicalOrder->order_code))
            ->assertOk()
            ->assertSee($genshinProduct->title);
    }

    public function test_public_storefront_renders_profile_hours_products_reviews_and_blocks_cross_seller_review_reply_with_403(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $sultanStore = SellerProfile::where('user_id', $seller2->id)->firstOrFail();
        $seller2Review = OrderReview::where('seller_id', $seller2->id)->firstOrFail();

        // Storefront products & reviews tabs render cleanly
        $this->get(route('stores.toko', $sultanStore->slug))
            ->assertOk()
            ->assertSee($sultanStore->store_name)
            ->assertSee('Jadwal Operasional 7 Hari');

        $this->get(route('stores.toko', ['slug' => $sultanStore->slug, 'tab' => 'reviews']))
            ->assertOk()
            ->assertSee($seller2Review->comment);

        // Cross-seller reply MUST be rejected with HTTP 403
        $this->actingAs($seller1)
            ->post(route('seller.reviews.reply', $seller2Review->id), [
                'seller_reply' => 'Upaya balasan ilegal dari penjual lain.',
            ])
            ->assertForbidden();

        // Legitimate owner reply succeeds and displays on storefront reviews tab
        $this->actingAs($seller2)
            ->post(route('seller.reviews.reply', $seller2Review->id), [
                'seller_reply' => 'Terima kasih banyak kak Fajar, semoga gacha Furina hoki terus!',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->get(route('stores.toko', ['slug' => $sultanStore->slug, 'tab' => 'reviews']))
            ->assertOk()
            ->assertSee('Terima kasih banyak kak Fajar, semoga gacha Furina hoki terus!');
    }

    public function test_wishlist_and_store_follow_toggles_work_atomically_and_prevent_self_following(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $product = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();

        DB::table('wishlists')->where('user_id', $buyer1->id)->where('product_id', $product->id)->delete();

        $this->actingAs($buyer1)
            ->postJson(route('wishlist.toggle.alt', $product))
            ->assertOk()
            ->assertJson(['ok' => true, 'wishlisted' => true]);

        $this->actingAs($buyer1)
            ->get(route('buyer.wishlist'))
            ->assertOk()
            ->assertSee($product->title);

        $this->actingAs($buyer1)
            ->get(route('buyer.wishlist', ['tab' => 'following']))
            ->assertOk()
            ->assertSee('Juragan Voucher Official');

        // Store follow toggle using numeric seller_id
        DB::table('store_followers')->where('user_id', $buyer1->id)->where('seller_id', $seller1->id)->delete();

        $this->actingAs($buyer1)
            ->postJson(route('stores.follow', $seller1->id))
            ->assertOk()
            ->assertJson(['ok' => true, 'following' => true]);

        // Seller attempting to follow own store -> HTTP 422
        $this->actingAs($seller1)
            ->postJson(route('stores.follow', $seller1->id))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_store_operating_hours_auto_schedule_blocks_manual_trade_outside_hours_while_allowing_instant_vault_24_7(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $manualProduct = Product::where('sku_code', 'MLBB-JOKI-MYTHIC')->firstOrFail();
        $instantProduct = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();

        $todayJakartaDow = (int) now()->timezone('Asia/Jakarta')->dayOfWeek;
        $hours = [];
        for ($d = 0; $d <= 6; $d++) {
            $hours[$d] = [
                'open_time' => '08:00',
                'close_time' => '22:00',
                'is_closed' => $d === $todayJakartaDow ? '1' : '0',
            ];
        }

        // Enable auto_schedule_enabled via PUT /seller/settings
        $this->actingAs($seller2)->put(route('seller.settings.put'), [
            'store_name' => 'Sultan Akun Nusantara',
            'store_tagline' => 'Verified Store',
            'store_bio' => 'Auto schedule enabled test',
            'is_store_open' => '1',
            'auto_schedule_enabled' => '1',
            'hours' => $hours,
        ])->assertRedirect()->assertSessionHas('success');

        $profile = SellerProfile::with('operatingHours')->where('user_id', $seller2->id)->firstOrFail();
        $this->assertTrue($profile->auto_schedule_enabled);
        $this->assertFalse($profile->isCurrentlyOpen());

        // Instant Vault from the exact same seller SUCCEEDS 24/7
        $checkoutService = app(CheckoutSplitOrderService::class);
        $invoice = $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $instantProduct->id, 'quantity' => 1]],
            clearCart: false,
        );
        $this->assertSame('PAID', $invoice->status);

        // Manual Trade from the exact same seller THROWS StoreClosedException
        $this->expectException(StoreClosedException::class);
        $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'qris',
            explicitItems: [[
                'product_id' => $manualProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041', 'target_rank' => 'Mythic'],
                'verified_game_nickname' => 'BagasPro',
            ]],
            clearCart: false,
        );
    }
}
