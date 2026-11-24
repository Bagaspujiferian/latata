<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_verification_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('channel', ['email', 'phone']);
            $table->char('target_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'channel', 'consumed_at'], 'uvc_user_channel_consumed_idx');
            $table->index(['expires_at'], 'uvc_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_verification_challenges');
    }
};
