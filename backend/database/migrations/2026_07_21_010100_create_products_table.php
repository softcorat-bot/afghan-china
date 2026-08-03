<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_fa')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable()->index();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('brand')->nullable();
            $table->string('unit')->default('pcs');            // pcs, kg, box, m...
            $table->text('description')->nullable();
            // Shopify-style status: active (sellable) | draft | archived
            $table->string('status')->default('active');
            // Money (base currency, AFN) — locked at nothing here; pricing is live
            $table->decimal('cost_price', 15, 2)->default(0);   // what we paid
            $table->decimal('sale_price', 15, 2)->default(0);   // list / selling price
            $table->decimal('compare_at_price', 15, 2)->nullable(); // strike-through "was" price
            $table->decimal('tax_rate', 5, 2)->default(0);      // % applied at POS
            // Inventory
            $table->boolean('track_inventory')->default(true);
            $table->decimal('stock_qty', 15, 3)->default(0);    // on-hand at the shop
            $table->decimal('min_stock', 15, 3)->default(0);    // low-stock threshold
            $table->string('image')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
