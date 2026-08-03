<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-only "Main Cost" layer. `products.main_price` is the owner's private
 * real buy price (always below the declared cost_price) — hidden from every
 * normal API payload and surfaced only through the gated owner endpoints.
 * Every change is journaled in main_price_logs for the per-person history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('main_price', 15, 2)->nullable()->after('cost_price');
        });

        Schema::create('main_price_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('old_price', 15, 2)->nullable();
            $table->decimal('new_price', 15, 2);
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('main_price_logs');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('main_price');
        });
    }
};
