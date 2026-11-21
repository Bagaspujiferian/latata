<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('claim_code', 40)->unique();
            $table->foreignId('order_warranty_id')->constrained('order_warranties')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->enum('reason_category', [
                'account_reclaimed_hackback',
                'password_email_changed',
                'banned_prior_violation',
                'other',
            ]);
            $table->text('description');
            $table->json('evidence_paths')->nullable();
            $table->enum('status', ['open', 'investigating', 'approved_refund', 'rejected'])->default('open');
            $table->decimal('clawback_from_deposit', 15, 2)->default(0.00);
            $table->decimal('clawback_from_available', 15, 2)->default(0.00);
            $table->decimal('recorded_seller_debt', 15, 2)->default(0.00);
            $table->text('admin_resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('seller_id', 'fk_wc_seller_profile')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index(['status', 'created_at'], 'idx_wc_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
