<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_repayments', function (Blueprint $table): void {
            $table->id();
            $table->string('repayment_code', 40)->unique();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_source', ['available_balance', 'gateway_direct']);
            $table->decimal('debt_before', 15, 2);
            $table->decimal('debt_after', 15, 2);
            $table->boolean('auto_unsuspended')->default(false);
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('seller_id', 'fk_debt_repayments_seller')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index(['seller_id', 'created_at'], 'idx_debt_repayments_seller_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_repayments');
    }
};
