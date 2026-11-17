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
        if (! $this->hasIndex('orders', 'orders_shipping_phone_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('shipping_phone', 'orders_shipping_phone_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->hasIndex('orders', 'orders_shipping_phone_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_shipping_phone_index');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name);
    }
};
