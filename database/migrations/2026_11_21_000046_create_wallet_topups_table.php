<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table): void {
            $table->id();
            $table->string('topup_code', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_channel', 40)->default('midtrans_snap');
            $table->string('gateway_reference', 100)->nullable()->unique();
            $table->enum('status', ['UNPAID', 'PAID', 'EXPIRED', 'FAILED'])->default('UNPAID');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at'], 'idx_wallet_topups_user_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
    }
};
