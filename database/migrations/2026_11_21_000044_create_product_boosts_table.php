<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_boosts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->enum('boost_type', ['featured', 'bump']);
            $table->unsignedInteger('credits_spent');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['active', 'expired'])->default('active');
            $table->timestamps();

            $table->foreign('seller_id', 'fk_product_boosts_seller')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index(['status', 'ends_at'], 'idx_product_boosts_status_ends');
            $table->index(['product_id', 'boost_type', 'status'], 'idx_product_boosts_prod_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_boosts');
    }
};
