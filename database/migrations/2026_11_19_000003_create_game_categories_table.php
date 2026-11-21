<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->enum('delivery_method', ['instant_vault', 'manual_trade', 'api_h2h']);
            $table->decimal('platform_fee_percent', 5, 2)->default(5.00);
            $table->json('required_inputs_schema')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'slug'], 'uq_game_category_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_categories');
    }
};
