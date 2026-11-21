<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bank_accounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('bank_code', 30);
            $table->string('bank_name', 60);
            $table->string('account_number', 50);
            $table->string('account_holder_name', 100);
            $table->boolean('is_verified')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_primary', 'deleted_at'], 'idx_uba_user_primary_deleted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bank_accounts');
    }
};
