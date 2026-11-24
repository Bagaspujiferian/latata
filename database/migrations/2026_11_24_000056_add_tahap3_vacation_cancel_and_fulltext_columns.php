<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('seller_profiles', 'is_on_vacation')) {
                $table->boolean('is_on_vacation')->default(false)->after('auto_schedule_enabled');
            }
            if (! Schema::hasColumn('seller_profiles', 'vacation_note')) {
                $table->string('vacation_note', 255)->nullable()->after('is_on_vacation');
            }
            if (! Schema::hasColumn('seller_profiles', 'operating_hours_enabled')) {
                $table->boolean('operating_hours_enabled')->default(false)->after('vacation_note');
            }
            if (! Schema::hasColumn('seller_profiles', 'operating_timezone')) {
                $table->string('operating_timezone', 40)->default('Asia/Jakarta')->after('operating_hours_enabled');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'seller_accepted_at')) {
                $table->timestamp('seller_accepted_at')->nullable()->after('sla_deadline_at');
            }
            if (! Schema::hasColumn('orders', 'cancelled_by_role')) {
                $table->string('cancelled_by_role', 20)->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('orders', 'cancellation_reason')) {
                $table->string('cancellation_reason', 255)->nullable()->after('cancelled_reason');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            $hasFulltext = collect(DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_search_fulltext'"))->isNotEmpty();
            if (! $hasFulltext) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->fullText(['title', 'description'], 'products_search_fulltext');
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $hasFulltext = collect(DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_search_fulltext'"))->isNotEmpty();
            if ($hasFulltext) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->dropFullText('products_search_fulltext');
                });
            }
        }

        Schema::table('orders', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['seller_accepted_at', 'cancelled_by_role', 'cancellation_reason'],
                static fn (string $c): bool => Schema::hasColumn('orders', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('seller_profiles', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['is_on_vacation', 'vacation_note', 'operating_hours_enabled', 'operating_timezone'],
                static fn (string $c): bool => Schema::hasColumn('seller_profiles', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
