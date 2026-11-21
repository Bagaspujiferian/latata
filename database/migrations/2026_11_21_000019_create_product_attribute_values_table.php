<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_attribute_id');
            $table->unsignedBigInteger('attribute_option_id')->nullable();
            $table->integer('numeric_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->string('text_value', 150)->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'fk_pav_product')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('category_attribute_id', 'fk_pav_attribute')
                ->references('id')
                ->on('category_attributes')
                ->cascadeOnDelete();

            $table->foreign('attribute_option_id', 'fk_pav_option')
                ->references('id')
                ->on('attribute_options')
                ->nullOnDelete();

            $table->unique(['product_id', 'category_attribute_id'], 'uq_product_attr');
            $table->index(['category_attribute_id', 'attribute_option_id', 'product_id'], 'idx_pav_select_filter');
            $table->index(['category_attribute_id', 'numeric_value', 'product_id'], 'idx_pav_numeric_filter');
            $table->index(['category_attribute_id', 'boolean_value', 'product_id'], 'idx_pav_bool_filter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
