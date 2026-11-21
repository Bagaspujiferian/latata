<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_mutations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('reference_code', 90)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_invoice_id')->nullable();
            $table->enum('balance_bucket', ['pending', 'available']);
            $table->enum('direction', ['CREDIT', 'DEBIT']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->enum('mutation_type', [
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
            ]);
            $table->string('description', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'balance_bucket', 'created_at'], 'idx_wallet_history');
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('payment_invoice_id')->references('id')->on('payment_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_mutations');
    }
};
