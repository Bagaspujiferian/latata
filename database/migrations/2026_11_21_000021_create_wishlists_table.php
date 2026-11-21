<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'product_id']);

            $table->foreign('user_id', 'fk_wl_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('product_id', 'fk_wl_product')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->index('product_id', 'idx_wishlist_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
