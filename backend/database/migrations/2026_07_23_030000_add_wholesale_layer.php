<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale (B2B) layer — SAME products, SAME stock, SAME customers table;
 * only pricing and account terms differ. Products gain a wholesale price
 * (fallback: retail); customers gain a type + business/credit fields;
 * sales gain a channel so retail and wholesale share one ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('wholesale_price', 15, 2)->nullable()->after('sale_price');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('type')->default('retail')->after('name'); // retail | wholesale
            $table->string('company_name')->nullable()->after('type');
            $table->string('contact_person')->nullable()->after('company_name');
            $table->string('tax_number')->nullable()->after('address');
            $table->decimal('credit_limit', 15, 2)->default(0)->after('tax_number');
            $table->decimal('balance', 15, 2)->default(0)->after('credit_limit'); // outstanding receivable
            $table->string('payment_terms')->nullable()->after('balance');       // e.g. cash | net-15 | net-30
            $table->text('notes')->nullable()->after('payment_terms');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('channel')->default('retail')->after('status'); // retail | wholesale
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('channel'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn([
            'type', 'company_name', 'contact_person', 'tax_number',
            'credit_limit', 'balance', 'payment_terms', 'notes',
        ]));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('wholesale_price'));
    }
};
