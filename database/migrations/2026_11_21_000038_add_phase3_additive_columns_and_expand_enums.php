<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_categories', function (Blueprint $table): void {
            $table->unsignedSmallInteger('escrow_hold_hours')->default(24)->after('platform_fee_percent');
            $table->unsignedSmallInteger('warranty_days')->default(0)->after('escrow_hold_hours');
        });

        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->boolean('is_suspended')->default(false)->after('auto_schedule_enabled');
            $table->string('suspension_reason', 255)->nullable()->after('is_suspended');
        });

        Schema::table('payment_invoices', function (Blueprint $table): void {
            $table->decimal('seller_discount_total', 15, 2)->default(0.00)->after('subtotal_amount');
            $table->decimal('platform_discount_total', 15, 2)->default(0.00)->after('seller_discount_total');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('seller_discount_amount', 15, 2)->default(0.00)->after('gross_amount');
            $table->decimal('platform_discount_amount', 15, 2)->default(0.00)->after('seller_discount_amount');
            $table->decimal('buyer_payable_amount', 15, 2)->default(0.00)->after('platform_discount_amount');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('flash_sale_item_id')
                ->nullable()
                ->after('product_vault_id')
                ->constrained('flash_sale_items')
                ->nullOnDelete();
            $table->decimal('original_unit_price', 15, 2)->nullable()->after('unit_price_snapshot');
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('security_deposit_balance', 15, 2)->default(0.00)->after('available_balance');
            $table->decimal('clawback_debt_balance', 15, 2)->default(0.00)->after('security_deposit_balance');
        });

        // Safe MySQL ENUM Expansion: PRESERVE every existing ENUM value while appending Phase 3 values!
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_mutations MODIFY COLUMN balance_bucket ENUM('pending', 'available', 'security_deposit') NOT NULL");
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
            DB::statement("ALTER TABLE user_notifications MODIFY COLUMN category ENUM(
                'order',
                'escrow',
                'chat',
                'kyc',
                'withdrawal',
                'system',
                'warranty',
                'promo',
                'moderation'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_notifications MODIFY COLUMN category ENUM('order', 'escrow', 'chat', 'kyc', 'withdrawal', 'system') NOT NULL");
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
                'ADMIN_DEMO_TOPUP'
            ) NOT NULL");
            DB::statement("ALTER TABLE wallet_mutations MODIFY COLUMN balance_bucket ENUM('pending', 'available') NOT NULL");
        }

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn(['security_deposit_balance', 'clawback_debt_balance']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flash_sale_item_id');
            $table->dropColumn('original_unit_price');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['seller_discount_amount', 'platform_discount_amount', 'buyer_payable_amount']);
        });

        Schema::table('payment_invoices', function (Blueprint $table): void {
            $table->dropColumn(['seller_discount_total', 'platform_discount_total']);
        });

        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->dropColumn(['is_suspended', 'suspension_reason']);
        });

        Schema::table('game_categories', function (Blueprint $table): void {
            $table->dropColumn(['escrow_hold_hours', 'warranty_days']);
        });
    }
};
