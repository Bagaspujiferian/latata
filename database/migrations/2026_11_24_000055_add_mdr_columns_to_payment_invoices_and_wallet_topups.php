<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_invoices', 'gateway_payment_type')) {
                $table->string('gateway_payment_type', 40)->nullable()->after('payment_channel');
            }
            if (! Schema::hasColumn('payment_invoices', 'gateway_mdr_fee')) {
                $table->decimal('gateway_mdr_fee', 15, 2)->default(0.00)->after('gateway_fee');
            }
        });

        Schema::table('wallet_topups', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallet_topups', 'gateway_payment_type')) {
                $table->string('gateway_payment_type', 40)->nullable()->after('payment_channel');
            }
            if (! Schema::hasColumn('wallet_topups', 'gateway_mdr_fee')) {
                $table->decimal('gateway_mdr_fee', 15, 2)->default(0.00)->after('gateway_payment_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['gateway_payment_type', 'gateway_mdr_fee'],
                static fn (string $c): bool => Schema::hasColumn('wallet_topups', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('payment_invoices', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['gateway_payment_type', 'gateway_mdr_fee'],
                static fn (string $c): bool => Schema::hasColumn('payment_invoices', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
