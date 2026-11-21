<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->enum('sender_role', ['buyer', 'seller', 'admin', 'system']);
            $table->text('raw_message');
            $table->text('sanitized_message');
            $table->string('attachment_proof_url', 255)->nullable();
            $table->boolean('is_flagged_bypass')->default(false);
            $table->string('detected_violation_type', 80)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
    }
};
