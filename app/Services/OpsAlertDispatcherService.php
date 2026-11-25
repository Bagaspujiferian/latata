<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpsAlertDispatcherService
{
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public const SEVERITY_WARNING = 'WARNING';

    public const SEVERITY_INFO = 'INFO';

    /**
     * Dispatch an operational alert with anti-spam cooldown deduplication and fail-safe external HTTP isolation.
     *
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        string $severity,
        string $title,
        string $message,
        string $dedupKey,
        array $context = [],
        bool $bypassCooldown = false,
    ): bool {
        $normalizedSeverity = $this->normalizeSeverity($severity);
        $cleanDedupKey = trim($dedupKey) !== '' ? trim($dedupKey) : md5($normalizedSeverity.'|'.$title);
        $cooldownSeconds = max(1, (int) config('nexatrade.alerts.cooldown_seconds', 900));
        $cacheKey = "nexatrade:alert:cooldown:{$cleanDedupKey}";

        if (! $bypassCooldown) {
            $acquired = Cache::add($cacheKey, true, $cooldownSeconds);
            if (! $acquired) {
                return false;
            }
        } else {
            Cache::put($cacheKey, true, $cooldownSeconds);
        }

        $driver = strtolower(trim((string) config('nexatrade.alerts.driver', 'log')));

        $this->writeStructuredLog($normalizedSeverity, $title, $message, $cleanDedupKey, $context);

        if (in_array($driver, ['telegram', 'both'], true)) {
            $this->sendTelegramAlert($normalizedSeverity, $title, $message, $cleanDedupKey, $context);
        }

        if (in_array($driver, ['discord', 'both'], true)) {
            $this->sendDiscordAlert($normalizedSeverity, $title, $message, $cleanDedupKey, $context);
        }

        return true;
    }

    private function normalizeSeverity(string $severity): string
    {
        $upper = strtoupper(trim($severity));

        return match ($upper) {
            self::SEVERITY_CRITICAL => self::SEVERITY_CRITICAL,
            self::SEVERITY_WARNING => self::SEVERITY_WARNING,
            default => self::SEVERITY_INFO,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function writeStructuredLog(
        string $severity,
        string $title,
        string $message,
        string $dedupKey,
        array $context,
    ): void {
        $payload = [
            'alert_severity' => $severity,
            'alert_title' => $title,
            'alert_dedup_key' => $dedupKey,
            'context' => $context,
        ];

        $logLine = "[NEXATRADE OPS ALERT][{$severity}] {$title}: {$message}";

        match ($severity) {
            self::SEVERITY_CRITICAL => Log::critical($logLine, $payload),
            self::SEVERITY_WARNING => Log::warning($logLine, $payload),
            default => Log::info($logLine, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendTelegramAlert(
        string $severity,
        string $title,
        string $message,
        string $dedupKey,
        array $context,
    ): void {
        $botToken = trim((string) config('nexatrade.alerts.telegram_bot_token', ''));
        $chatId = trim((string) config('nexatrade.alerts.telegram_chat_id', ''));

        if ($botToken === '' || $chatId === '') {
            Log::error('[NEXATRADE OPS ALERT] Telegram bot token or chat ID is not configured.');

            return;
        }

        $endpoint = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $text = $this->formatTelegramText($severity, $title, $message, $dedupKey, $context);

        try {
            $response = Http::timeout(5)->post($endpoint, [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            if ($response->failed()) {
                Log::error('[NEXATRADE OPS ALERT] Telegram webhook returned non-2xx response.', [
                    'status' => $response->status(),
                    'dedup_key' => $dedupKey,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('[NEXATRADE OPS ALERT] Telegram webhook request failed: '.$e->getMessage(), [
                'dedup_key' => $dedupKey,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendDiscordAlert(
        string $severity,
        string $title,
        string $message,
        string $dedupKey,
        array $context,
    ): void {
        $webhookUrl = trim((string) config('nexatrade.alerts.discord_webhook_url', ''));

        if ($webhookUrl === '') {
            Log::error('[NEXATRADE OPS ALERT] Discord webhook URL is not configured.');

            return;
        }

        $color = match ($severity) {
            self::SEVERITY_CRITICAL => 15158332,
            self::SEVERITY_WARNING => 16776960,
            default => 3066993,
        };

        $fields = [];
        foreach ($context as $key => $value) {
            $formattedValue = is_scalar($value) || $value === null
                ? (string) ($value ?? '-')
                : (json_encode($value) ?: '-');

            $fields[] = [
                'name' => (string) $key,
                'value' => mb_substr($formattedValue !== '' ? $formattedValue : '-', 0, 1024),
                'inline' => true,
            ];
        }

        try {
            $response = Http::timeout(5)->post($webhookUrl, [
                'username' => (string) config('nexatrade.app_brand', 'NexaTrade Enterprise Engine'),
                'content' => "[{$severity}] {$title}",
                'embeds' => [
                    [
                        'title' => "[{$severity}] {$title}",
                        'description' => $message,
                        'color' => $color,
                        'fields' => $fields,
                        'footer' => [
                            'text' => "NexaTrade Ops Monitor • Dedup: {$dedupKey}",
                        ],
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ]);

            if ($response->failed()) {
                Log::error('[NEXATRADE OPS ALERT] Discord webhook returned non-2xx response.', [
                    'status' => $response->status(),
                    'dedup_key' => $dedupKey,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('[NEXATRADE OPS ALERT] Discord webhook request failed: '.$e->getMessage(), [
                'dedup_key' => $dedupKey,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatTelegramText(
        string $severity,
        string $title,
        string $message,
        string $dedupKey,
        array $context,
    ): string {
        $lines = [
            "[NEXATRADE OPS ALERT • {$severity}]",
            "Judul: {$title}",
            "Pesan: {$message}",
            "Dedup Key: {$dedupKey}",
            'Waktu: '.now()->format('Y-m-d H:i:s').' WIB',
        ];

        if ($context !== []) {
            $lines[] = '';
            $lines[] = 'Konteks:';
            foreach ($context as $key => $value) {
                $formatted = is_scalar($value) || $value === null
                    ? (string) ($value ?? '-')
                    : (json_encode($value) ?: '-');
                $lines[] = "- {$key}: {$formatted}";
            }
        }

        return implode("\n", $lines);
    }
}
