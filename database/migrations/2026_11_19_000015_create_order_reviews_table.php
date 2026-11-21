<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('buyer_id')->constrained('users');
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 500)->nullable();
            $table->unsignedInteger('delivery_duration_minutes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
