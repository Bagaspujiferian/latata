<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_vaults', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->char('payload_blind_hash', 64)->unique();
            $table->text('encrypted_payload');
            $table->enum('status', ['available', 'reserved', 'sold'])->default('available');
            $table->unsignedBigInteger('reserved_order_id')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'id'], 'idx_vault_allocation');
            $table->index('reserved_order_id', 'idx_vault_reserved_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_vaults');
    }
};
