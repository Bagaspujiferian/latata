<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('store_name');
            $table->string('slug')->unique();
            $table->string('store_tagline', 150)->nullable();
            $table->unsignedInteger('store_level')->default(1);
            $table->unsignedInteger('total_completed_orders')->default(0);
            $table->unsignedInteger('total_cancelled_orders')->default(0);
            $table->decimal('avg_delivery_minutes', 6, 2)->default(5.00);
            $table->boolean('is_sla_10m_badge')->default(true);
            $table->boolean('is_store_open')->default(true);
            $table->unsignedInteger('ad_credits')->default(10);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};
