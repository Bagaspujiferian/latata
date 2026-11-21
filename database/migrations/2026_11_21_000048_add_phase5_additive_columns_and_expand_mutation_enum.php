<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('featured_until')->nullable()->after('is_featured');
            $table->index(['is_featured', 'featured_until'], 'idx_products_featured_until');
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('non_withdrawable_portion', 15, 2)->default(0.00)->after('available_balance');
        });

        Schema::table('user_bank_accounts', function (Blueprint $table): void {
            $table->char('account_blind_hash', 64)->nullable()->after('account_number');
            $table->char('active_account_lock_hash', 64)->nullable()->unique('uq_bank_active_account_lock_hash')->after('account_blind_hash');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedTinyInteger('dispute_buyer_refund_percent')->nullable()->after('cancelled_reason');
            $table->decimal('dispute_buyer_refund_amount', 15, 2)->default(0.00)->after('dispute_buyer_refund_percent');
            $table->decimal('dispute_seller_release_amount', 15, 2)->default(0.00)->after('dispute_buyer_refund_amount');
        });

        // Safe MySQL ENUM Expansion: PRESERVE ALL 16 existing Phase 0–4 values and append 3 Phase 5 values!
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
                'AD_CREDIT_PURCHASE'
            ) NOT NULL");
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'dispute_buyer_refund_percent',
                'dispute_buyer_refund_amount',
                'dispute_seller_release_amount',
            ]);
        });

        Schema::table('user_bank_accounts', function (Blueprint $table): void {
            $table->dropUnique('uq_bank_active_account_lock_hash');
            $table->dropColumn(['account_blind_hash', 'active_account_lock_hash']);
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn('non_withdrawable_portion');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_featured_until');
            $table->dropColumn('featured_until');
        });
    }
};
