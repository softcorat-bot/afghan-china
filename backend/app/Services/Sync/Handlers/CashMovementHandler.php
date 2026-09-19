<?php

namespace App\Services\Sync\Handlers;

use App\Models\CashMovement;
use App\Models\PosDevice;
use App\Models\Shift;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Facades\DB;

/**
 * Cash in / cash out against a drawer session, rung offline.
 *
 * The movement is the truth — the session's running totals are derived from the
 * movements plus the sales, so a till that was offline simply contributes more
 * movements.
 */
class CashMovementHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'cash_movement';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];
        $companyId = $this->companyId($device);

        $existing = CashMovement::where('uuid', $uuid)->first();
        if ($existing) {
            return $this->duplicate($existing, 'This cash movement was already synchronized.');
        }

        $type = ($payload['type'] ?? 'out') === 'in' ? 'in' : 'out';
        $amount = round((float) ($payload['amount'] ?? 0), 2);

        if ($amount <= 0) {
            return $this->reject('A cash movement must carry a positive amount.');
        }

        $shift = $this->shift($payload['shift_uuid'] ?? null);

        if (! $shift) {
            return $this->reject('The drawer session this movement belongs to has not reached the server yet — sync the session first, then retry.');
        }

        $movement = DB::transaction(function () use ($device, $change, $payload, $shift, $type, $amount, $companyId) {
            $movement = CashMovement::create(ColumnGuard::only('cash_movements', array_merge($this->provenance($device, $change), [
                'company_id' => $companyId,
                'shift_id' => $shift->id,
                'user_id' => $this->user($payload['user_uuid'] ?? null)?->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $payload['reason'] ?? null,
                'note' => $payload['note'] ?? null,
            ])));

            // Keep the session's totals in step, exactly like the web register does.
            Shift::whereKey($shift->id)->update([
                $type === 'in' ? 'cash_in' : 'cash_out' => DB::raw(($type === 'in' ? 'cash_in' : 'cash_out').' + '.$amount),
            ]);

            return $movement;
        });

        return $this->result('applied', [
            'server_id' => $movement->id,
            'server_uuid' => $movement->uuid,
            'server_row' => $this->row($movement),
            'message' => "Cash {$type} of {$amount} recorded.",
        ]);
    }
}
