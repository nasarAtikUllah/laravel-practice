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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->string('payment_method', 10);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('currency', 3)->default('BDT');

            $table->decimal('items_total', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);

            // A copy of the coupon, so editing the coupon later never changes this order.
            $table->string('coupon_code', 40)->nullable();
            $table->string('coupon_type', 20)->nullable();
            $table->decimal('coupon_value', 12, 2)->nullable();
            $table->string('coupon_scope', 20)->nullable();

            // A copy of the delivery option.
            $table->foreignId('shipping_option_id')->nullable()->constrained('shipping_options')->nullOnDelete();
            $table->string('shipping_option_code', 40)->nullable();
            $table->string('shipping_option_name', 100)->nullable();

            $table->string('shipping_name', 100);
            $table->string('shipping_phone', 20);
            $table->string('shipping_address', 500);
            $table->string('shipping_district', 50);
            $table->string('note', 500)->nullable();

            $table->string('idempotency_key', 64);
            $table->timestamp('placed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same key from the same customer can only ever make one order.
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'id']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['payment_method', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
