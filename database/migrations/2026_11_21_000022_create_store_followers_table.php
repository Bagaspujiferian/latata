<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_followers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('seller_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'seller_id']);

            $table->foreign('user_id', 'fk_sf_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('seller_id', 'fk_sf_seller')
                ->references('user_id')
                ->on('seller_profiles')
                ->cascadeOnDelete();

            $table->index('seller_id', 'idx_follower_seller');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_followers');
    }
};
