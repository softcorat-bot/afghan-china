<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 4-language support: English, Farsi, Pashto, Chinese (Simplified)
 * Each user has their preferred language setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('language', ['en', 'fa', 'ps', 'zh'])->default('en')->after('pin');
        });

        // Create translations table for dynamic content (counter names, branch names, etc.)
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translatable_type'); // Counter, Branch, etc.
            $table->unsignedBigInteger('translatable_id');
            $table->enum('language', ['en', 'fa', 'ps', 'zh']);
            $table->string('field'); // 'name', 'description', etc.
            $table->text('value');
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'language', 'field']);
            $table->index(['translatable_type', 'translatable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('language');
        });
        Schema::dropIfExists('translations');
    }
};
