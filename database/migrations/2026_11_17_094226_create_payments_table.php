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

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('reference', 40)->unique();              // our tran_id
            $table->string('provider_reference', 100)->nullable();  // SSLCommerz bank_tran_id, needed for refunds
            $table->string('session_key', 100)->nullable();
            $table->string('validation_id', 100)->nullable();       // SSLCommerz val_id
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BDT');
            $table->string('status', 20);
            $table->string('checkout_url', 1000)->nullable();
            $table->string('card_type', 100)->nullable();
            $table->unsignedTinyInteger('risk_level')->nullable();
            $table->string('failure_reason', 255)->nullable();
            // Money that needs a person: late_payment, order_cancelled, duplicate_payment, amount_mismatch.
            $table->string('review_reason', 50)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['order_id', 'id']);
            $table->index(['status', 'created_at']);
            $table->index('review_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
