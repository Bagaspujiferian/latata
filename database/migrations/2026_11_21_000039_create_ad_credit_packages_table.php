<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_credit_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('credits_amount');
            $table->unsignedInteger('bonus_credits')->default(0);
            $table->decimal('price', 15, 2);
            $table->string('badge_label', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'idx_ad_packages_active_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_credit_packages');
    }
};
