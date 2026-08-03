<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical counter seats (Counter 1, 2, 3, …). A counter is a PLACE, not a
 * person — any cashier (Ahmad today, Ali tomorrow) opens their shift ON a
 * counter, and every sale carries both the worker (user_id) and the seat
 * (counter_id), so performance can be judged per counter AND per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('name');                 // "Counter 1"
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('shifts', fn (Blueprint $t) => $t->unsignedBigInteger('counter_id')->nullable()->after('branch_id')->index());
        Schema::table('sales', fn (Blueprint $t) => $t->unsignedBigInteger('counter_id')->nullable()->after('shift_id')->index());
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('counter_id'));
        Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('counter_id'));
        Schema::dropIfExists('counters');
    }
};
