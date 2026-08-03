<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product Expiry Date Tracking
 * For categories like medicine, food, cosmetics, etc. that have shelf life.
 * Non-expiry categories (electronics, stationery) leave expiry_date NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mark categories that require expiry tracking
        Schema::create('expiry_tracked_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->boolean('require_expiry')->default(true);
            $table->integer('warning_days')->default(30); // Alert when X days to expiry
            $table->timestamps();

            $table->unique(['company_id', 'category_id']);
        });

        // Add expiry date columns to products table
        Schema::table('products', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->after('image');
            $table->date('manufacture_date')->nullable()->after('expiry_date');
            $table->integer('shelf_life_days')->nullable()->after('manufacture_date');
            $table->boolean('track_expiry')->default(false)->after('shelf_life_days');
        });

        // Inventory item expiry tracking
        Schema::create('inventory_expiry_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('batch_number')->unique(); // Batch/lot number
            $table->date('manufacture_date');
            $table->date('expiry_date');
            $table->decimal('quantity_received', 12, 2);
            $table->decimal('quantity_available', 12, 2); // Available (not sold)
            $table->decimal('quantity_sold', 12, 2)->default(0);
            $table->decimal('quantity_discarded', 12, 2)->default(0);

            // Cost tracking per batch
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 12, 2); // quantity_received * unit_cost

            // Status tracking
            $table->enum('status', ['active', 'expired', 'discarded', 'recalled'])->default('active');
            $table->dateTime('expired_at')->nullable(); // When it was marked as expired
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'expiry_date']);
            $table->index(['product_id', 'status']);
            $table->index(['branch_id', 'expiry_date']);
        });

        // Expiry alerts & notifications
        Schema::create('expiry_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('inventory_expiry_batches')->cascadeOnDelete();

            $table->enum('alert_type', ['expiring_soon', 'expired', 'low_quantity'])->default('expiring_soon');
            $table->integer('days_until_expiry'); // How many days until expiry
            $table->boolean('acknowledged')->default(false);
            $table->dateTime('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'alert_type']);
            $table->index(['acknowledged']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_alerts');
        Schema::dropIfExists('inventory_expiry_batches');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['expiry_date', 'manufacture_date', 'shelf_life_days', 'track_expiry']);
        });
        Schema::dropIfExists('expiry_tracked_categories');
    }
};
