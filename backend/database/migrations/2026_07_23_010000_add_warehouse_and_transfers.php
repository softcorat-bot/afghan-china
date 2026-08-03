<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-location stock: `stock_qty` is the SHOP floor (what POS sells);
 * `warehouse_qty` is the reserve. Numbered transfer documents move stock
 * between the two and form the transfer history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('warehouse_qty', 15, 3)->default(0)->after('stock_qty');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('reference');                    // TRF-000001
            $table->string('direction');                    // to_store | to_warehouse
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_qty', 15, 3)->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');                         // snapshot at transfer time
            $table->string('barcode')->nullable();
            $table->decimal('qty', 15, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('warehouse_qty'));
    }
};
