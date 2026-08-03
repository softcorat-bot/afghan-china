<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counter PIN sign-in. The PIN is stored HASHED (never plain) and is a
 * fast second door for register staff — typing an email + password on a
 * touch screen between customers costs real seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('password');
            $table->timestamp('pin_set_at')->nullable()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['pin', 'pin_set_at']));
    }
};
