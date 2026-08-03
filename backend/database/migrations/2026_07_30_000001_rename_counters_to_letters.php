<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner instruction: counters are lettered (Counter A, B, C), not numbered.
 * Renames the seeded seats and the matching cashier display names on
 * machines that already carry the numbered rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = ['Counter 1' => 'Counter A', 'Counter 2' => 'Counter B', 'Counter 3' => 'Counter C'];

        foreach ($map as $old => $new) {
            if (Schema::hasTable('counters')) {
                DB::table('counters')->where('name', $old)->update(['name' => $new]);
            }
            // Cashier logins showed the same numbered name on receipts.
            DB::table('users')->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        $map = ['Counter A' => 'Counter 1', 'Counter B' => 'Counter 2', 'Counter C' => 'Counter 3'];

        foreach ($map as $old => $new) {
            if (Schema::hasTable('counters')) {
                DB::table('counters')->where('name', $old)->update(['name' => $new]);
            }
            DB::table('users')->where('name', $old)->update(['name' => $new]);
        }
    }
};
