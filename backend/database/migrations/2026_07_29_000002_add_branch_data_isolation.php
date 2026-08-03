<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New branches start with zero income/expense data but keep customers and products.
 * This allows independent financial tracking per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dateTime('financial_start_date')->nullable()->after('active');
            $table->boolean('share_catalog')->default(true)->after('financial_start_date'); // Share products across all company branches
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['financial_start_date', 'share_catalog']);
        });
    }
};
