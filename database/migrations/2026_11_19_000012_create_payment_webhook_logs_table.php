<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('provider', 30);
            $table->string('idempotency_key', 160)->unique();
            $table->boolean('signature_valid')->default(true);
            $table->integer('http_status')->default(200);
            $table->json('raw_payload');
            $table->string('processing_notes', 255);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
