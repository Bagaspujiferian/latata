<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_audit_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('audit_code', 40)->unique();
            $table->enum('status', ['BALANCED', 'DISCREPANCY']);
            $table->decimal('total_wallets_pending', 18, 2);
            $table->decimal('total_wallets_available', 18, 2);
            $table->decimal('total_wallets_deposit', 18, 2);
            $table->decimal('total_custody_wallets', 18, 2);
            $table->decimal('total_ledger_net', 18, 2);
            $table->decimal('discrepancy_amount', 18, 2);
            $table->unsignedInteger('users_checked');
            $table->json('discrepancy_details')->nullable();
            $table->string('triggered_by', 60);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'created_at'], 'idx_ledger_audit_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_audit_snapshots');
    }
};
