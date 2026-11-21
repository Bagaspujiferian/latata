<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('title', 140);
            $table->string('slug', 160)->unique();
            $table->string('banner_subtitle', 200)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at'], 'idx_flash_sales_active_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sales');
    }
};
