<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR layer on top of USERS (the staff are the system users — counters,
 * finance, admins). Daily attendance (one row per person per day) feeds
 * monthly payroll runs; an unpaid absence prefills a deduction of
 * basic/30 per missed day, editable until the run is marked paid.
 * Modeled on the proven Aria Herat payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('basic_salary', 15, 2)->default(0)->after('type');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('att_date');
            $table->string('status')->default('present'); // present | absent | leave
            $table->text('note')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'att_date']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);                  // YYYY-MM
            $table->string('status')->default('draft');   // draft | paid
            $table->string('currency', 10)->default('AFN');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'period']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic', 15, 2)->default(0);
            $table->decimal('allowances', 15, 2)->default(0);
            $table->decimal('bonus', 15, 2)->default(0);
            $table->decimal('overtime', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);   // prefilled from absences
            $table->integer('present_days')->default(0);
            $table->integer('absent_days')->default(0);
            $table->integer('leave_days')->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('attendance_records');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('basic_salary'));
    }
};
