<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->nullable()->after('amount');
        });

        DB::table('payments')
            ->where('status', 'succeeded')
            ->whereNull('paid_amount')
            ->update(['paid_amount' => DB::raw('amount')]);

        DB::table('payments')
            ->where('review_reason', 'amount_mismatch')
            ->whereNull('paid_amount')
            ->orderBy('id')
            ->chunkById(500, function ($payments): void {
                foreach ($payments as $payment) {
                    $payload = DB::table('payment_events')
                        ->where('payment_id', $payment->id)
                        ->where('type', 'succeeded')
                        ->latest('id')
                        ->value('payload');
                    $event = is_string($payload) ? json_decode($payload, true) : null;

                    if (is_array($event) && isset($event['amount']) && is_numeric($event['amount'])) {
                        DB::table('payments')
                            ->where('id', $payment->id)
                            ->update(['paid_amount' => number_format((float) $event['amount'], 2, '.', '')]);
                    }
                }
            });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->index();
            $table->string('reason', 255);
            $table->string('provider_refund_id', 100)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('idempotency_key', 100);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'idempotency_key']);
            $table->index(['order_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
