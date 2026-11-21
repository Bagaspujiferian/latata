<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('product_vault_id')->nullable();
            $table->string('product_title_snapshot', 200);
            $table->string('sku_snapshot', 50);
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 15, 2);
            $table->text('encrypted_delivery_payload')->nullable();
            $table->timestamps();

            $table->foreign('product_vault_id')->references('id')->on('product_vaults')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
