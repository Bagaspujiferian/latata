<?php

namespace Tests\Feature;

use App\Enums\VerificationChannel;
use App\Mail\OtpVerificationMail;
use App\Models\User;
use App\Models\UserVerificationChallenge;
use App\Models\Wallet;
use App\Services\UserVerificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpGatewayDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_phone_otp_via_fonnte_driver_sends_expected_payload_and_handles_http_failures_gracefully(): void
    {
        config([
            'nexatrade.otp_gateway.whatsapp_driver' => 'fonnte',
            'nexatrade.otp_gateway.fonnte_token' => 'fonnte-secret-token-2026',
            'nexatrade.otp_gateway.fonnte_endpoint' => 'https://api.fonnte.com/send',
        ]);

        Http::fake([
            'https://api.fonnte.com/send' => Http::sequence()
                ->push(['status' => true, 'detail' => 'success! message in queue'], 200)
                ->push(['status' => false, 'reason' => 'device disconnected'], 500),
        ]);

        $verificationService = app(UserVerificationService::class);
        $user = User::create([
            'name' => 'Fonnte WA Buyer',
            'email' => 'fonnte.buyer@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $user->id]);

        $canonicalPhone = $verificationService->bindPhoneNumber($user, '0812-3456-7890');
        $this->assertSame('6281234567890', $canonicalPhone);

        // 1. Successful Fonnte WhatsApp OTP delivery
        $response = $this->actingAs($user)
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::PHONE->value,
            ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $capturedOtp = null;
        Http::assertSent(function (HttpClientRequest $request) use ($canonicalPhone, &$capturedOtp): bool {
            $data = $request->data();
            if (isset($data['message']) && preg_match('/\*(\d{6})\*/', (string) $data['message'], $matches)) {
                $capturedOtp = $matches[1];
            }

            return $request->url() === 'https://api.fonnte.com/send'
                && $request->hasHeader('Authorization', 'fonnte-secret-token-2026')
                && ($data['target'] ?? null) === $canonicalPhone
                && ($data['countryCode'] ?? null) === '62'
                && $capturedOtp !== null;
        });

        $this->assertNotNull($capturedOtp);
        $verificationService->verifyChallenge($user, VerificationChannel::PHONE, (string) $capturedOtp);
        $this->assertNotNull($user->refresh()->phone_verified_at);

        // 2. Travel past 60s cooldown and test graceful handling when Fonnte returns HTTP 500
        $this->travel(65)->seconds();

        $failResponse = $this->actingAs($user)
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::PHONE->value,
            ]);

        $failResponse->assertStatus(422)
            ->assertJsonPath('ok', false);

        // Secret token MUST NEVER be leaked in the error message
        $errorMessage = (string) $failResponse->json('message');
        $this->assertStringNotContainsString('fonnte-secret-token-2026', $errorMessage);

        // Unconsumed challenge must have been rolled back when gateway failed
        $unconsumedCount = UserVerificationChallenge::where('user_id', $user->id)
            ->where('channel', VerificationChannel::PHONE->value)
            ->whereNull('consumed_at')
            ->count();
        $this->assertSame(0, $unconsumedCount);
    }

    public function test_email_otp_sends_otp_verification_mail_with_matching_database_code_hash(): void
    {
        Mail::fake();

        $verificationService = app(UserVerificationService::class);
        $user = User::create([
            'name' => 'Email Mailable Buyer',
            'email' => 'mailable.buyer@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::EMAIL->value,
            ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $challenge = UserVerificationChallenge::where('user_id', $user->id)
            ->where('channel', VerificationChannel::EMAIL->value)
            ->whereNull('consumed_at')
            ->latest('id')
            ->firstOrFail();

        $sentOtpCode = null;

        Mail::assertSent(OtpVerificationMail::class, function (OtpVerificationMail $mail) use ($user, $challenge, &$sentOtpCode): bool {
            $sentOtpCode = $mail->otpCode;
            $expectedHash = hash_hmac(
                'sha256',
                'otp:'.$user->id.':'.VerificationChannel::EMAIL->value.':'.$mail->otpCode,
                (string) config('app.key')
            );

            return $mail->hasTo($user->email)
                && strlen($mail->otpCode) === 6
                && hash_equals((string) $challenge->code_hash, $expectedHash);
        });

        $this->assertNotNull($sentOtpCode);
        $verificationService->verifyChallenge($user, VerificationChannel::EMAIL, (string) $sentOtpCode);
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_three_layer_anti_drain_rate_limiter_blocks_cooldown_target_hourly_and_ip_hourly_without_calling_fonnte(): void
    {
        config([
            'nexatrade.otp_gateway.whatsapp_driver' => 'fonnte',
            'nexatrade.otp_gateway.fonnte_token' => 'fonnte-rate-limit-token',
            'nexatrade.otp_gateway.fonnte_endpoint' => 'https://api.fonnte.com/send',
            'nexatrade.otp_gateway.resend_cooldown_seconds' => 60,
            'nexatrade.otp_gateway.max_per_target_per_hour' => 5,
            'nexatrade.otp_gateway.max_per_ip_per_hour' => 15,
        ]);

        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
        ]);

        $verificationService = app(UserVerificationService::class);

        $user = User::create([
            'name' => 'Rate Limit Target User',
            'email' => 'ratelimit.target@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $user->id]);
        $verificationService->bindPhoneNumber($user, '0813-1111-2222');

        // --- LAYER 1: 60-Second Resend Cooldown ---
        $first = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::PHONE->value,
            ]);
        $first->assertOk();
        Http::assertSentCount(1);

        // Immediate resend (< 60s) MUST be blocked with HTTP 429 and MUST NOT call Fonnte
        $secondImmediate = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::PHONE->value,
            ]);
        $secondImmediate->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('limit_layer', 'cooldown');
        Http::assertSentCount(1);

        // --- LAYER 2: Max 5 Requests / Hour Per Target ---
        // Requests #2, #3, #4, #5 spaced 61 seconds apart succeed (total 5 in the same hour)
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->travel(61)->seconds();

            $res = $this->actingAs($user)
                ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->postJson(route('account.security.otp.send'), [
                    'channel' => VerificationChannel::PHONE->value,
                ]);
            $res->assertOk();
        }
        Http::assertSentCount(5);

        // Request #6 to the same phone number within the same hour (even after 61s cooldown) MUST be rejected with 429
        $this->travel(61)->seconds();
        $sixthSameTarget = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::PHONE->value,
            ]);
        $sixthSameTarget->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('limit_layer', 'target_hourly');
        Http::assertSentCount(5);

        // --- LAYER 3: Max 15 Requests / Hour Per IP (Rotating Accounts & Phone Numbers) ---
        $attackerIp = '203.0.113.99';
        RateLimiter::clear('otp:ip_hour:'.$attackerIp);

        for ($i = 1; $i <= 15; $i++) {
            $botUser = User::create([
                'name' => "Bot Drain User {$i}",
                'email' => "bot.drain.{$i}@nexatrade.id",
                'password' => Hash::make('password123'),
                'role' => 'buyer',
                'is_active' => true,
            ]);
            Wallet::create(['user_id' => $botUser->id]);
            $phone = '0815'.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            $verificationService->bindPhoneNumber($botUser, $phone);

            $botRes = $this->actingAs($botUser)
                ->withServerVariables(['REMOTE_ADDR' => $attackerIp])
                ->postJson(route('account.security.otp.send'), [
                    'channel' => VerificationChannel::PHONE->value,
                ]);
            $botRes->assertOk();
        }

        // 5 from earlier + 15 from attackerIp = 20 Fonnte calls so far
        Http::assertSentCount(20);

        // 16th request from the same IP with a brand-new 16th account & phone number MUST be blocked by Layer 3 (IP cap)
        $botUser16 = User::create([
            'name' => 'Bot Drain User 16',
            'email' => 'bot.drain.16@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $botUser16->id]);
        $verificationService->bindPhoneNumber($botUser16, '081500000016');

        $sixteenthFromSameIp = $this->actingAs($botUser16)
            ->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->postJson(route('account.security.otp.send'), [
                'channel' => VerificationChannel::PHONE->value,
            ]);

        $sixteenthFromSameIp->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('limit_layer', 'ip_hourly');

        // Zero extra Fonnte HTTP calls on the blocked 16th request!
        Http::assertSentCount(20);
    }
}
