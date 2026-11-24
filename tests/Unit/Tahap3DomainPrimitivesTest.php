<?php

namespace Tests\Unit;

use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use App\Services\FulltextQuerySanitizer;
use App\Services\SlaDeadlineCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

class Tahap3DomainPrimitivesTest extends TestCase
{
    public function test_sla_deadline_calculator_handles_within_hours_before_open_closed_day_and_all_closed_fallback(): void
    {
        $calculator = new SlaDeadlineCalculator;

        $profile = new SellerProfile([
            'operating_hours_enabled' => true,
            'operating_timezone' => 'Asia/Jakarta',
        ]);

        // Schedule: Sunday (0) closed; Monday (1) to Saturday (6) open 08:00:00 - 22:00:00
        $hours = new Collection;
        for ($d = 0; $d <= 6; $d++) {
            $hours->push(new StoreOperatingHour([
                'day_of_week' => $d,
                'open_time' => '08:00:00',
                'close_time' => '22:00:00',
                'is_closed' => $d === 0,
            ]));
        }

        // 1. Order paid inside operating hours (Monday 2026-11-23 10:00:00 WIB, SLA 30m) -> 10:30:00 WIB
        $mondayMorning = CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta');
        $deadline1 = $calculator->calculateDeadline($mondayMorning, 30, $profile, $hours);
        $this->assertSame('2026-11-23 10:30:00', $deadline1->format('Y-m-d H:i:s'));

        // 2. Order paid at 02:00:00 early morning (Monday 2026-11-23 02:00:00 WIB, SLA 30m) -> shifts to 08:00 + 30m = 08:30:00 WIB
        $mondayEarly = CarbonImmutable::parse('2026-11-23 02:00:00', 'Asia/Jakarta');
        $deadline2 = $calculator->calculateDeadline($mondayEarly, 30, $profile, $hours);
        $this->assertSame('2026-11-23 08:30:00', $deadline2->format('Y-m-d H:i:s'));

        // 3. Order paid on closed day (Sunday 2026-11-22 14:15:00 WIB, SLA 45m) -> shifts to Monday 08:00 + 45m = 2026-11-23 08:45:00 WIB
        $sundayAfternoon = CarbonImmutable::parse('2026-11-22 14:15:00', 'Asia/Jakarta');
        $deadline3 = $calculator->calculateDeadline($sundayAfternoon, 45, $profile, $hours);
        $this->assertSame('2026-11-23 08:45:00', $deadline3->format('Y-m-d H:i:s'));

        // 4. Order paid after close on Saturday night (Saturday 2026-11-21 23:10:00 WIB, Sunday closed, Monday opens 08:00, SLA 20m) -> Monday 08:20:00
        $saturdayLate = CarbonImmutable::parse('2026-11-21 23:10:00', 'Asia/Jakarta');
        $deadline4 = $calculator->calculateDeadline($saturdayLate, 20, $profile, $hours);
        $this->assertSame('2026-11-23 08:20:00', $deadline4->format('Y-m-d H:i:s'));

        // 5. Safe fallback when all 7 days are closed -> paidAt + slaMinutes
        $allClosed = new Collection;
        for ($d = 0; $d <= 6; $d++) {
            $allClosed->push(new StoreOperatingHour([
                'day_of_week' => $d,
                'open_time' => '08:00:00',
                'close_time' => '22:00:00',
                'is_closed' => true,
            ]));
        }
        $fallbackDeadline = $calculator->calculateDeadline($mondayEarly, 30, $profile, $allClosed);
        $this->assertSame('2026-11-23 02:30:00', $fallbackDeadline->format('Y-m-d H:i:s'));
    }

    public function test_fulltext_query_sanitizer_strips_special_boolean_operators_and_returns_null_for_short_tokens(): void
    {
        $sanitizer = new FulltextQuerySanitizer;

        // 1. Cleans dangerous MySQL Boolean Mode operators and builds +token* query
        $query = $sanitizer->buildBooleanQuery('  +Diamond -Mobile *() "@<>~ Legends  ');
        $this->assertSame('+diamond* +mobile* +legends*', $query);

        // 2. Short 1-2 character tokens return null for safe LIKE fallback
        $this->assertNull($sanitizer->buildBooleanQuery('ML'));
        $this->assertNull($sanitizer->buildBooleanQuery('UC FF'));

        // 3. Pure operator characters return null
        $this->assertNull($sanitizer->buildBooleanQuery('+-*()"@<>~'));
        $this->assertSame('', $sanitizer->escapeLike('+-*()"@<>~'));

        // 4. Escapes LIKE wildcards safely
        $this->assertSame('100\% \_Promo\_', $sanitizer->escapeLike('100% _Promo_'));
    }
}
