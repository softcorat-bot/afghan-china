<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency_code', 10);          // the foreign currency, e.g. USD
            $table->decimal('rate_to_base', 16, 4);        // 1 unit of currency_code = rate_to_base of base (e.g. 1 USD = 70 AFN)
            $table->date('rate_date');
            $table->timestamps();
            $table->unique(['company_id', 'currency_code', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
