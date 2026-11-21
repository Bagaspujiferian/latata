<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('order_code', 40)->unique();
            $table->foreignId('payment_invoice_id')->constrained('payment_invoices')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('platform_commission', 15, 2);
            $table->decimal('seller_escrow_amount', 15, 2);
            $table->enum('delivery_method', ['instant_vault', 'manual_trade', 'api_h2h']);
            $table->enum('status', [
                'WAITING_PAYMENT',
                'PAID_ESCROW',
                'PROCESSING',
                'DELIVERED',
                'COMPLETED',
                'DISPUTED',
                'REFUNDED',
                'CANCELLED',
            ])->default('WAITING_PAYMENT');
            $table->json('buyer_game_inputs')->nullable();
            $table->string('verified_game_nickname', 100)->nullable();
            $table->json('seller_delivery_proof')->nullable();
            $table->string('h2h_reference_id', 60)->unique()->nullable();
            $table->json('h2h_response_log')->nullable();
            $table->unsignedInteger('sla_target_minutes_snapshot')->default(10);
            $table->timestamp('sla_deadline_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('auto_complete_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancelled_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status', 'sla_deadline_at'], 'idx_seller_sla_queue');
            $table->index(['status', 'auto_complete_at'], 'idx_auto_complete_worker');
        });

        Schema::table('product_vaults', function (Blueprint $table) {
            $table->foreign('reserved_order_id', 'fk_vault_reserved_order')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_vaults', function (Blueprint $table) {
            $table->dropForeign('fk_vault_reserved_order');
        });

        Schema::dropIfExists('orders');
    }
};
