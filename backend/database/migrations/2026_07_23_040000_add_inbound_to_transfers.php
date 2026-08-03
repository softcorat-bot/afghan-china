<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound receiving: stock can arrive from an external source (a person,
 * company or country) straight into the warehouse or the shop — a third
 * movement beside warehouse⇄store. `source` names where it came from;
 * `destination` is which location received it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('source')->nullable()->after('direction');      // e.g. "Guangzhou — Li Wei Trading"
            $table->string('destination')->nullable()->after('source');    // warehouse | shop (inbound only)
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', fn (Blueprint $t) => $t->dropColumn(['source', 'destination']));
    }
};
