<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('withdrawal_requests')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->enum('stage', ['maker', 'checker']);
            $table->enum('decision', ['approved', 'rejected']);
            $table->string('notes', 255)->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['withdrawal_id', 'stage']);
            $table->unique(['withdrawal_id', 'admin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_approvals');
    }
};
