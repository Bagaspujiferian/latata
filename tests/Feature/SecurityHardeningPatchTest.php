<?php

namespace Tests\Feature;

use App\Exceptions\TooManyUnpaidInvoicesException;
use App\Models\FlashSaleItem;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\SellerKycVerification;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMutation;
use App\Models\WalletTopUp;
use App\Models\WarrantyClaim;
use App\Services\CheckoutSplitOrderService;
use App\Services\DigiflazzH2HService;
use App\Services\DocumentExportService;
use App\Services\MidtransWebhookService;
use App\Services\SecureImageUploadService;
use App\Services\WalletTopUpService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningPatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_simulation_endpoints_return_404_and_hide_ui_buttons_in_production(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $unpaidInvoice = PaymentInvoice::where('buyer_id', $buyer1->id)->where('status', 'UNPAID')->firstOrFail();
        $unpaidTopUp = WalletTopUp::where('user_id', $buyer1->id)->where('status', 'UNPAID')->firstOrFail();

        // Simulate APP_ENV=production where demo_switcher_enabled is false
        config(['nexatrade.demo_switcher_enabled' => false]);

        $this->post(route('demo.switch-user'), ['email' => 'admin@nexatrade.id'])->assertNotFound();
        $this->actingAs($buyer1)->post(route('demo.wallet-topup'), ['amount' => 100000])->assertNotFound();
        $this->actingAs($buyer1)->post(route('invoices.simulate-pay', $unpaidInvoice->invoice_code))->assertNotFound();
        $this->actingAs($buyer1)->post(route('buyer.wallet.topup.simulate-pay', $unpaidTopUp->topup_code))->assertNotFound();
        $this->actingAs($seller1)->post(route('seller.wallet.repay-debt-gateway'), ['amount' => 50000])->assertNotFound();
        $this->actingAs($admin)->postJson(route('admin.chaos-lab.run', 1))->assertNotFound();

        // Blade views MUST NOT render any simulation buttons when demo_switcher_enabled=false
        $this->actingAs($buyer1)->get(route('invoices.show', $unpaidInvoice->invoice_code))
            ->assertOk()
            ->assertDontSee('Simulasi Bayar Lunas Midtrans');

        $this->actingAs($buyer1)->get(route('buyer.wallet.topup.show', $unpaidTopUp->topup_code))
            ->assertOk()
            ->assertDontSee('Simulasi Bayar Lunas Top-Up');

        $this->actingAs($buyer1)->get(route('buyer.wallet'))
            ->assertOk()
            ->assertDontSee('Top-Up Demo Rp 500.000');
    }

    public function test_csv_formula_injection_is_neutralized_across_orders_and_mutations_exports(): void
    {
        $exportService = app(DocumentExportService::class);

        // Unit check sanitizeCsvCell() for all formula vectors + numeric safety
        $this->assertSame("'=CMD|' /C calc'!A0", $exportService->sanitizeCsvCell("=CMD|' /C calc'!A0"));
        $this->assertSame("'+HYPERLINK(\"http://evil.test\",\"x\")", $exportService->sanitizeCsvCell('+HYPERLINK("http://evil.test","x")'));
        $this->assertSame("'-2+3", $exportService->sanitizeCsvCell('-2+3'));
        $this->assertSame("'@SUM(1+1)", $exportService->sanitizeCsvCell('@SUM(1+1)'));
        $this->assertSame("' =1+1", $exportService->sanitizeCsvCell(' =1+1'));
        $this->assertSame("'\t=1+1", $exportService->sanitizeCsvCell("\t=1+1"));
        $this->assertSame('-15000.00', $exportService->sanitizeCsvCell('-15000.00', true));
        $this->assertSame("'=1+1", $exportService->sanitizeCsvCell('=1+1', true));

        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        // Create attacker buyer + order + mutation with dangerous Excel formula payloads
        $attacker = User::create([
            'name' => "=CMD|' /C calc'!A0",
            'email' => 'csv.attacker@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $attacker->id]);

        $product = Product::where('seller_id', $seller1->id)->firstOrFail();
        $invoice = PaymentInvoice::create([
            'invoice_code' => 'INV-CSV-'.strtoupper(Str::random(6)),
            'buyer_id' => $attacker->id,
            'subtotal_amount' => '50000.00',
            'seller_discount_total' => '0.00',
            'platform_discount_total' => '0.00',
            'gateway_fee' => '0.00',
            'wallet_used_amount' => '50000.00',
            'grand_total' => '0.00',
            'payment_channel' => 'wallet_full',
            'status' => 'PAID',
            'paid_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $order = Order::create([
            'order_code' => 'NXT-CSV-'.strtoupper(Str::random(6)),
            'payment_invoice_id' => $invoice->id,
            'buyer_id' => $attacker->id,
            'seller_id' => $seller1->id,
            'gross_amount' => '50000.00',
            'seller_discount_amount' => '0.00',
            'platform_discount_amount' => '0.00',
            'buyer_payable_amount' => '50000.00',
            'platform_commission' => '2500.00',
            'seller_escrow_amount' => '47500.00',
            'delivery_method' => 'manual_trade',
            'status' => 'COMPLETED',
            'sla_target_minutes_snapshot' => 10,
            'completed_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_title_snapshot' => '+HYPERLINK("http://evil.test","x")',
            'sku_snapshot' => $product->sku_code,
            'unit_price_snapshot' => '50000.00',
            'quantity' => 1,
            'subtotal' => '50000.00',
        ]);

        WalletMutation::create([
            'reference_code' => 'CSV-TEST-'.strtoupper(Str::random(6)),
            'user_id' => $seller1->id,
            'order_id' => $order->id,
            'balance_bucket' => 'available',
            'direction' => 'CREDIT',
            'amount' => '1000.00',
            'balance_before' => '0.00',
            'balance_after' => '1000.00',
            'mutation_type' => 'ADMIN_DEMO_TOPUP',
            'description' => '@SUM(1+1)*cmd',
            'created_at' => now(),
        ]);

        // Export Orders CSV & parse every cell
        $ordersCsv = $this->actingAs($seller1)->get(route('seller.exports.orders'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=CMD|' /C calc'!A0", $ordersCsv);
        $this->assertStringContainsString("'+HYPERLINK(", $ordersCsv);

        $ordersLines = array_filter(preg_split('/\r\n|\n|\r/', ltrim($ordersCsv, "\xEF\xBB\xBF")) ?: []);
        foreach (array_slice($ordersLines, 1) as $line) {
            $cols = str_getcsv($line);
            foreach ([2, 5] as $textIdx) {
                if (isset($cols[$textIdx]) && $cols[$textIdx] !== '') {
                    $this->assertDoesNotMatchRegularExpression('/^[=+\-@]/', $cols[$textIdx]);
                }
            }
        }

        // Export Mutations CSV & verify @SUM payload neutralized
        $mutCsv = $this->actingAs($seller1)->get(route('seller.exports.mutations'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'@SUM(1+1)*cmd", $mutCsv);
    }

    public function test_idor_private_files_return_403_and_sanitized_image_upload_blocks_disguised_php_and_strips_polyglot(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();

        $buyer2Kyc = SellerKycVerification::where('user_id', $buyer2->id)->firstOrFail();
        $buyer2Claim = WarrantyClaim::where('buyer_id', $buyer2->id)->firstOrFail();
        $buyer1Invoice = PaymentInvoice::where('buyer_id', $buyer1->id)->firstOrFail();
        $buyer1Order = Order::where('buyer_id', $buyer1->id)->firstOrFail();

        // 1. Strict IDOR protection (HTTP 403 for unauthorized users; 200 for owner & admin)
        $this->actingAs($buyer1)->get(route('admin.kyc.document', [$buyer2Kyc->id, 'id_card']))->assertForbidden();
        $this->actingAs($buyer2)->get(route('admin.kyc.document', [$buyer2Kyc->id, 'id_card']))->assertOk();
        $this->actingAs($admin)->get(route('admin.kyc.document', [$buyer2Kyc->id, 'id_card']))->assertOk();

        $this->actingAs($buyer1)->get(route('admin.warranties.evidence', [$buyer2Claim->id, 0]))->assertForbidden();
        $this->actingAs($buyer2)->get(route('admin.warranties.evidence', [$buyer2Claim->id, 0]))->assertOk();
        $this->actingAs($admin)->get(route('admin.warranties.evidence', [$buyer2Claim->id, 0]))->assertOk();

        $this->actingAs($buyer2)->get(route('invoices.pdf', $buyer1Invoice->invoice_code))->assertForbidden();
        $this->actingAs($buyer2)->get(route('buyer.orders.pdf', $buyer1Order->order_code))->assertForbidden();

        // 2. SecureImageUploadService re-encodes valid raster image & strips appended PHP polyglot payload
        $uploadService = app(SecureImageUploadService::class);
        $realPng = UploadedFile::fake()->image('clean-proof.png', 100, 100);
        fflush($realPng->tempFile);
        rewind($realPng->tempFile);
        $rawPngBytes = (string) stream_get_contents($realPng->tempFile);
        $this->assertNotSame('', $rawPngBytes);

        $polyglotFile = UploadedFile::fake()->createWithContent(
            'polyglot.png',
            $rawPngBytes."<?php system(\$_GET['cmd']); ?>"
        );

        $storedPath = $uploadService->storeSanitizedImage($polyglotFile, 'local', 'private/security-test', 3072);
        $this->assertStringStartsWith('private/security-test/', $storedPath);
        $this->assertTrue(Storage::disk('local')->exists($storedPath));

        $savedBytes = (string) Storage::disk('local')->get($storedPath);
        $this->assertStringNotContainsString('<?php', $savedBytes);
        $this->assertStringNotContainsString('system(', $savedBytes);
        Storage::disk('local')->delete($storedPath);

        // 3. Disguised PHP script renamed as .jpg MUST be rejected by BOTH controller validation & service!
        $disguisedPhp = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo shell_exec("whoami"); ?>');

        $this->actingAs($buyer1)->post(route('buyer.open-store.submit'), [
            'proposed_store_name' => 'Attacker Store',
            'full_legal_name' => 'Attacker Name',
            'nik' => '3171090909990009',
            'id_card_file' => $disguisedPhp,
            'selfie_file' => UploadedFile::fake()->image('selfie.jpg', 100, 100),
        ])->assertSessionHasErrors('id_card_file');

        try {
            $uploadService->storeSanitizedImage($disguisedPhp, 'local', 'private/security-test', 3072);
            $this->fail('Expected disguised PHP file to be rejected by SecureImageUploadService.');
        } catch (DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_anti_inventory_hoarding_caps_unpaid_invoices_and_enforces_15m_flash_sale_ttl(): void
    {
        $checkoutService = app(CheckoutSplitOrderService::class);
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $buyer = User::create([
            'name' => 'Hoarder Test Buyer',
            'email' => 'hoarder@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $buyer->id]);

        $game = Game::firstOrFail();
        $cat = GameCategory::create([
            'game_id' => $game->id,
            'name' => 'Hoarding Test Manual',
            'slug' => 'hoarding-test-manual',
            'delivery_method' => 'manual_trade',
            'platform_fee_percent' => '5.00',
            'escrow_hold_hours' => 24,
            'buyer_input_schema' => [],
        ]);
        $prod = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'HOARD-TEST-01',
            'title' => 'Produk Anti Hoarding Test',
            'slug' => 'produk-anti-hoarding-test',
            'description' => 'Produk uji batas 3 invoice UNPAID per pembeli.',
            'price' => '25000.00',
            'stock' => 20,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        // 1. First 3 UNPAID invoices MUST succeed
        $createdInvoices = [];
        for ($i = 1; $i <= 3; $i++) {
            $inv = $checkoutService->checkout(
                buyerId: $buyer->id,
                paymentMode: 'gateway_only',
                paymentChannel: 'qris',
                explicitItems: [['product_id' => $prod->id, 'quantity' => 1]],
                clearCart: false,
            );
            $this->assertSame('UNPAID', $inv->status);
            $createdInvoices[] = $inv;
        }

        // 2. 4th concurrent UNPAID checkout MUST throw TooManyUnpaidInvoicesException!
        try {
            $checkoutService->checkout(
                buyerId: $buyer->id,
                paymentMode: 'gateway_only',
                paymentChannel: 'qris',
                explicitItems: [['product_id' => $prod->id, 'quantity' => 1]],
                clearCart: false,
            );
            $this->fail('Expected TooManyUnpaidInvoicesException on 4th concurrent UNPAID invoice.');
        } catch (TooManyUnpaidInvoicesException $e) {
            $this->assertStringContainsString('3 tagihan belum dibayar', $e->getMessage());
        }

        // 3. Expire 1 invoice so buyer can checkout an active Flash Sale item -> expires_at MUST be ~15 minutes!
        DB::table('payment_invoices')->where('id', $createdInvoices[0]->id)->update(['status' => 'EXPIRED']);

        $flashItem = FlashSaleItem::with('product.gameCategory')->firstOrFail();
        $flashProduct = $flashItem->product;
        $requiredInputs = [];
        foreach (($flashProduct->gameCategory->required_inputs_schema ?? []) as $field) {
            $k = (string) ($field['key'] ?? '');
            if ($k !== '') {
                $requiredInputs[$k] = ($field['type'] ?? 'text') === 'number' ? '12345678' : 'TestUser#123';
            }
        }

        $flashInvoice = $checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [[
                'product_id' => $flashProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => $requiredInputs,
                'verified_game_nickname' => 'FlashTester',
            ]],
            clearCart: false,
        );

        $ttlMinutes = now()->diffInMinutes($flashInvoice->expires_at, false);
        $this->assertGreaterThanOrEqual(13, $ttlMinutes);
        $this->assertLessThanOrEqual(16, $ttlMinutes);
    }

    public function test_instant_banned_session_kill_logs_out_active_user_on_web_and_json(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();

        // Active user accesses protected page normally
        $this->actingAs($buyer1)->get(route('buyer.wallet'))->assertOk();

        // Admin/Risk Engine bans the user while session is active
        $buyer1->forceFill([
            'is_banned' => true,
            'banned_at' => now(),
            'ban_reason' => 'Indikasi kecurangan refund massal.',
        ])->save();

        // Next web request MUST immediately terminate session and redirect to /login!
        $webResp = $this->actingAs($buyer1->fresh())->get(route('buyer.wallet'));
        $webResp->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();

        // Next JSON request MUST return HTTP 403 and log out!
        $jsonResp = $this->actingAs($buyer1->fresh())->getJson(route('buyer.wallet'));
        $jsonResp->assertForbidden()
            ->assertJsonPath('ok', false);
        $this->assertGuest();

        // Re-login attempt MUST also be rejected
        $this->post(route('login.submit'), [
            'email' => $buyer1->email,
            'password' => 'password123',
        ])->assertRedirect();
        $this->assertGuest();
    }

    public function test_timing_safe_webhook_signature_and_prefix_separation_rejection(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $midtransService = app(MidtransWebhookService::class);
        $digiflazzService = app(DigiflazzH2HService::class);

        // 1. Midtrans invalid signature -> HTTP 403
        $this->postJson(route('api.webhooks.midtrans'), [
            'order_id' => 'INV-SPOOF-999',
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'invalid-signature-value',
        ])->assertForbidden()->assertJsonPath('signature_valid', false);

        // 2. Midtrans valid signature BUT unknown prefix (neither INV- nor TOPUP-) -> HTTP 422
        $badPrefixId = 'UNKNOWN-123456';
        $badPrefixSig = $midtransService->generateSignature($badPrefixId, '200', '50000.00');
        $this->postJson(route('api.webhooks.midtrans'), [
            'order_id' => $badPrefixId,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $badPrefixSig,
        ])->assertStatus(422);

        // 3. Midtrans valid signature with TOPUP- prefix routes explicitly to WalletTopUpService!
        $topup = app(WalletTopUpService::class)->createTopUp($buyer1->id, 75000.00, 'qris');
        $topupGross = '75000.00';
        $topupSig = $midtransService->generateSignature($topup->topup_code, '200', $topupGross);
        $availBefore = (float) $buyer1->wallet->fresh()->available_balance;

        $this->postJson(route('api.webhooks.midtrans'), [
            'order_id' => $topup->topup_code,
            'status_code' => '200',
            'gross_amount' => $topupGross,
            'transaction_status' => 'settlement',
            'transaction_id' => 'MT-TOPUP-TEST-01',
            'signature_key' => $topupSig,
        ])->assertOk()->assertJsonPath('topup_status', 'PAID');

        $this->assertEqualsWithDelta($availBefore + 75000.00, (float) $buyer1->wallet->fresh()->available_balance, 0.01);
        $this->assertEqualsWithDelta(75000.00, (float) $buyer1->wallet->fresh()->non_withdrawable_portion, 0.01);

        // 4. Digiflazz H2H webhook with missing/forged X-Hub-Signature -> HTTP 403!
        $h2hOrder = Order::where('delivery_method', 'api_h2h')
            ->where('status', 'PROCESSING')
            ->firstOrFail();

        $dfPayload = [
            'data' => [
                'ref_id' => $h2hOrder->h2h_reference_id,
                'status' => 'Sukses',
                'sn' => 'SN-SEC-TEST-888',
            ],
        ];
        $rawBody = json_encode($dfPayload, JSON_THROW_ON_ERROR);

        $this->call(
            method: 'POST',
            uri: route('api.webhooks.digiflazz'),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => 'sha1=0000000000000000000000000000000000000000'],
            content: $rawBody,
        )->assertForbidden()->assertJsonPath('signature_valid', false);

        // 5. Digiflazz H2H webhook with valid timing-safe HMAC-SHA1 X-Hub-Signature -> HTTP 200!
        $validHubSig = $digiflazzService->generateWebhookSignature($rawBody);
        $this->call(
            method: 'POST',
            uri: route('api.webhooks.digiflazz'),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => $validHubSig],
            content: $rawBody,
        )->assertOk()->assertJsonPath('order_status', 'DELIVERED');
    }
}
