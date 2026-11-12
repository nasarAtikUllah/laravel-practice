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
        // MySQL does not undo schema changes when a migration fails half-way,
        // so each step first checks whether it was already done.
        if (! $this->hasIndex('brands', 'brands_name_unique')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->unique('name', 'brands_name_unique');
            });
        }

        if (! $this->hasIndex('collections', 'collections_brand_id_name_unique')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->unique(['brand_id', 'name'], 'collections_brand_id_name_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->hasIndex('brands', 'brands_name_unique')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropUnique('brands_name_unique');
            });
        }

        if ($this->hasIndex('collections', 'collections_brand_id_name_unique')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->dropUnique('collections_brand_id_name_unique');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name);
    }
};
