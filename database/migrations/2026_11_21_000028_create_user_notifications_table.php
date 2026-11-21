<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('category', ['order', 'escrow', 'chat', 'kyc', 'withdrawal', 'system']);
            $table->string('title', 140);
            $table->string('body', 255);
            $table->string('action_url', 255)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at', 'created_at'], 'idx_unotif_user_read_created');
            $table->index(['user_id', 'category', 'created_at'], 'idx_unotif_user_cat_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
