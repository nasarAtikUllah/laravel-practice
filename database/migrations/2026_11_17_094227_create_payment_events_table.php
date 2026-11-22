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
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('event_id', 100);
            $table->string('type', 50);
            $table->json('payload');
            $table->string('result', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The same notification is processed once, however often it is sent.
            $table->unique(['provider', 'event_id']);
            $table->index('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
