<?php

namespace App\Services\Sync\Handlers;

use App\Models\CounterEndOfDay;
use App\Models\PosDevice;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Carbon;

/**
 * The end-of-day reconciliation a cashier completed while offline. It is a
 * statement of what happened at that till; it is appended, never rewritten.
 * Approving or rejecting it stays a server-side, permission-gated action.
 */
class CounterEndOfDayHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'counter_end_of_day';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];

        $existing = CounterEndOfDay::where('uuid', $uuid)->first();
        if ($existing) {
            return $this->duplicate($existing, 'This end-of-day report was already synchronized.');
        }

        $counter = isset($payload['counter_uuid'])
            ? \App\Models\Counter::where('uuid', $payload['counter_uuid'])->first()
            : null;

        if (! $counter) {
            return $this->reject('The counter this report belongs to is unknown centrally — pull the counters first.');
        }

        $report = CounterEndOfDay::create(ColumnGuard::only('counter_end_of_day', array_merge($this->provenance($device, $change), [
            'company_id' => $this->companyId($device),
            'counter_id' => $counter->id,
            'branch_id' => $this->branchId($device),
            'user_id' => $this->user($payload['user_uuid'] ?? null)?->id,
            'report_date' => isset($payload['report_date']) ? Carbon::parse($payload['report_date'])->toDateString() : now()->toDateString(),
            'submitted_at' => isset($payload['submitted_at']) ? Carbon::parse($payload['submitted_at']) : now(),
            'opening_float' => round((float) ($payload['opening_float'] ?? 0), 2),
            'cash_sales' => round((float) ($payload['cash_sales'] ?? 0), 2),
            'card_sales' => round((float) ($payload['card_sales'] ?? 0), 2),
            'mobile_sales' => round((float) ($payload['mobile_sales'] ?? 0), 2),
            'expected_cash' => round((float) ($payload['expected_cash'] ?? 0), 2),
            'counted_cash' => round((float) ($payload['counted_cash'] ?? 0), 2),
            'variance' => round((float) ($payload['variance'] ?? 0), 2),
            'cash_in' => round((float) ($payload['cash_in'] ?? 0), 2),
            'cash_out' => round((float) ($payload['cash_out'] ?? 0), 2),
            'total_income' => round((float) ($payload['total_income'] ?? 0), 2),
            'total_expense' => round((float) ($payload['total_expense'] ?? 0), 2),
            'net_profit' => round((float) ($payload['net_profit'] ?? 0), 2),
            'note' => $payload['note'] ?? null,
            'status' => 'submitted',
        ])));

        return $this->result('applied', [
            'server_id' => $report->id,
            'server_uuid' => $report->uuid,
            'server_row' => $this->row($report),
            'message' => "End-of-day report for {$counter->name} accepted.",
        ]);
    }
}
