<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_operating_hours', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('open_time')->default('08:00:00');
            $table->time('close_time')->default('22:00:00');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->foreign('seller_id', 'fk_soh_seller')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->unique(['seller_id', 'day_of_week'], 'uq_seller_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_operating_hours');
    }
};
