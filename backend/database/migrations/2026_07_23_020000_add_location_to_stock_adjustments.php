<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Adjustments become location-aware: correct the shop floor OR the warehouse. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->string('location')->default('shop')->after('type'); // shop | warehouse
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', fn (Blueprint $t) => $t->dropColumn('location'));
    }
};
