<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_credit_purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('purchase_code', 40)->unique();
            $table->unsignedBigInteger('seller_id');
            $table->foreignId('package_id')->constrained('ad_credit_packages')->restrictOnDelete();
            $table->unsignedInteger('credits_granted');
            $table->decimal('amount_paid', 15, 2);
            $table->enum('payment_method', ['available_balance', 'midtrans_gateway']);
            $table->enum('status', ['PAID', 'PENDING', 'FAILED'])->default('PAID');
            $table->timestamps();

            $table->foreign('seller_id', 'fk_ad_purchases_seller')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index(['seller_id', 'created_at'], 'idx_ad_purchases_seller_date');
            $table->index(['status', 'created_at'], 'idx_ad_purchases_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_credit_purchases');
    }
};
