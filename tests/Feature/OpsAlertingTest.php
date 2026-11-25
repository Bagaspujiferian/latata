<?php

namespace Tests\Feature;

use App\Enums\HeartbeatRunStatus;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Services\OpsAlertDispatcherService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpsAlertingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Cache::flush();
    }

    public function test_telegram_discord_and_both_drivers_format_payloads_and_test_alert_command_bypasses_cooldown(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://discord.com/api/webhooks/*' => Http::response('', 204),
        ]);

        config([
            'nexatrade.alerts.telegram_bot_token' => 'test_bot_token_123',
            'nexatrade.alerts.telegram_chat_id' => '-100999888777',
            'nexatrade.alerts.discord_webhook_url' => 'https://discord.com/api/webhooks/123456/test_webhook_token',
            'nexatrade.alerts.cooldown_seconds' => 900,
        ]);

        $dispatcher = app(OpsAlertDispatcherService::class);

        // 1. Telegram driver
        config(['nexatrade.alerts.driver' => 'telegram']);
        $sentTg = $dispatcher->dispatch(
            severity: OpsAlertDispatcherService::SEVERITY_CRITICAL,
            title: 'Uji Telegram Alert',
            message: 'Pesan kritis untuk Telegram.',
            dedupKey: 'tg_only_test',
            context: ['module' => 'escrow'],
        );

        $this->assertTrue($sentTg);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.telegram.org/bottest_bot_token_123/sendMessage'
                && ($data['chat_id'] ?? '') === '-100999888777'
                && str_contains((string) ($data['text'] ?? ''), '[NEXATRADE OPS ALERT • CRITICAL]')
                && str_contains((string) ($data['text'] ?? ''), 'Uji Telegram Alert')
                && str_contains((string) ($data['text'] ?? ''), 'module: escrow');
        });

        // 2. Discord driver (verify CRITICAL, WARNING, INFO embed colors)
        config(['nexatrade.alerts.driver' => 'discord']);
        $sentDc = $dispatcher->dispatch(
            severity: OpsAlertDispatcherService::SEVERITY_WARNING,
            title: 'Uji Discord Warning',
            message: 'Pesan warning untuk Discord.',
            dedupKey: 'dc_only_test',
            context: ['queue' => 'redis'],
        );

        $this->assertTrue($sentDc);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://discord.com/api/webhooks/123456/test_webhook_token') {
                return false;
            }
            $data = $request->data();
            $embed = $data['embeds'][0] ?? [];

            return ($embed['title'] ?? '') === '[WARNING] Uji Discord Warning'
                && ($embed['color'] ?? 0) === 16776960
                && ($embed['description'] ?? '') === 'Pesan warning untuk Discord.';
        });

        // 3. Both driver + nexatrade:test-alert command (bypasses cooldown on consecutive runs)
        config(['nexatrade.alerts.driver' => 'both']);
        $this->artisan('nexatrade:test-alert', ['--message' => 'Connectivity Ping #1'])
            ->assertExitCode(0);
        $this->artisan('nexatrade:test-alert', ['--message' => 'Connectivity Ping #2'])
            ->assertExitCode(0);

        Http::assertSentCount(6); // 1 tg + 1 dc + (2 * 2 from both)
    }

    public function test_anti_spam_cooldown_deduplication_and_fail_safe_isolation_on_webhook_500_or_connection_exception(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        config([
            'nexatrade.alerts.driver' => 'telegram',
            'nexatrade.alerts.telegram_bot_token' => 'test_bot_token_123',
            'nexatrade.alerts.telegram_chat_id' => '-100999888777',
            'nexatrade.alerts.cooldown_seconds' => 900,
        ]);

        $dispatcher = app(OpsAlertDispatcherService::class);

        // 1. Cooldown deduplication prevents second dispatch with same dedupKey
        $first = $dispatcher->dispatch(
            severity: 'CRITICAL',
            title: 'Duplicate Alert Test',
            message: 'First trigger.',
            dedupKey: 'same_dedup_key_01',
        );
        $second = $dispatcher->dispatch(
            severity: 'CRITICAL',
            title: 'Duplicate Alert Test',
            message: 'Second trigger inside 15m cooldown.',
            dedupKey: 'same_dedup_key_01',
        );

        $this->assertTrue($first);
        $this->assertFalse($second);
        Http::assertSentCount(1);

        // 2. Fail-safe isolation when Telegram/Discord returns HTTP 500 or throws ConnectionException
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['description' => 'Internal Server Error'], 500),
            'https://discord.com/api/webhooks/*' => static function (): never {
                throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
            },
        ]);

        config([
            'nexatrade.alerts.driver' => 'both',
            'nexatrade.alerts.discord_webhook_url' => 'https://discord.com/api/webhooks/123456/timeout_test',
        ]);

        $failSafeResult = $dispatcher->dispatch(
            severity: 'CRITICAL',
            title: 'Fail-Safe Resilience Test',
            message: 'Must not throw uncaught exception when external webhook fails.',
            dedupKey: 'failsafe_dedup_key_02',
        );

        $this->assertTrue($failSafeResult);
    }

    public function test_monitor_ops_command_detects_stale_and_failed_scheduler_heartbeats_and_failed_jobs_threshold(): void
    {
        Http::fake([
            'https://discord.com/api/webhooks/*' => Http::response('', 204),
        ]);

        config([
            'nexatrade.alerts.driver' => 'discord',
            'nexatrade.alerts.discord_webhook_url' => 'https://discord.com/api/webhooks/123456/ops_monitor',
            'nexatrade.alerts.cooldown_seconds' => 900,
            'nexatrade.alerts.failed_jobs_threshold' => 3,
        ]);

        // 1. Simulate 1 STALE worker & 1 FAILED worker
        SchedulerHeartbeat::where('command_name', 'nexatrade:expire-boosts')->update([
            'last_finished_at' => now()->subHours(3),
            'last_status' => HeartbeatRunStatus::OK,
        ]);

        SchedulerHeartbeat::where('command_name', 'nexatrade:reconcile-h2h')->update([
            'last_status' => HeartbeatRunStatus::FAILED,
            'last_error' => 'Digiflazz H2H connection reset by peer',
            'consecutive_failures' => 2,
        ]);

        // 2. Simulate 3 rows in failed_jobs (>= threshold 3)
        for ($i = 1; $i <= 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\DispatchH2HOrderJob']),
                'exception' => 'RuntimeException: Simulated queue failure #'.$i,
                'failed_at' => now(),
            ]);
        }

        $this->artisan('nexatrade:monitor-ops')
            ->expectsOutputToContain('Anomalies=3 | AlertsDispatched=3')
            ->assertExitCode(0);

        Http::assertSentCount(3);

        // Verify nexatrade:monitor-ops recorded its own healthy heartbeat
        $monitorHb = SchedulerHeartbeat::where('command_name', 'nexatrade:monitor-ops')->firstOrFail();
        $this->assertSame(HeartbeatRunStatus::OK, $monitorHb->last_status);
        $this->assertSame(0, $monitorHb->consecutive_failures);

        // Running monitor-ops again inside cooldown window sends 0 duplicate HTTP requests
        $this->artisan('nexatrade:monitor-ops')
            ->expectsOutputToContain('Anomalies=3 | AlertsDispatched=0')
            ->assertExitCode(0);

        Http::assertSentCount(3);
    }

    public function test_ledger_discrepancy_automatically_dispatches_critical_ops_alert_and_restores_balanced_state(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        config([
            'nexatrade.alerts.driver' => 'telegram',
            'nexatrade.alerts.telegram_bot_token' => 'test_bot_token_ledger',
            'nexatrade.alerts.telegram_chat_id' => '-100111222333',
        ]);

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $originalBalance = (string) DB::table('wallets')->where('user_id', $buyer1->id)->value('available_balance');

        // Tamper wallet balance directly in DB to trigger DISCREPANCY
        DB::table('wallets')->where('user_id', $buyer1->id)->update([
            'available_balance' => number_format((float) $originalBalance + 10000.00, 2, '.', ''),
        ]);

        $this->artisan('nexatrade:audit-ledger')
            ->assertExitCode(1);

        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, '[NEXATRADE OPS ALERT • CRITICAL]')
                && str_contains($text, 'Ledger Discrepancy Detected')
                && str_contains($text, '10.000,00');
        });

        // Restore balance and confirm audit returns to BALANCED
        DB::table('wallets')->where('user_id', $buyer1->id)->update([
            'available_balance' => $originalBalance,
        ]);

        $this->artisan('nexatrade:audit-ledger')
            ->assertExitCode(0);
    }
}
