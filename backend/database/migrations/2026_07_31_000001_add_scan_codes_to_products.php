<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra codes a scanner may read off a carton. A shop rarely has one code per
 * product: the manufacturer's EAN is on the box, the supplier sticks their own
 * label on it, and the shop prints a third for the shelf. All of them must find
 * the same product.
 *
 * `extra_codes` is the future-proof slot — any number of additional codes, so a
 * new labelling scheme never needs another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'barcode2')) {
                $table->string('barcode2')->nullable()->after('barcode');
            }
            if (! Schema::hasColumn('products', 'internal_code')) {
                $table->string('internal_code')->nullable()->after('barcode2');
            }
            if (! Schema::hasColumn('products', 'extra_codes')) {
                $table->json('extra_codes')->nullable()->after('internal_code');
            }
        });

        // Scanning happens on every keystroke of a burst — these lookups have to
        // hit an index, not a table scan.
        Schema::table('products', function (Blueprint $table) {
            $table->index(['company_id', 'barcode2']);
            $table->index(['company_id', 'internal_code']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'barcode2']);
            $table->dropIndex(['company_id', 'internal_code']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['barcode2', 'internal_code', 'extra_codes']);
        });
    }
};
