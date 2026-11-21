<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image_path', 255);
            $table->string('alt_text', 150)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('product_id', 'fk_pi_product')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->index(['product_id', 'is_primary', 'sort_order'], 'idx_product_images_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
