<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('http_method', 10);
            $table->string('route_path', 160);
            $table->char('payload_hash', 64);
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'uq_idempotency_user_key');
            $table->index('expires_at', 'idx_idempotency_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
