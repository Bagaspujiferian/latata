<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_invoices', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('invoice_code', 40)->unique();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('subtotal_amount', 15, 2);
            $table->decimal('gateway_fee', 15, 2)->default(0.00);
            $table->decimal('wallet_used_amount', 15, 2)->default(0.00);
            $table->decimal('grand_total', 15, 2);
            $table->string('payment_channel', 40)->nullable();
            $table->string('snap_token', 255)->nullable();
            $table->string('midtrans_transaction_id', 100)->nullable();
            $table->enum('status', ['UNPAID', 'PAID', 'PAID_LATE_REFUNDED', 'EXPIRED', 'FAILED'])->default('UNPAID');
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'idx_invoice_sweeper');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_invoices');
    }
};
