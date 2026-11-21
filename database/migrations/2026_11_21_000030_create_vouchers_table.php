<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title', 120);
            $table->enum('issuer_type', ['platform', 'seller']);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->foreignId('game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->enum('discount_type', ['fixed', 'percent']);
            $table->decimal('discount_value', 15, 2);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->decimal('min_purchase_amount', 15, 2)->default(0.00);
            $table->unsignedInteger('quota_total');
            $table->unsignedInteger('quota_used')->default(0);
            $table->unsignedInteger('max_uses_per_user')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('seller_id', 'fk_vouchers_seller_profile')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index(['code', 'is_active', 'starts_at', 'ends_at'], 'idx_vouchers_lookup');
            $table->index(['issuer_type', 'seller_id', 'is_active'], 'idx_vouchers_issuer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
