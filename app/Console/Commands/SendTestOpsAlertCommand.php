<?php

namespace App\Console\Commands;

use App\Services\OpsAlertDispatcherService;
use Illuminate\Console\Command;

class SendTestOpsAlertCommand extends Command
{
    protected $signature = 'nexatrade:test-alert
        {--message=Pesan uji coba sistem peringatan operasional NexaTrade. : Isi pesan uji alert}';

    protected $description = 'Kirim pesan uji operasional (INFO) dengan bypass cooldown untuk memverifikasi koneksi webhook Telegram/Discord.';

    public function handle(OpsAlertDispatcherService $alertDispatcher): int
    {
        $driver = (string) config('nexatrade.alerts.driver', 'log');
        $message = (string) $this->option('message');

        $sent = $alertDispatcher->dispatch(
            severity: OpsAlertDispatcherService::SEVERITY_INFO,
            title: 'NexaTrade Operational Alert Connectivity Test',
            message: $message,
            dedupKey: 'manual_test_alert',
            context: [
                'environment' => (string) app()->environment(),
                'driver' => $driver,
            ],
            bypassCooldown: true,
        );

        if ($sent) {
            $this->info("[ALERT SENT] Pesan uji INFO berhasil dikirim melalui driver [{$driver}].");

            return self::SUCCESS;
        }

        $this->error('[ALERT SKIPPED] Pesan uji tidak terkirim.');

        return self::FAILURE;
    }
}
