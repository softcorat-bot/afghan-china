<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_fa')->nullable();
            $table->string('name_pa')->nullable();
            $table->string('abbreviation')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('logo')->nullable();
            $table->string('currency')->nullable();
            $table->string('lang')->default('en');
            $table->string('calendar_type')->default('en');
            $table->string('business_type')->nullable();
            $table->date('financial_start_date')->nullable();
            $table->date('financial_end_date')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
