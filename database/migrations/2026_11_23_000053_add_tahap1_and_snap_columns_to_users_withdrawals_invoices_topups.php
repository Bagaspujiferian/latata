<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone_encrypted')) {
                $table->text('phone_encrypted')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'phone_blind_hash')) {
                $table->char('phone_blind_hash', 64)->nullable()->unique()->after('phone_encrypted');
            }
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            }
            if (! Schema::hasColumn('users', 'admin_subrole')) {
                $table->enum('admin_subrole', ['super_admin', 'finance', 'cs_arbitrator', 'kyc_officer'])
                    ->nullable()
                    ->after('role');
            }
        });

        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('admin_subrole')
            ->update(['admin_subrole' => 'super_admin']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE withdrawal_requests MODIFY COLUMN status ENUM('PENDING', 'MAKER_APPROVED', 'APPROVED_PAID', 'APPROVED', 'REJECTED_REFUNDED') NOT NULL DEFAULT 'PENDING'");
        }

        Schema::table('payment_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_invoices', 'snap_redirect_url')) {
                $table->string('snap_redirect_url', 512)->nullable()->after('snap_token');
            }
        });

        Schema::table('wallet_topups', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallet_topups', 'snap_token')) {
                $table->string('snap_token', 255)->nullable()->after('gateway_reference');
            }
            if (! Schema::hasColumn('wallet_topups', 'snap_redirect_url')) {
                $table->string('snap_redirect_url', 512)->nullable()->after('snap_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['snap_token', 'snap_redirect_url'],
                static fn (string $c): bool => Schema::hasColumn('wallet_topups', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('payment_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_invoices', 'snap_redirect_url')) {
                $table->dropColumn('snap_redirect_url');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('withdrawal_requests')->where('status', 'MAKER_APPROVED')->update(['status' => 'PENDING']);
            DB::table('withdrawal_requests')->where('status', 'APPROVED')->update(['status' => 'APPROVED_PAID']);
            DB::statement("ALTER TABLE withdrawal_requests MODIFY COLUMN status ENUM('PENDING', 'APPROVED_PAID', 'REJECTED_REFUNDED') NOT NULL DEFAULT 'PENDING'");
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'phone_blind_hash')) {
                $table->dropUnique(['phone_blind_hash']);
            }
            $cols = array_values(array_filter(
                ['phone_encrypted', 'phone_blind_hash', 'phone_verified_at', 'admin_subrole'],
                static fn (string $c): bool => Schema::hasColumn('users', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
