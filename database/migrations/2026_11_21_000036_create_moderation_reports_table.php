<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_reports', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->enum('target_type', ['product', 'seller', 'review']);
            $table->unsignedBigInteger('target_id');
            $table->enum('reason_category', [
                'fraud_scam',
                'fake_listing',
                'illegal_account',
                'off_platform_bypass',
                'abusive_content',
            ]);
            $table->text('details');
            $table->enum('status', ['open', 'action_taken', 'dismissed'])->default('open');
            $table->string('resolution_notes', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'target_type', 'created_at'], 'idx_mod_reports_status_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_reports');
    }
};
