<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the register behaves — which helpers are on, whether the next sale
 * starts on a timer, which banknotes the tender pad offers. Edited in the app
 * so a shop can tune its own counter without a developer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('pos_settings')->nullable()->after('receipt_settings');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pos_settings');
        });
    }
};
