<?php

namespace App\Services\Sync\Handlers;

use App\Models\CounterEndOfDay;
use App\Models\Expense;
use App\Models\PosDevice;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Carbon;

/**
 * Small append-only handlers: money that left the drawer, and the end-of-day
 * reconciliation a cashier completed with no internet. Both are records of
 * something that already happened, so a duplicate uuid is a replay and anything
 * else is a new row — never an overwrite.
 */
class ExpenseHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'expense';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];

        $existing = Expense::withTrashed()->where('uuid', $uuid)->first();
        if ($existing) {
            return $this->duplicate($existing, 'This expense was already synchronized.');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return $this->reject('An expense must carry a positive amount.');
        }

        $expense = Expense::create(ColumnGuard::only('expenses', array_merge($this->provenance($device, $change), [
            'company_id' => $this->companyId($device),
            'branch_id' => $this->branchId($device),
            'user_id' => $this->user($payload['user_uuid'] ?? null)?->id,
            'spent_on' => isset($payload['spent_on']) ? Carbon::parse($payload['spent_on'])->toDateString() : now()->toDateString(),
            'category' => $payload['category'] ?? 'misc',
            'payee' => $payload['payee'] ?? null,
            'amount' => $amount,
            'method' => $payload['method'] ?? 'cash',
            'reference' => $payload['reference'] ?? null,
            'note' => $payload['note'] ?? null,
        ])));

        return $this->result('applied', [
            'server_id' => $expense->id,
            'server_uuid' => $expense->uuid,
            'server_row' => $this->row($expense),
            'message' => "Expense of {$amount} recorded.",
        ]);
    }
}
