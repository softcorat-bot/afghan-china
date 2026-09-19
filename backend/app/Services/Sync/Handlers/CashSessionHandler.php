<?php

namespace App\Services\Sync\Handlers;

use App\Models\PosDevice;
use App\Models\Shift;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Carbon;

/**
 * A drawer session opened and/or closed with no internet.
 *
 * Two cashiers on two devices are two sessions; they are never merged. A session
 * that was already closed centrally is not silently re-closed with different
 * numbers — the money is a historical record, so a second, different version is
 * raised as a critical conflict.
 */
class CashSessionHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'cash_session';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];

        $existing = Shift::where('uuid', $uuid)->first();

        if ($existing) {
            return $this->updateSession($existing, $payload, $device, $uuid);
        }

        $shift = Shift::create(ColumnGuard::only('shifts', array_merge($this->provenance($device, $change), [
            'company_id' => $this->companyId($device),
            'branch_id' => $this->branchId($device),
            'user_id' => $this->user($payload['user_uuid'] ?? null)?->id,
            'opened_at' => isset($payload['opened_at']) ? Carbon::parse($payload['opened_at']) : now(),
            'closed_at' => isset($payload['closed_at']) ? Carbon::parse($payload['closed_at']) : null,
            'opening_float' => round((float) ($payload['opening_float'] ?? 0), 2),
            'counted_cash' => isset($payload['counted_cash']) ? round((float) $payload['counted_cash'], 2) : null,
            'expected_cash' => isset($payload['expected_cash']) ? round((float) $payload['expected_cash'], 2) : null,
            'variance' => isset($payload['variance']) ? round((float) $payload['variance'], 2) : null,
            'cash_sales' => round((float) ($payload['cash_sales'] ?? 0), 2),
            'card_sales' => round((float) ($payload['card_sales'] ?? 0), 2),
            'mobile_sales' => round((float) ($payload['mobile_sales'] ?? 0), 2),
            'cash_in' => round((float) ($payload['cash_in'] ?? 0), 2),
            'cash_out' => round((float) ($payload['cash_out'] ?? 0), 2),
            'total_sales' => round((float) ($payload['total_sales'] ?? 0), 2),
            'orders_count' => (int) ($payload['orders_count'] ?? 0),
            'status' => ($payload['status'] ?? 'open') === 'closed' ? 'closed' : 'open',
            'note' => $payload['note'] ?? null,
        ])));

        return $this->result('applied', [
            'server_id' => $shift->id,
            'server_uuid' => $shift->uuid,
            'server_row' => $this->row($shift),
            'message' => "Cash session {$shift->status} accepted.",
        ]);
    }

    /** Closing a session that already exists centrally — including one closed on the web. */
    private function updateSession(Shift $shift, array $payload, PosDevice $device, string $uuid): array
    {
        if ($shift->status === 'closed' && isset($payload['counted_cash'])
            && abs((float) $shift->counted_cash - (float) $payload['counted_cash']) > 0.01) {
            $conflict = $this->conflicts->raiseFinancial('cash_session', $uuid, $payload, $this->row($shift), $device,
                'A closed cash session arrived again with a different counted amount.');

            return $this->result('conflict', [
                'conflict_id' => $conflict->id,
                'server_row' => $this->row($shift),
                'message' => 'This drawer session is already closed centrally with different figures; nothing was overwritten.',
            ]);
        }

        if ($shift->status === 'open' && ($payload['status'] ?? 'open') === 'closed') {
            $shift->fill(ColumnGuard::only('shifts', [
                'closed_at' => isset($payload['closed_at']) ? Carbon::parse($payload['closed_at']) : now(),
                'counted_cash' => round((float) ($payload['counted_cash'] ?? 0), 2),
                'expected_cash' => round((float) ($payload['expected_cash'] ?? 0), 2),
                'variance' => round((float) ($payload['variance'] ?? 0), 2),
                'cash_sales' => round((float) ($payload['cash_sales'] ?? $shift->cash_sales), 2),
                'card_sales' => round((float) ($payload['card_sales'] ?? $shift->card_sales), 2),
                'mobile_sales' => round((float) ($payload['mobile_sales'] ?? $shift->mobile_sales), 2),
                'cash_in' => round((float) ($payload['cash_in'] ?? $shift->cash_in), 2),
                'cash_out' => round((float) ($payload['cash_out'] ?? $shift->cash_out), 2),
                'total_sales' => round((float) ($payload['total_sales'] ?? $shift->total_sales), 2),
                'orders_count' => (int) ($payload['orders_count'] ?? $shift->orders_count),
                'status' => 'closed',
                'device_id' => $device->device_id,
                'origin' => 'offline',
                'synced_at' => now(),
            ]))->save();

            return $this->result('applied', [
                'server_id' => $shift->id,
                'server_uuid' => $shift->uuid,
                'server_row' => $this->row($shift),
                'message' => 'Cash session closed.',
            ]);
        }

        return $this->duplicate($shift, 'Cash session is already up to date.');
    }
}
