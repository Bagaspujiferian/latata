<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->foreignId('user_bank_account_id')
                ->nullable()
                ->after('user_id')
                ->constrained('user_bank_accounts')
                ->nullOnDelete();

            $table->index(['user_bank_account_id', 'status'], 'idx_wd_bank_account_status');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropIndex('idx_wd_bank_account_status');
            $table->dropConstrainedForeignId('user_bank_account_id');
        });
    }
};
