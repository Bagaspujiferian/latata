<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('wallet_pin_hash', 255)->nullable()->after('password');
            $table->unsignedTinyInteger('wallet_pin_attempts')->default(0)->after('wallet_pin_hash');
            $table->timestamp('wallet_pin_locked_until')->nullable()->after('wallet_pin_attempts');
            $table->timestamp('wallet_pin_set_at')->nullable()->after('wallet_pin_locked_until');
        });

        // Safe MySQL ENUM Expansion: PRESERVE ALL 15 existing Phase 0–3 values and append AD_CREDIT_PURCHASE!
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_mutations MODIFY COLUMN mutation_type ENUM(
                'ESCROW_HOLD',
                'ESCROW_RELEASE',
                'ESCROW_REFUND_DEBIT',
                'BUYER_REFUND_CREDIT',
                'ZOMBIE_PAYMENT_REFUND',
                'HYBRID_WALLET_HOLD',
                'HYBRID_EXPIRY_REFUND',
                'PAY_ORDER_FULL_WALLET',
                'WITHDRAW_HOLD',
                'WITHDRAW_REJECT_REFUND',
                'ADMIN_DEMO_TOPUP',
                'SECURITY_DEPOSIT_LOCK',
                'WARRANTY_CLAWBACK_DEBIT',
                'WARRANTY_BUYER_REFUND',
                'SELLER_DEBT_RECOVERY',
                'AD_CREDIT_PURCHASE'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_mutations MODIFY COLUMN mutation_type ENUM(
                'ESCROW_HOLD',
                'ESCROW_RELEASE',
                'ESCROW_REFUND_DEBIT',
                'BUYER_REFUND_CREDIT',
                'ZOMBIE_PAYMENT_REFUND',
                'HYBRID_WALLET_HOLD',
                'HYBRID_EXPIRY_REFUND',
                'PAY_ORDER_FULL_WALLET',
                'WITHDRAW_HOLD',
                'WITHDRAW_REJECT_REFUND',
                'ADMIN_DEMO_TOPUP',
                'SECURITY_DEPOSIT_LOCK',
                'WARRANTY_CLAWBACK_DEBIT',
                'WARRANTY_BUYER_REFUND',
                'SELLER_DEBT_RECOVERY'
            ) NOT NULL");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'wallet_pin_hash',
                'wallet_pin_attempts',
                'wallet_pin_locked_until',
                'wallet_pin_set_at',
            ]);
        });
    }
};
