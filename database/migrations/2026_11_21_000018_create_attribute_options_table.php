<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_options', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('category_attribute_id');
            $table->string('label', 80);
            $table->string('value_slug', 80);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('category_attribute_id', 'fk_ao_attribute')
                ->references('id')
                ->on('category_attributes')
                ->cascadeOnDelete();

            $table->unique(['category_attribute_id', 'value_slug'], 'uq_attr_opt_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_options');
    }
};
