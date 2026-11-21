<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_usages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_invoice_id')->constrained('payment_invoices')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->enum('subsidy_bearer', ['platform', 'seller']);
            $table->decimal('discount_amount', 15, 2);
            $table->enum('status', ['reserved', 'applied', 'released'])->default('reserved');
            $table->timestamps();

            $table->index(['voucher_id', 'user_id', 'status'], 'idx_vu_voucher_user_status');
            $table->index(['payment_invoice_id', 'status'], 'idx_vu_invoice_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_usages');
    }
};
