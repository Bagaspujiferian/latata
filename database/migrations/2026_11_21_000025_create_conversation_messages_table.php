<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->enum('sender_role', ['buyer', 'seller', 'system']);
            $table->foreignId('attached_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('attached_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('raw_message');
            $table->text('sanitized_message');
            $table->boolean('is_flagged_bypass')->default(false);
            $table->string('detected_violation_type', 80)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id'], 'idx_conv_msg_thread');
            $table->index(['is_flagged_bypass', 'created_at'], 'idx_conv_msg_flagged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
