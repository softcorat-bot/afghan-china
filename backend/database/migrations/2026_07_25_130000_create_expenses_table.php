<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop running costs — rent, power, transport, wages paid in cash, repairs.
 * Kept separate from purchases: a purchase buys stock to sell, an expense is
 * money that leaves the business without becoming inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('spent_on');
            $table->string('category', 60);
            $table->string('payee')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('method', 20)->default('cash');   // cash | bank | mobile
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
