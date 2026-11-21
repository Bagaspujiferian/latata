<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_warranties', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedSmallInteger('warranty_days');
            $table->decimal('coverage_amount', 15, 2);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->enum('status', ['active', 'claimed', 'refunded', 'expired', 'voided'])->default('active');
            $table->timestamps();

            $table->foreign('seller_id', 'fk_ow_seller_profile')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index(['buyer_id', 'status', 'expires_at'], 'idx_ow_buyer_status_exp');
            $table->index(['seller_id', 'status'], 'idx_ow_seller_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_warranties');
    }
};
