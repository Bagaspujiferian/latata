<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('withdraw_code', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('bank_name', 50);
            $table->string('account_number', 50);
            $table->string('account_holder', 100);
            $table->decimal('amount', 15, 2);
            $table->decimal('admin_fee', 15, 2)->default(2500.00);
            $table->decimal('net_payout', 15, 2);
            $table->enum('status', ['PENDING', 'APPROVED_PAID', 'REJECTED_REFUNDED'])->default('PENDING');
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('processed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
