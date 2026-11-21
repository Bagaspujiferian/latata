<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->index('deleted_at', 'idx_products_deleted');
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('store_banner_path', 255)->nullable()->after('store_tagline');
            $table->string('store_bio', 500)->nullable()->after('store_banner_path');
            $table->boolean('auto_schedule_enabled')->default(false)->after('is_store_open');
        });

        Schema::table('order_reviews', function (Blueprint $table) {
            $table->text('seller_reply')->nullable()->after('comment');
            $table->timestamp('replied_at')->nullable()->after('seller_reply');
        });
    }

    public function down(): void
    {
        Schema::table('order_reviews', function (Blueprint $table) {
            $table->dropColumn(['seller_reply', 'replied_at']);
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['store_banner_path', 'store_bio', 'auto_schedule_enabled']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_deleted');
            $table->dropColumn('deleted_at');
        });
    }
};
