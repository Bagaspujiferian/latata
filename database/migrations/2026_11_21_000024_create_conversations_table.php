<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->foreignId('context_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('context_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedInteger('buyer_unread_count')->default(0);
            $table->unsignedInteger('seller_unread_count')->default(0);
            $table->string('last_message_preview', 160)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('seller_id', 'fk_conv_seller_profile')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->unique(['buyer_id', 'seller_id'], 'uq_conv_buyer_seller');
            $table->index(['buyer_id', 'last_message_at'], 'idx_conv_buyer_last_msg');
            $table->index(['seller_id', 'last_message_at'], 'idx_conv_seller_last_msg');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
