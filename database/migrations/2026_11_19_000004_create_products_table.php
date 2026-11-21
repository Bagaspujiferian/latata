<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('game_category_id')->constrained('game_categories')->cascadeOnDelete();
            $table->string('sku_code', 50)->unique();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->text('description');
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_order')->default(1);
            $table->unsignedInteger('max_order')->default(10);
            $table->unsignedInteger('sla_target_minutes')->default(10);
            $table->string('h2h_provider_sku', 60)->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('last_bumped_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['game_category_id', 'is_active', 'price'], 'idx_catalog_filter');
            $table->index(['is_active', 'is_featured', 'last_bumped_at'], 'idx_ranking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
