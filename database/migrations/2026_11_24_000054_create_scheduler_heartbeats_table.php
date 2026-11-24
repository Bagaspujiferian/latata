<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('command_name', 100)->unique();
            $table->string('display_name', 120);
            $table->unsignedInteger('expected_interval_minutes');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->enum('last_status', ['running', 'ok', 'failed'])->default('ok');
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->string('last_summary', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['last_status', 'last_finished_at'], 'sh_status_finished_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
    }
};
