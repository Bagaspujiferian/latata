<?php

namespace Database\Seeders;

use App\Models\AttributeOption;
use App\Models\CartItem;
use App\Models\CategoryAttribute;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\SellerKycVerification;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BankAccountService;
use App\Services\ChatModerationService;
use App\Services\CheckoutSplitOrderService;
use App\Services\ConversationService;
use App\Services\EscrowLedgerService;
use App\Services\NotificationService;
use App\Services\OrderStateAndSlaService;
use App\Services\SellerKycService;
use App\Services\VaultEncryptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $ledgerService = app(EscrowLedgerService::class);
        $vaultService = app(VaultEncryptionService::class);
        $checkoutService = app(CheckoutSplitOrderService::class);
        $orderStateService = app(OrderStateAndSlaService::class);
        $chatService = app(ChatModerationService::class);
        $bankAccountService = app(BankAccountService::class);
        $conversationService = app(ConversationService::class);
        $kycService = app(SellerKycService::class);
        $notificationService = app(NotificationService::class);

        // =====================================================================
        // 1. USERS, WALLETS, SELLER PROFILES & 7-DAY OPERATING HOURS
        // =====================================================================
        $admin = User::create([
            'name' => 'Arif Wijaya (Admin & Chaos Engineer)',
            'email' => 'admin@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $seller1 = User::create([
            'name' => 'Raka Pratama',
            'email' => 'juragan@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'seller',
            'is_active' => true,
        ]);

        $seller2 = User::create([
            'name' => 'Dimas Mahendra',
            'email' => 'sultan@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'seller',
            'is_active' => true,
        ]);

        $buyer1 = User::create([
            'name' => 'Bagas Ferian',
            'email' => 'bagas@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);

        $buyer2 = User::create([
            'name' => 'Fajar ProPlayer',
            'email' => 'proplayer@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);

        foreach ([$admin, $seller1, $seller2, $buyer1, $buyer2] as $u) {
            Wallet::create([
                'user_id' => $u->id,
                'pending_balance' => '0.00',
                'available_balance' => '0.00',
                'updated_at' => now(),
            ]);
        }

        SellerProfile::create([
            'user_id' => $seller1->id,
            'store_name' => 'Juragan Voucher Official',
            'slug' => 'juragan-voucher-official',
            'store_tagline' => 'Distributor Resmi Voucher AES-256 & Top-Up H2H 24 Jam Nonstop',
            'store_banner_path' => '/images/stores/juragan-banner.svg',
            'store_bio' => 'Merchant resmi NexaTrade sejak 2023. Seluruh voucher Steam IDR dan top-up Digiflazz H2H diproses otomatis 24 jam nonstop dengan rata-rata pengiriman di bawah 4 menit.',
            'store_level' => 7,
            'total_completed_orders' => 1482,
            'total_cancelled_orders' => 4,
            'avg_delivery_minutes' => '3.20',
            'is_sla_10m_badge' => true,
            'is_store_open' => true,
            'auto_schedule_enabled' => false,
            'ad_credits' => 18,
        ]);

        SellerProfile::create([
            'user_id' => $seller2->id,
            'store_name' => 'Sultan Akun Nusantara',
            'slug' => 'sultan-akun-nusantara',
            'store_tagline' => 'Kurator Akun High-Tier, Growtopia BGL & Joki Mythic Bergaransi',
            'store_banner_path' => '/images/stores/sultan-banner.svg',
            'store_bio' => 'Spesialis akun Valorant Immortal/Radiant, Genshin Impact AR 58+ First Email, drop Blue Gem Lock Growtopia bebas rollback, dan jasa joki MLBB oleh roster eks-MDL.',
            'store_level' => 5,
            'total_completed_orders' => 418,
            'total_cancelled_orders' => 19,
            'avg_delivery_minutes' => '14.50',
            'is_sla_10m_badge' => false,
            'is_store_open' => true,
            'auto_schedule_enabled' => false,
            'ad_credits' => 12,
        ]);

        // Seed 7-day store_operating_hours (0 = Minggu .. 6 = Sabtu) for both stores
        for ($day = 0; $day <= 6; $day++) {
            StoreOperatingHour::create([
                'seller_id' => $seller1->id,
                'day_of_week' => $day,
                'open_time' => '00:00:00',
                'close_time' => '23:59:00',
                'is_closed' => false,
            ]);

            StoreOperatingHour::create([
                'seller_id' => $seller2->id,
                'day_of_week' => $day,
                'open_time' => $day === 0 ? '10:00:00' : '08:00:00',
                'close_time' => '22:00:00',
                'is_closed' => false,
            ]);
        }

        // Initial wallet funding via immutable double-entry ledger (final buyer balances hit exact Rp 750.000 & Rp 350.000)
        $ledgerService->adminDemoTopup($buyer1->id, 1153000.00, 'SEED-TOPUP-BUYER1', 'Saldo awal demo Buyer 1 (Bagas Ferian)');
        $ledgerService->adminDemoTopup($buyer2->id, 903000.00, 'SEED-TOPUP-BUYER2', 'Saldo awal demo Buyer 2 (Fajar ProPlayer)');
        $ledgerService->adminDemoTopup($seller1->id, 250000.00, 'SEED-FLOAT-SELLER1', 'Saldo operasional awal Juragan Voucher Official');
        $ledgerService->adminDemoTopup($seller2->id, 120000.00, 'SEED-FLOAT-SELLER2', 'Saldo operasional awal Sultan Akun Nusantara');

        // =====================================================================
        // 2. 5 GAMES, CATEGORIES & DYNAMIC CATEGORY ATTRIBUTES / OPTIONS
        // =====================================================================
        $mlbb = Game::create([
            'name' => 'Mobile Legends: Bang Bang',
            'slug' => 'mobile-legends',
            'publisher' => 'Moonton',
            'thumbnail_url' => '/images/games/mobile-legends.svg',
            'requires_nickname_check' => true,
            'nickname_provider_code' => 'MLBB_ID',
            'is_popular' => true,
            'is_active' => true,
        ]);

        $valorant = Game::create([
            'name' => 'Valorant',
            'slug' => 'valorant',
            'publisher' => 'Riot Games',
            'thumbnail_url' => '/images/games/valorant.svg',
            'requires_nickname_check' => true,
            'nickname_provider_code' => 'RIOT_APAC',
            'is_popular' => true,
            'is_active' => true,
        ]);

        $steam = Game::create([
            'name' => 'Steam Wallet IDR',
            'slug' => 'steam-wallet',
            'publisher' => 'Valve Corporation',
            'thumbnail_url' => '/images/games/steam-wallet.svg',
            'requires_nickname_check' => false,
            'nickname_provider_code' => null,
            'is_popular' => true,
            'is_active' => true,
        ]);

        $growtopia = Game::create([
            'name' => 'Growtopia',
            'slug' => 'growtopia',
            'publisher' => 'Ubisoft',
            'thumbnail_url' => '/images/games/growtopia.svg',
            'requires_nickname_check' => true,
            'nickname_provider_code' => 'GT_WORLD',
            'is_popular' => true,
            'is_active' => true,
        ]);

        $genshin = Game::create([
            'name' => 'Genshin Impact',
            'slug' => 'genshin-impact',
            'publisher' => 'HoYoverse',
            'thumbnail_url' => '/images/games/genshin-impact.svg',
            'requires_nickname_check' => true,
            'nickname_provider_code' => 'HOYO_UID',
            'is_popular' => true,
            'is_active' => true,
        ]);

        $catMlbbH2h = GameCategory::create([
            'game_id' => $mlbb->id,
            'name' => 'Top Up Instan H2H',
            'slug' => 'top-up-instan-h2h',
            'delivery_method' => 'api_h2h',
            'platform_fee_percent' => '3.50',
            'required_inputs_schema' => [
                ['key' => 'user_id', 'label' => 'User ID MLBB', 'type' => 'number', 'required' => true, 'placeholder' => 'Contoh: 123456789'],
                ['key' => 'zone_id', 'label' => 'Zone ID (Server)', 'type' => 'number', 'required' => true, 'placeholder' => 'Contoh: 2041'],
            ],
        ]);

        $catMlbbJoki = GameCategory::create([
            'game_id' => $mlbb->id,
            'name' => 'Jasa Joki Rank',
            'slug' => 'jasa-joki-rank',
            'delivery_method' => 'manual_trade',
            'platform_fee_percent' => '6.00',
            'required_inputs_schema' => [
                ['key' => 'user_id', 'label' => 'User ID MLBB', 'type' => 'number', 'required' => true, 'placeholder' => 'Contoh: 123456789'],
                ['key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'number', 'required' => true, 'placeholder' => 'Contoh: 2041'],
                ['key' => 'target_rank', 'label' => 'Target Rank & Catatan Hero', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: Mythic Honor (Req Ling/Fanny)'],
            ],
        ]);

        $catValH2h = GameCategory::create([
            'game_id' => $valorant->id,
            'name' => 'Top Up Instan H2H',
            'slug' => 'top-up-instan-h2h',
            'delivery_method' => 'api_h2h',
            'platform_fee_percent' => '4.00',
            'required_inputs_schema' => [
                ['key' => 'riot_id', 'label' => 'Riot ID + Tagline', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: JettMaster#JKT1'],
            ],
        ]);

        $catValVault = GameCategory::create([
            'game_id' => $valorant->id,
            'name' => 'Akun Sultan Instan',
            'slug' => 'akun-sultan-instan',
            'delivery_method' => 'instant_vault',
            'platform_fee_percent' => '5.00',
            'required_inputs_schema' => [],
        ]);

        $catSteamVault = GameCategory::create([
            'game_id' => $steam->id,
            'name' => 'Voucher Otomatis',
            'slug' => 'voucher-otomatis',
            'delivery_method' => 'instant_vault',
            'platform_fee_percent' => '4.00',
            'required_inputs_schema' => [],
        ]);

        $catGtManual = GameCategory::create([
            'game_id' => $growtopia->id,
            'name' => 'Item & Koin In-Game',
            'slug' => 'item-koin-in-game',
            'delivery_method' => 'manual_trade',
            'platform_fee_percent' => '5.00',
            'required_inputs_schema' => [
                ['key' => 'grow_id', 'label' => 'GrowID Pemilik World', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: BagasGT99'],
                ['key' => 'world_name', 'label' => 'Nama World (Wajib Donation Box)', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: BAGASDROP88'],
            ],
        ]);

        $catGenshinH2h = GameCategory::create([
            'game_id' => $genshin->id,
            'name' => 'Top Up Instan H2H',
            'slug' => 'top-up-instan-h2h',
            'delivery_method' => 'api_h2h',
            'platform_fee_percent' => '4.00',
            'required_inputs_schema' => [
                ['key' => 'uid', 'label' => 'UID Genshin Impact', 'type' => 'number', 'required' => true, 'placeholder' => 'Contoh: 812345678'],
                ['key' => 'server', 'label' => 'Region Server', 'type' => 'text', 'required' => true, 'placeholder' => 'Asia / America / Europe'],
            ],
        ]);

        $catGenshinVault = GameCategory::create([
            'game_id' => $genshin->id,
            'name' => 'Akun Sultan Instan',
            'slug' => 'akun-sultan-instan',
            'delivery_method' => 'instant_vault',
            'platform_fee_percent' => '5.00',
            'required_inputs_schema' => [],
        ]);

        // Dynamic Category Attributes & Controlled Options
        $attrValRank = CategoryAttribute::create([
            'game_category_id' => $catValVault->id,
            'name' => 'Rank Tertinggi',
            'slug' => 'rank-tertinggi',
            'input_type' => 'select',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 1,
        ]);
        $optValRadiant = AttributeOption::create(['category_attribute_id' => $attrValRank->id, 'label' => 'Radiant', 'value_slug' => 'radiant', 'sort_order' => 1]);
        $optValImmortal = AttributeOption::create(['category_attribute_id' => $attrValRank->id, 'label' => 'Immortal', 'value_slug' => 'immortal', 'sort_order' => 2]);
        AttributeOption::create(['category_attribute_id' => $attrValRank->id, 'label' => 'Ascendant', 'value_slug' => 'ascendant', 'sort_order' => 3]);
        AttributeOption::create(['category_attribute_id' => $attrValRank->id, 'label' => 'Diamond', 'value_slug' => 'diamond', 'sort_order' => 4]);

        $attrValSkins = CategoryAttribute::create([
            'game_category_id' => $catValVault->id,
            'name' => 'Jumlah Skin Premium',
            'slug' => 'jumlah-skin-premium',
            'input_type' => 'number',
            'unit_label' => 'Skin',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 2,
        ]);
        $attrValFe = CategoryAttribute::create([
            'game_category_id' => $catValVault->id,
            'name' => 'First Email (FE)',
            'slug' => 'first-email',
            'input_type' => 'boolean',
            'is_required' => false,
            'is_filterable' => true,
            'sort_order' => 3,
        ]);
        $attrValRegion = CategoryAttribute::create([
            'game_category_id' => $catValVault->id,
            'name' => 'Region Server',
            'slug' => 'region-server',
            'input_type' => 'select',
            'is_required' => false,
            'is_filterable' => true,
            'sort_order' => 4,
        ]);
        $optValApac = AttributeOption::create(['category_attribute_id' => $attrValRegion->id, 'label' => 'APAC (Indonesia/SEA)', 'value_slug' => 'apac', 'sort_order' => 1]);
        AttributeOption::create(['category_attribute_id' => $attrValRegion->id, 'label' => 'North America (NA)', 'value_slug' => 'na', 'sort_order' => 2]);
        AttributeOption::create(['category_attribute_id' => $attrValRegion->id, 'label' => 'Europe (EU)', 'value_slug' => 'eu', 'sort_order' => 3]);

        $attrGiAr = CategoryAttribute::create([
            'game_category_id' => $catGenshinVault->id,
            'name' => 'Adventure Rank',
            'slug' => 'adventure-rank',
            'input_type' => 'number',
            'unit_label' => 'AR',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 1,
        ]);
        $attrGiB5 = CategoryAttribute::create([
            'game_category_id' => $catGenshinVault->id,
            'name' => 'Jumlah Karakter B5',
            'slug' => 'jumlah-karakter-b5',
            'input_type' => 'number',
            'unit_label' => 'Karakter',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 2,
        ]);
        $attrGiServer = CategoryAttribute::create([
            'game_category_id' => $catGenshinVault->id,
            'name' => 'Server Region',
            'slug' => 'server-region',
            'input_type' => 'select',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 3,
        ]);
        $optGiAsia = AttributeOption::create(['category_attribute_id' => $attrGiServer->id, 'label' => 'Asia', 'value_slug' => 'asia', 'sort_order' => 1]);
        AttributeOption::create(['category_attribute_id' => $attrGiServer->id, 'label' => 'America', 'value_slug' => 'america', 'sort_order' => 2]);
        AttributeOption::create(['category_attribute_id' => $attrGiServer->id, 'label' => 'Europe', 'value_slug' => 'europe', 'sort_order' => 3]);

        $attrGiUnset = CategoryAttribute::create([
            'game_category_id' => $catGenshinVault->id,
            'name' => 'Username & Birthday Unset',
            'slug' => 'unset-all',
            'input_type' => 'boolean',
            'is_required' => false,
            'is_filterable' => true,
            'sort_order' => 4,
        ]);

        $attrSteamRegion = CategoryAttribute::create([
            'game_category_id' => $catSteamVault->id,
            'name' => 'Region Voucher',
            'slug' => 'region-voucher',
            'input_type' => 'select',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 1,
        ]);
        $optSteamIdr = AttributeOption::create(['category_attribute_id' => $attrSteamRegion->id, 'label' => 'Indonesia (IDR)', 'value_slug' => 'indonesia-idr', 'sort_order' => 1]);
        AttributeOption::create(['category_attribute_id' => $attrSteamRegion->id, 'label' => 'Global (USD)', 'value_slug' => 'global-usd', 'sort_order' => 2]);

        $attrSteamNominal = CategoryAttribute::create([
            'game_category_id' => $catSteamVault->id,
            'name' => 'Nominal Saldo',
            'slug' => 'nominal-saldo',
            'input_type' => 'number',
            'unit_label' => 'IDR',
            'is_required' => false,
            'is_filterable' => true,
            'sort_order' => 2,
        ]);

        $attrGtMethod = CategoryAttribute::create([
            'game_category_id' => $catGtManual->id,
            'name' => 'Metode Drop',
            'slug' => 'metode-drop',
            'input_type' => 'select',
            'is_required' => true,
            'is_filterable' => true,
            'sort_order' => 1,
        ]);
        $optGtDbox = AttributeOption::create(['category_attribute_id' => $attrGtMethod->id, 'label' => 'Donation Box / Display Box', 'value_slug' => 'donation-box', 'sort_order' => 1]);
        AttributeOption::create(['category_attribute_id' => $attrGtMethod->id, 'label' => 'Direct Trade In-Game', 'value_slug' => 'direct-trade', 'sort_order' => 2]);

        $attrJokiWr = CategoryAttribute::create([
            'game_category_id' => $catMlbbJoki->id,
            'name' => 'Garansi Winrate',
            'slug' => 'garansi-winrate',
            'input_type' => 'number',
            'unit_label' => '%',
            'is_required' => false,
            'is_filterable' => true,
            'sort_order' => 1,
        ]);

        // =====================================================================
        // 3. 10 PRODUCTS + MULTI-PHOTO GALLERIES + TYPED ATTRIBUTES + 24 VAULTS
        // =====================================================================
        $pSteam120 = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $catSteamVault->id,
            'sku_code' => 'STM-IDR-120K',
            'title' => 'Steam Wallet Code IDR 120.000 — Brankas Instan 0 Detik',
            'slug' => 'steam-wallet-code-idr-120000-instan',
            'description' => 'Kode voucher resmi Steam Wallet Region Indonesia nominal Rp 120.000. Disimpan terenkripsi AES-256 di Brankas NexaTrade dan langsung terbuka otomatis begitu pembayaran terverifikasi.',
            'price' => '125000.00',
            'stock' => 0,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 1,
            'sold_count' => 318,
            'rating_avg' => '4.98',
            'is_featured' => true,
            'last_bumped_at' => now()->subMinutes(10),
            'is_active' => true,
        ]);

        $pSteam60 = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $catSteamVault->id,
            'sku_code' => 'STM-IDR-60K',
            'title' => 'Steam Wallet Code IDR 60.000 — Auto Decrypt 24/7',
            'slug' => 'steam-wallet-code-idr-60000-auto',
            'description' => 'Voucher Steam IDR 60.000 legal 100% tanpa hold. Dilengkapi deduplikasi HMAC-SHA256 sehingga setiap kode dijamin eksklusif dan belum pernah terpakai.',
            'price' => '63000.00',
            'stock' => 0,
            'min_order' => 1,
            'max_order' => 10,
            'sla_target_minutes' => 1,
            'sold_count' => 542,
            'rating_avg' => '4.96',
            'is_featured' => true,
            'last_bumped_at' => now()->subMinutes(22),
            'is_active' => true,
        ]);

        $pValAccount = Product::create([
            'seller_id' => $seller2->id,
            'game_category_id' => $catValVault->id,
            'sku_code' => 'VAL-ACC-IMMORTAL',
            'title' => 'Akun Valorant Immortal 2 • Kuronami Vandal + Reaver Karambit (Full Access)',
            'slug' => 'akun-valorant-immortal-kuronami-reaver',
            'description' => 'Akun Valorant APAC Indonesia Rank Immortal 2, email pertama siap ganti, 9 premium skin termasuk Kuronami Bundle & Reaver Karambit. Kredensial terenkripsi AES-256 dikirim otomatis via brankas.',
            'price' => '485000.00',
            'stock' => 0,
            'min_order' => 1,
            'max_order' => 1,
            'sla_target_minutes' => 2,
            'sold_count' => 27,
            'rating_avg' => '4.85',
            'is_featured' => true,
            'last_bumped_at' => now()->subMinutes(35),
            'is_active' => true,
        ]);

        $pGenshinAccount = Product::create([
            'seller_id' => $seller2->id,
            'game_category_id' => $catGenshinVault->id,
            'sku_code' => 'GI-ACC-AR58-FURINA',
            'title' => 'Akun Genshin Impact AR 58 Asia • Furina C2 + Neuvillette Sign (No Minus)',
            'slug' => 'akun-genshin-ar58-furina-c2-neuvillette',
            'description' => 'Akun Genshin Impact Server Asia AR 58, unset username, birthday unset, 14 karakter B5 terawat siap Spiral Abyss 36 stars. Pengiriman kredensial instan dari Brankas Terenkripsi.',
            'price' => '320000.00',
            'stock' => 0,
            'min_order' => 1,
            'max_order' => 1,
            'sla_target_minutes' => 2,
            'sold_count' => 19,
            'rating_avg' => '4.90',
            'is_featured' => false,
            'last_bumped_at' => now()->subHours(1),
            'is_active' => true,
        ]);

        $pMlbb257 = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $catMlbbH2h->id,
            'sku_code' => 'MLBB-DM-257',
            'title' => '257 Diamonds Mobile Legends (234 + 23 Bonus) • Top Up H2H Kilat',
            'slug' => '257-diamonds-mobile-legends-h2h-kilat',
            'description' => 'Top Up resmi Moonton jalur API H2H Digiflazz. Cukup masukkan User ID & Zone ID lalu klik Cek Nickname. Gagal server = auto-refund 100% ke dompet tanpa tunggu admin.',
            'price' => '68000.00',
            'stock' => 250,
            'min_order' => 1,
            'max_order' => 10,
            'sla_target_minutes' => 5,
            'h2h_provider_sku' => 'ML257',
            'sold_count' => 924,
            'rating_avg' => '4.99',
            'is_featured' => true,
            'last_bumped_at' => now()->subMinutes(5),
            'is_active' => true,
        ]);

        $pMlbbWdp = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $catMlbbH2h->id,
            'sku_code' => 'MLBB-WDP-PASS',
            'title' => 'Weekly Diamond Pass MLBB (Total 220 DM + Starlight Score) H2H',
            'slug' => 'weekly-diamond-pass-mlbb-h2h',
            'description' => 'Weekly Diamond Pass resmi masuk dalam hitungan detik melalui jalur Digiflazz H2H. Mendukung stack hingga 10 minggu berturut-turut.',
            'price' => '28500.00',
            'stock' => 400,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 5,
            'h2h_provider_sku' => 'MLWDP',
            'sold_count' => 1210,
            'rating_avg' => '4.97',
            'is_featured' => false,
            'last_bumped_at' => now()->subMinutes(40),
            'is_active' => true,
        ]);

        $pVal1375 = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $catValH2h->id,
            'sku_code' => 'VAL-VP-1375',
            'title' => '1375 Valorant Points (VP) APAC Resmi — Jalur API H2H',
            'slug' => '1375-valorant-points-apac-h2h',
            'description' => 'Top Up 1375 VP langsung masuk ke akun Riot ID APAC Anda tanpa perlu login password. Dilindungi garansi SLA 5 menit & otomatis refund jika transaksi gagal.',
            'price' => '149000.00',
            'stock' => 180,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 5,
            'h2h_provider_sku' => 'VAL1375',
            'sold_count' => 412,
            'rating_avg' => '4.95',
            'is_featured' => false,
            'last_bumped_at' => now()->subHours(2),
            'is_active' => true,
        ]);

        $pGenshin980 = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $catGenshinH2h->id,
            'sku_code' => 'GI-GC-980',
            'title' => '980 + 110 Genesis Crystals Genshin Impact — UID Instant H2H',
            'slug' => '980-genesis-crystals-genshin-h2h',
            'description' => 'Pengisian Genesis Crystals resmi via UID HoYoverse (Asia/America/Europe). Bonus double top-up pertama tetap aktif mengikuti status akun Anda.',
            'price' => '198000.00',
            'stock' => 150,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 5,
            'h2h_provider_sku' => 'GI980',
            'sold_count' => 276,
            'rating_avg' => '4.94',
            'is_featured' => false,
            'last_bumped_at' => now()->subHours(3),
            'is_active' => true,
        ]);

        $pGrowtopiaBgl = Product::create([
            'seller_id' => $seller2->id,
            'game_category_id' => $catGtManual->id,
            'sku_code' => 'GT-BGL-05',
            'title' => '5 Blue Gem Locks (BGL) Growtopia • Drop Donation Box Garansi SLA 10m',
            'slug' => '5-blue-gem-locks-growtopia-drop-kilat',
            'description' => 'Pengiriman 5 BGL bersih bebas rollback langsung ke Donation Box atau Display Box di World Anda. Wajib cantumkan GrowID & Nama World yang benar. Lewat SLA 10 menit = tombol refund instan aktif.',
            'price' => '215000.00',
            'stock' => 35,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'sold_count' => 184,
            'rating_avg' => '4.88',
            'is_featured' => false,
            'last_bumped_at' => now()->subMinutes(18),
            'is_active' => true,
        ]);

        $pMlbbJoki = Product::create([
            'seller_id' => $seller2->id,
            'game_category_id' => $catMlbbJoki->id,
            'sku_code' => 'MLBB-JOKI-MYTHIC',
            'title' => 'Paket Joki MLBB Epic V ke Mythic Grading • Pro Roster WR 85%+',
            'slug' => 'paket-joki-mlbb-epic-ke-mythic-pro',
            'description' => 'Dikerjakan manual oleh eks-pemain MDL Indonesia tanpa cheat/script. Proses transparan via chat terpantau anti-bypass dan garansi escrow penuh hingga rank tercapai.',
            'price' => '145000.00',
            'stock' => 20,
            'min_order' => 1,
            'max_order' => 2,
            'sla_target_minutes' => 30,
            'sold_count' => 93,
            'rating_avg' => '4.91',
            'is_featured' => false,
            'last_bumped_at' => now()->subHours(4),
            'is_active' => true,
        ]);

        // Seed Multi-Photo Galleries (product_images — 3 editorial screenshots per Account product)
        ProductImage::create(['product_id' => $pValAccount->id, 'image_path' => '/images/screenshots/val-rank-card.svg', 'alt_text' => 'Profil & Peak Rank Immortal 2 Valorant APAC', 'sort_order' => 0, 'is_primary' => true]);
        ProductImage::create(['product_id' => $pValAccount->id, 'image_path' => '/images/screenshots/val-skin-collection.svg', 'alt_text' => 'Koleksi Skin Kuronami Vandal & Reaver Karambit', 'sort_order' => 1, 'is_primary' => false]);
        ProductImage::create(['product_id' => $pValAccount->id, 'image_path' => '/images/screenshots/val-bind-verified.svg', 'alt_text' => 'Verifikasi First Email & Keamanan Akun Riot', 'sort_order' => 2, 'is_primary' => false]);

        ProductImage::create(['product_id' => $pGenshinAccount->id, 'image_path' => '/images/screenshots/gi-ar58-profile.svg', 'alt_text' => 'Profil Genshin Impact AR 58 Asia Spiral Abyss 36*', 'sort_order' => 0, 'is_primary' => true]);
        ProductImage::create(['product_id' => $pGenshinAccount->id, 'image_path' => '/images/screenshots/gi-char-collection.svg', 'alt_text' => 'Roster Karakter B5 Furina C2 + Neuvillette Sign', 'sort_order' => 1, 'is_primary' => false]);
        ProductImage::create(['product_id' => $pGenshinAccount->id, 'image_path' => '/images/screenshots/gi-bind-unset.svg', 'alt_text' => 'Audit Hoyoverse Clean Unset Username & Birthday', 'sort_order' => 2, 'is_primary' => false]);

        ProductImage::create(['product_id' => $pSteam120->id, 'image_path' => '/images/products/steam-voucher-card.svg', 'alt_text' => 'Kartu Digital Steam Wallet IDR 120.000', 'sort_order' => 0, 'is_primary' => true]);
        ProductImage::create(['product_id' => $pSteam120->id, 'image_path' => '/images/games/steam-wallet.svg', 'alt_text' => 'Brankas AES-256 Steam Wallet', 'sort_order' => 1, 'is_primary' => false]);

        // Seed Typed Product Attribute Values (product_attribute_values)
        ProductAttributeValue::create(['product_id' => $pValAccount->id, 'category_attribute_id' => $attrValRank->id, 'attribute_option_id' => $optValImmortal->id]);
        ProductAttributeValue::create(['product_id' => $pValAccount->id, 'category_attribute_id' => $attrValSkins->id, 'numeric_value' => 9]);
        ProductAttributeValue::create(['product_id' => $pValAccount->id, 'category_attribute_id' => $attrValFe->id, 'boolean_value' => true]);
        ProductAttributeValue::create(['product_id' => $pValAccount->id, 'category_attribute_id' => $attrValRegion->id, 'attribute_option_id' => $optValApac->id]);

        ProductAttributeValue::create(['product_id' => $pGenshinAccount->id, 'category_attribute_id' => $attrGiAr->id, 'numeric_value' => 58]);
        ProductAttributeValue::create(['product_id' => $pGenshinAccount->id, 'category_attribute_id' => $attrGiB5->id, 'numeric_value' => 14]);
        ProductAttributeValue::create(['product_id' => $pGenshinAccount->id, 'category_attribute_id' => $attrGiServer->id, 'attribute_option_id' => $optGiAsia->id]);
        ProductAttributeValue::create(['product_id' => $pGenshinAccount->id, 'category_attribute_id' => $attrGiUnset->id, 'boolean_value' => true]);

        ProductAttributeValue::create(['product_id' => $pSteam120->id, 'category_attribute_id' => $attrSteamRegion->id, 'attribute_option_id' => $optSteamIdr->id]);
        ProductAttributeValue::create(['product_id' => $pSteam120->id, 'category_attribute_id' => $attrSteamNominal->id, 'numeric_value' => 120000]);

        ProductAttributeValue::create(['product_id' => $pSteam60->id, 'category_attribute_id' => $attrSteamRegion->id, 'attribute_option_id' => $optSteamIdr->id]);
        ProductAttributeValue::create(['product_id' => $pSteam60->id, 'category_attribute_id' => $attrSteamNominal->id, 'numeric_value' => 60000]);

        ProductAttributeValue::create(['product_id' => $pGrowtopiaBgl->id, 'category_attribute_id' => $attrGtMethod->id, 'attribute_option_id' => $optGtDbox->id]);
        ProductAttributeValue::create(['product_id' => $pMlbbJoki->id, 'category_attribute_id' => $attrJokiWr->id, 'numeric_value' => 85]);

        // Bulk ingest 24 real AES-256 encrypted voucher codes & accounts
        $vaultService->bulkIngestVaultItems($pSteam120->id, $seller1->id, [
            'STM120-NX9K-4421-QWER-881A',
            'STM120-NX9K-7732-TYUI-992B',
            'STM120-NX9K-1184-OPAS-334C',
            'STM120-NX9K-6659-DFGH-445D',
            'STM120-NX9K-9043-JKLM-556E',
            'STM120-NX9K-3278-ZXCV-667F',
            'STM120-NX9K-8410-BNMQ-778G',
        ]);

        $vaultService->bulkIngestVaultItems($pSteam60->id, $seller1->id, [
            'STM060-JR7P-2210-ABCD-101X',
            'STM060-JR7P-3321-EFGH-202Y',
            'STM060-JR7P-4432-IJKL-303Z',
            'STM060-JR7P-5543-MNOP-404W',
            'STM060-JR7P-6654-QRST-505V',
            'STM060-JR7P-7765-UVWX-606U',
        ]);

        $vaultService->bulkIngestVaultItems($pValAccount->id, $seller2->id, [
            'immortal.kuronami01@nexavault.id:KuroReaver#2026 | RiotTag: ShadowViper#ID01 | Recovery: OK',
            'immortal.kuronami02@nexavault.id:ApexPhantom#7788 | RiotTag: KaitoJett#ID02 | Recovery: OK',
            'immortal.kuronami03@nexavault.id:SovereignAce#9921 | RiotTag: ReynaClutch#ID03 | Recovery: OK',
            'immortal.kuronami04@nexavault.id:PreludeChaos#4410 | RiotTag: OmenSmoke#ID04 | Recovery: OK',
            'immortal.kuronami05@nexavault.id:RGXKatana#6623 | RiotTag: ChamberOP#ID05 | Recovery: OK',
        ]);

        $vaultService->bulkIngestVaultItems($pGenshinAccount->id, $seller2->id, [
            'furina.ar58.01@nexavault.id:FontaineHydro#2026 | UID: 814902311 | Unset Username',
            'furina.ar58.02@nexavault.id:NeuvilletteC2#8831 | UID: 829104455 | Unset Username',
            'furina.ar58.03@nexavault.id:ArchonSpiral#4419 | UID: 837712290 | Unset Username',
            'furina.ar58.04@nexavault.id:TeyvatVault#5592 | UID: 845619820 | Unset Username',
            'furina.ar58.05@nexavault.id:Abyss36Star#9014 | UID: 851290441 | Unset Username',
            'furina.ar58.06@nexavault.id:CelestiaKey#1187 | UID: 862340119 | Unset Username',
        ]);

        // =====================================================================
        // 4. PRE-SEEDED ACTIVE TRANSACTIONS ACROSS ALL STATES + SELLER REPLY
        // =====================================================================

        // (A) COMPLETED Order + 5-star review + Seller Reply
        $invCompleted = $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $pSteam60->id, 'quantity' => 1]],
            clearCart: false,
        );
        $ordCompleted = $invCompleted->orders->firstOrFail();
        $orderStateService->buyerConfirmOrderCompleted(
            orderId: $ordCompleted->id,
            buyerId: $buyer1->id,
            rating: 5,
            comment: 'Gila sih 0 detik langsung bisa buka segel AES-256 dan redeem ke Steam tanpa kendala!',
        );

        OrderReview::where('order_id', $ordCompleted->id)->update([
            'seller_reply' => 'Terima kasih banyak kak Bagas! Selamat menikmati saldo Steam-nya, ditunggu order berikutnya di Juragan Voucher Official.',
            'replied_at' => now()->subMinutes(15),
        ]);

        // (A2) Second COMPLETED Order + 5-star review for Seller 2 (without reply yet so Seller 2 can reply live)
        $invCompleted2 = $checkoutService->checkout(
            buyerId: $buyer2->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $pGenshinAccount->id, 'quantity' => 1]],
            clearCart: false,
        );
        $ordCompleted2 = $invCompleted2->orders->firstOrFail();
        $orderStateService->buyerConfirmOrderCompleted(
            orderId: $ordCompleted2->id,
            buyerId: $buyer2->id,
            rating: 5,
            comment: 'Akun Genshin AR 58 Furina C2 benar-benar unset username & birthday, langsung ganti email dalam 2 menit!',
        );

        // (B) DELIVERED Order (AES-256 Vault ready to unseal)
        $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $pSteam120->id, 'quantity' => 1]],
            clearCart: false,
        );

        // (C) PROCESSING Manual Trade Order with active SLA timer
        $invProcessingManual = $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $pGrowtopiaBgl->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['grow_id' => 'BagasGT99', 'world_name' => 'BAGASDROP88'],
                'verified_game_nickname' => 'BagasGT99 @ WORLD BAGASDROP88',
            ]],
            clearCart: false,
        );
        $ordProcessingManual = $invProcessingManual->orders->firstOrFail();
        DB::table('orders')->where('id', $ordProcessingManual->id)->update([
            'sla_deadline_at' => now()->addMinutes(8),
        ]);

        // (D) PROCESSING API H2H Order
        $checkoutService->checkout(
            buyerId: $buyer2->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $pMlbb257->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
                'verified_game_nickname' => 'EVOS•KaguraID',
            ]],
            clearCart: false,
        );

        // (E) DISPUTED Order with flagged anti-bypass chat
        $invDisputed = $checkoutService->checkout(
            buyerId: $buyer2->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $pValAccount->id, 'quantity' => 1]],
            clearCart: false,
        );
        $ordDisputed = $invDisputed->orders->firstOrFail();
        $orderStateService->buyerOpenDispute(
            orderId: $ordDisputed->id,
            buyerId: $buyer2->id,
            reason: 'Saya sudah membuka segel brankas AES-256 tetapi saat login Riot Client muncul kode verifikasi 2FA email penjual belum dimatikan. Mohon bantuan Admin Arbitrase.',
            proofUrl: 'https://cdn.nexatrade.id/proofs/riot-2fa-screen-01.png',
        );
        $chatService->postOrderMessage(
            order: $ordDisputed,
            sender: $seller2,
            rawMessage: 'Halo bro, kode OTP 2FA saya kirim lewat WhatsApp aja di 081234567890 atau chat https://wa.me/6281234567890 biar cepat, kalau mau tambah skin bisa transfer langsung ke DANA 081299887766.',
        );
        $chatService->postOrderMessage(
            order: $ordDisputed,
            sender: $buyer2,
            rawMessage: 'Tidak mau pindah ke luar NexaTrade bang, semua kode dan bukti wajib lewat ruang sengketa resmi di sini agar escrow aman.',
        );

        // (F) UNPAID Multi-Seller Invoice
        $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [
                ['product_id' => $pSteam60->id, 'quantity' => 1],
                [
                    'product_id' => $pMlbbJoki->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => ['user_id' => '987654321', 'zone_id' => '2512', 'target_rank' => 'Mythic Grading (Main Hayabusa)'],
                    'verified_game_nickname' => 'RRQ•LancelotPro',
                ],
            ],
            clearCart: false,
        );

        // (G) Saved Bank Accounts + 1 Pending Withdrawal Request from Seller 1 linked to Primary BCA
        $seller1Bca = $bankAccountService->addBankAccount($seller1->id, [
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia (BCA)',
            'account_number' => '8830192841',
            'account_holder_name' => 'RAKA PRATAMA',
            'is_primary' => true,
        ]);
        $bankAccountService->addBankAccount($seller1->id, [
            'bank_code' => 'JAGO',
            'bank_name' => 'Bank Jago',
            'account_number' => '102938475612',
            'account_holder_name' => 'RAKA PRATAMA',
            'is_primary' => false,
        ]);
        $bankAccountService->addBankAccount($seller2->id, [
            'bank_code' => 'MANDIRI',
            'bank_name' => 'Bank Mandiri',
            'account_number' => '1370009911223',
            'account_holder_name' => 'DIMAS MAHENDRA',
            'is_primary' => true,
        ]);

        $orderStateService->requestWithdrawal(
            userId: $seller1->id,
            bankName: 'BCA',
            accountNumber: '8830192841',
            accountHolder: 'RAKA PRATAMA',
            amount: 50000.00,
            userBankAccountId: $seller1Bca->id,
        );

        // Preserve exact target SLA metrics
        DB::table('seller_profiles')->where('user_id', $seller1->id)->update([
            'avg_delivery_minutes' => '3.20',
            'is_sla_10m_badge' => true,
        ]);
        DB::table('seller_profiles')->where('user_id', $seller2->id)->update([
            'avg_delivery_minutes' => '14.50',
            'is_sla_10m_badge' => false,
        ]);

        // (H) Pre-populate 2 multi-seller items in Buyer 1's cart
        CartItem::create([
            'user_id' => $buyer1->id,
            'product_id' => $pSteam60->id,
            'quantity' => 1,
            'buyer_game_inputs' => null,
            'verified_game_nickname' => null,
        ]);
        CartItem::create([
            'user_id' => $buyer1->id,
            'product_id' => $pGenshinAccount->id,
            'quantity' => 1,
            'buyer_game_inputs' => null,
            'verified_game_nickname' => null,
        ]);

        // (I) Seed Wishlists & Store Followers (Composite PKs)
        DB::table('wishlists')->insert([
            ['user_id' => $buyer1->id, 'product_id' => $pValAccount->id, 'created_at' => now()->subHours(2)],
            ['user_id' => $buyer1->id, 'product_id' => $pGenshinAccount->id, 'created_at' => now()->subHour()],
            ['user_id' => $buyer2->id, 'product_id' => $pSteam120->id, 'created_at' => now()->subMinutes(30)],
        ]);

        DB::table('store_followers')->insert([
            ['user_id' => $buyer1->id, 'seller_id' => $seller1->id, 'created_at' => now()->subDays(3)],
            ['user_id' => $buyer1->id, 'seller_id' => $seller2->id, 'created_at' => now()->subDays(1)],
            ['user_id' => $buyer2->id, 'seller_id' => $seller1->id, 'created_at' => now()->subHours(6)],
        ]);

        // (J) Phase 2: Private KYC Placeholder PNGs + Approved Seller KYCs + 1 Pending Buyer 2 KYC (Buyer 1 stays clean)
        $ktpPrivatePath = 'private/kyc/seeded/ktp-sample.png';
        $selfiePrivatePath = 'private/kyc/seeded/selfie-sample.png';
        Storage::disk('local')->put($ktpPrivatePath, $this->makePlaceholderPng('NEXATRADE E-KTP SAMPLE (PRIVATE DISK)', [239, 246, 255], [0, 104, 225]));
        Storage::disk('local')->put($selfiePrivatePath, $this->makePlaceholderPng('NEXATRADE SELFIE + KTP (PRIVATE DISK)', [236, 253, 245], [0, 170, 91]));

        $nikSeller1 = '3171010101900001';
        $hashSeller1 = $kycService->computeNikBlindHash($nikSeller1);
        SellerKycVerification::create([
            'user_id' => $seller1->id,
            'active_user_lock' => $seller1->id,
            'proposed_store_name' => 'Juragan Voucher Official',
            'proposed_store_slug' => 'juragan-voucher-official',
            'proposed_store_tagline' => 'Distributor Resmi Voucher AES-256 & Top-Up H2H 24 Jam Nonstop',
            'full_legal_name' => 'RAKA PRATAMA',
            'nik_encrypted' => Crypt::encryptString($nikSeller1),
            'nik_last4' => substr($nikSeller1, -4),
            'nik_blind_hash' => $hashSeller1,
            'active_nik_lock_hash' => $hashSeller1,
            'id_card_private_path' => $ktpPrivatePath,
            'selfie_private_path' => $selfiePrivatePath,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(30),
        ]);

        $nikSeller2 = '3273020202920002';
        $hashSeller2 = $kycService->computeNikBlindHash($nikSeller2);
        SellerKycVerification::create([
            'user_id' => $seller2->id,
            'active_user_lock' => $seller2->id,
            'proposed_store_name' => 'Sultan Akun Nusantara',
            'proposed_store_slug' => 'sultan-akun-nusantara',
            'proposed_store_tagline' => 'Kurator Akun High-Tier, Growtopia BGL & Joki Mythic Bergaransi',
            'full_legal_name' => 'DIMAS MAHENDRA',
            'nik_encrypted' => Crypt::encryptString($nikSeller2),
            'nik_last4' => substr($nikSeller2, -4),
            'nik_blind_hash' => $hashSeller2,
            'active_nik_lock_hash' => $hashSeller2,
            'id_card_private_path' => $ktpPrivatePath,
            'selfie_private_path' => $selfiePrivatePath,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(18),
        ]);

        $nikBuyer2 = '3578030303950003';
        $hashBuyer2 = $kycService->computeNikBlindHash($nikBuyer2);
        $pendingKyc = SellerKycVerification::create([
            'user_id' => $buyer2->id,
            'active_user_lock' => $buyer2->id,
            'proposed_store_name' => 'ProPlayer TopUp Store',
            'proposed_store_slug' => 'proplayer-topup-store',
            'proposed_store_tagline' => 'Top-Up Kompetitif MLBB & Valorant Cepat dan Amanah',
            'full_legal_name' => 'FAJAR NUGROHO',
            'nik_encrypted' => Crypt::encryptString($nikBuyer2),
            'nik_last4' => substr($nikBuyer2, -4),
            'nik_blind_hash' => $hashBuyer2,
            'active_nik_lock_hash' => $hashBuyer2,
            'id_card_private_path' => $ktpPrivatePath,
            'selfie_private_path' => $selfiePrivatePath,
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        // (K) Phase 2: 2 Realistic Pre-Transaction Conversations (with Attached Product Card + 1 Bypass-Flagged Message)
        $conv1 = $conversationService->startOrGetConversation($buyer1->id, $seller2->id, $pValAccount->id);
        $conversationService->sendMessage(
            conversationId: $conv1->id,
            senderId: $buyer1->id,
            rawMessage: 'Halo min, akun Valorant Immortal Kuronami ini email pertama (FE) aktif dan siap ganti email langsung setelah buka segel brankas?',
            attachedProductId: $pValAccount->id,
        );
        $conversationService->sendMessage(
            conversationId: $conv1->id,
            senderId: $seller2->id,
            rawMessage: 'Halo kak Bagas! Betul 100% First Email siap ganti, data lengkap sudah terenkripsi di Brankas AES-256 jadi begitu bayar langsung keluar otomatis.',
        );

        $conv2 = $conversationService->startOrGetConversation($buyer2->id, $seller1->id, $pSteam120->id);
        $conversationService->sendMessage(
            conversationId: $conv2->id,
            senderId: $buyer2->id,
            rawMessage: 'Bang stok Steam Wallet IDR 120K masih aman? Boleh nego lewat WA aja di 081355667788 biar saya transfer langsung via DANA?',
            attachedProductId: $pSteam120->id,
        );
        $conversationService->sendMessage(
            conversationId: $conv2->id,
            senderId: $seller1->id,
            rawMessage: 'Maaf kak, kami hanya menerima transaksi resmi di NexaTrade agar garansi Escrow & Brankas AES-256 tetap berlaku 100%. Stok voucher 120K masih ready!',
        );

        // (L) Phase 2: 6 Rich Notifications across Buyer 1, Seller 1, and Admin
        $notificationService->notify(
            userId: $buyer1->id,
            category: 'order',
            title: 'Voucher Steam 120K Siap Dibuka di Brankas',
            body: 'Pesanan Brankas Instan Anda telah terverifikasi. Klik untuk membuka segel AES-256 sekarang.',
            actionUrl: '/buyer/orders',
            refType: 'order',
            refId: $ordCompleted->id,
        );
        $notificationService->notify(
            userId: $buyer1->id,
            category: 'system',
            title: 'Ingin Mulai Berjualan di NexaTrade?',
            body: 'Ajukan Buka Toko & Verifikasi KYC e-KTP kurang dari 2 menit untuk membuka fitur Brankas AES-256.',
            actionUrl: '/buyer/open-store',
            refType: 'system',
        );
        $notificationService->notify(
            userId: $seller1->id,
            category: 'withdrawal',
            title: 'Pengajuan Tarik Saldo Rp 50.000 Sedang Diproses',
            body: 'Dana ditahan ke BCA (8830192841) a.n. RAKA PRATAMA menunggu persetujuan Admin.',
            actionUrl: '/seller/wallet',
            refType: 'withdrawal',
        );
        $notificationService->notify(
            userId: $seller1->id,
            category: 'escrow',
            title: 'Badge 10 Menit Kirim Aktif di Toko Anda',
            body: 'Rata-rata pengiriman 3.2 menit menjaga peringkat Juragan Voucher Official di puncak katalog.',
            actionUrl: '/seller/dashboard',
            refType: 'seller_profile',
        );
        $notificationService->notify(
            userId: $admin->id,
            category: 'kyc',
            title: 'Antrean Verifikasi KYC: ProPlayer TopUp Store',
            body: 'FAJAR NUGROHO (NIK ****0003) mengajukan buka toko baru. Tinjau KTP & Selfie privat sekarang.',
            actionUrl: '/admin/kyc?tab=pending',
            refType: 'seller_kyc',
            refId: $pendingKyc->id,
        );
        $notificationService->notify(
            userId: $admin->id,
            category: 'chat',
            title: 'Peringatan Anti-Bypass pada Inbox Pra-Transaksi',
            body: '1 pesan terindikasi nomor HP / transaksi luar otomatis disensor pada Thread #'.$conv2->id.'.',
            actionUrl: '/admin/kyc?tab=flagged_chat',
            refType: 'conversation',
            refId: $conv2->id,
        );
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $bgRgb
     * @param  array{0: int, 1: int, 2: int}  $accentRgb
     */
    private function makePlaceholderPng(string $label, array $bgRgb, array $accentRgb): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(640, 380);
            if ($img !== false) {
                $bg = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
                $accent = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
                $white = imagecolorallocate($img, 255, 255, 255);
                $ink = imagecolorallocate($img, 17, 24, 39);
                $muted = imagecolorallocate($img, 107, 114, 128);

                imagefilledrectangle($img, 0, 0, 640, 380, $bg);
                imagefilledrectangle($img, 24, 24, 616, 356, $white);
                imagerectangle($img, 24, 24, 616, 356, $accent);
                imagefilledrectangle($img, 24, 24, 616, 86, $accent);

                imagestring($img, 5, 48, 48, $label, $white);
                imagestring($img, 4, 48, 120, 'REPUBLIK INDONESIA - SAMPLE VERIFIKASI KYC', $ink);
                imagestring($img, 3, 48, 160, 'Dokumen ini disimpan di Private Disk (local) Laravel', $muted);
                imagestring($img, 3, 48, 185, 'Hanya dapat diakses oleh Admin & Pemohon terotorisasi.', $muted);
                imagestring($img, 4, 48, 240, 'ENKRIPSI: AES-256 + HMAC-SHA256 BLIND-HASH', $accent);
                imagestring($img, 3, 48, 300, 'NexaTrade Enterprise Escrow & Risk Governance', $muted);

                ob_start();
                imagepng($img);
                $bytes = (string) ob_get_clean();
                imagedestroy($img);

                if ($bytes !== '') {
                    return $bytes;
                }
            }
        }

        // Valid minimal PNG fallback if GD extension is disabled
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true) ?: '';
    }
}
