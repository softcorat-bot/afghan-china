<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counter End-of-Day Report: Daily cash reconciliation per counter.
 * Cashier submits opening float, counted cash, and system calculates variance.
 * This replaces user-based reporting with counter-based daily settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_end_of_day', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // Cashier who closed the counter

            $table->date('report_date'); // Which day's report is this
            $table->dateTime('submitted_at')->nullable(); // When the report was submitted

            // Till reconciliation
            $table->decimal('opening_float', 12, 2)->default(0); // Starting cash in drawer
            $table->decimal('cash_sales', 12, 2)->default(0); // Cash sales from system
            $table->decimal('card_sales', 12, 2)->default(0); // Card sales (reference only)
            $table->decimal('mobile_sales', 12, 2)->default(0); // Mobile money sales (reference)
            $table->decimal('expected_cash', 12, 2); // opening_float + cash_sales (what should be in drawer)
            $table->decimal('counted_cash', 12, 2); // What cashier counted manually
            $table->decimal('variance', 12, 2); // expected_cash - counted_cash (positive = overage, negative = shortage)

            // Cash movements
            $table->decimal('cash_in', 12, 2)->default(0); // Withdrawals, refunds, etc.
            $table->decimal('cash_out', 12, 2)->default(0); // Payouts, loans, etc.

            // Daily totals
            $table->decimal('total_income', 12, 2)->default(0); // Sales revenue
            $table->decimal('total_expense', 12, 2)->default(0); // Counter expenses
            $table->decimal('net_profit', 12, 2)->default(0); // income - expense

            // Status
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->text('notes')->nullable(); // Cashier notes about variance
            $table->text('rejection_reason')->nullable(); // Why manager rejected it

            // Auditing
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'report_date']);
            $table->index(['counter_id', 'report_date']);
            $table->index(['branch_id', 'report_date']);
            $table->unique(['counter_id', 'report_date']); // One report per counter per day
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_end_of_day');
    }
};
