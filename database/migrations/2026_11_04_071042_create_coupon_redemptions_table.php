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
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->decimal('discount_amount', 12, 2);
            $table->string('status', 20)->default('reserved');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The same order can never use the same coupon twice.
            $table->unique(['coupon_id', 'reference_type', 'reference_id'], 'coupon_redemptions_reference_unique');
            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
