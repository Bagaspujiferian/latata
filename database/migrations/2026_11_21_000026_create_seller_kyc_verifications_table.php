<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_kyc_verifications', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('active_user_lock')->nullable()->unique('uq_kyc_active_user_lock');
            $table->string('proposed_store_name', 100);
            $table->string('proposed_store_slug', 120);
            $table->string('proposed_store_tagline', 150)->nullable();
            $table->string('full_legal_name', 120);
            $table->text('nik_encrypted');
            $table->char('nik_last4', 4);
            $table->char('nik_blind_hash', 64);
            $table->char('active_nik_lock_hash', 64)->nullable()->unique('uq_kyc_active_nik_lock');
            $table->string('id_card_private_path', 255);
            $table->string('selfie_private_path', 255);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_kyc_status_created');
            $table->index('nik_blind_hash', 'idx_kyc_nik_blind_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_kyc_verifications');
    }
};
