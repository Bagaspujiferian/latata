<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_mutations MODIFY COLUMN mutation_type ENUM(
                'ESCROW_HOLD',
                'ESCROW_RELEASE',
                'ESCROW_REFUND_DEBIT',
                'BUYER_REFUND_CREDIT',
                'ZOMBIE_PAYMENT_REFUND',
                'LATE_PAYMENT_REFUND',
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
                'AD_CREDIT_PURCHASE',
                'WALLET_TOPUP',
                'DISPUTE_PARTIAL_REFUND',
                'DISPUTE_PARTIAL_RELEASE'
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
                'SELLER_DEBT_RECOVERY',
                'AD_CREDIT_PURCHASE',
                'WALLET_TOPUP',
                'DISPUTE_PARTIAL_REFUND',
                'DISPUTE_PARTIAL_RELEASE'
            ) NOT NULL");
        }
    }
};
