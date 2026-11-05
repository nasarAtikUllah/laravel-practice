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
        Schema::table('brands', function (Blueprint $table) {
            $table->unique('name', 'brands_name_unique');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->unique(['brand_id', 'name'], 'collections_brand_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique('brands_name_unique');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique('collections_brand_id_name_unique');
        });
    }
};
