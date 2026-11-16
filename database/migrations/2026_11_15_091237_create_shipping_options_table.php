<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shipping_options', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // never changes; soft-deleted codes stay reserved
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('estimated_days', 50)->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_options');
    }
};
