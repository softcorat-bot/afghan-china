<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reference']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('qty', 15, 3);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('returned_qty', 15, 3)->default(0)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', fn (Blueprint $t) => $t->dropColumn('returned_qty'));
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
