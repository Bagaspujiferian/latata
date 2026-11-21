<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_attributes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('game_category_id');
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->enum('input_type', ['select', 'number', 'boolean', 'text']);
            $table->string('unit_label', 30)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('game_category_id', 'fk_ca_category')
                ->references('id')
                ->on('game_categories')
                ->cascadeOnDelete();

            $table->unique(['game_category_id', 'slug'], 'uq_cat_attr_slug');
            $table->index(['game_category_id', 'is_filterable', 'sort_order'], 'idx_cat_attr_filter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_attributes');
    }
};
