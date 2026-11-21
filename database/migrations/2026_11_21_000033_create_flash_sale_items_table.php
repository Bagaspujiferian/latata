<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('flash_sale_id')->constrained('flash_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('flash_price', 15, 2);
            $table->unsignedInteger('flash_quota');
            $table->unsignedInteger('flash_sold')->default(0);
            $table->unsignedInteger('max_qty_per_user')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['flash_sale_id', 'product_id'], 'uq_fsi_sale_product');
            $table->index(['product_id', 'flash_sale_id'], 'idx_fsi_product_sale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_items');
    }
};
