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
        Schema::create('coupon_brand', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->primary(['coupon_id', 'brand_id']);
            $table->index('brand_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_brand');
    }
};
