<?php

namespace App\Services\Sync\Handlers;

use App\Models\Customer;
use App\Models\PosDevice;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Facades\DB;

/**
 * A customer opened at the till while offline.
 *
 * Creating is safe and idempotent by uuid. Editing an existing customer goes
 * through the conflict policy: the fields a cashier may legitimately correct
 * (name, phone, address) are merged; loyalty and spend figures are server-owned
 * and can never be pushed from a device.
 */
class CustomerHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'customer';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];
        $companyId = $this->companyId($device);

        $existing = Customer::withTrashed()->where('uuid', $uuid)->first();

        if (! $existing) {
            if (($change['operation'] ?? 'create') === 'delete') {
                return $this->result('applied', ['message' => 'Nothing to delete.']);
            }

            if (empty($payload['name'])) {
                return $this->reject('A customer needs a name.');
            }

            $customer = Customer::create(ColumnGuard::only('customers', array_merge($this->provenance($device, $change), [
                'company_id' => $companyId,
                'name' => $payload['name'],
                'phone' => $payload['phone'] ?? null,
                'email' => $payload['email'] ?? null,
                'address' => $payload['address'] ?? null,
                'note' => $payload['note'] ?? null,
                'type' => $payload['type'] ?? 'retail',
            ])));

            return $this->result('applied', [
                'server_id' => $customer->id,
                'server_uuid' => $customer->uuid,
                'server_row' => $this->row($customer),
                'message' => "Customer {$customer->name} created.",
            ]);
        }

        if (($change['operation'] ?? 'create') === 'delete') {
            $existing->delete();

            return $this->result('applied', ['message' => 'Customer removed.']);
        }

        $decision = $this->conflicts->reconcileMaster('customer', $existing, $payload, $device, $uuid);

        if ($decision['action'] === 'unchanged') {
            return $this->duplicate($existing, $decision['message'] ?? 'Customer is already up to date.');
        }

        if ($decision['action'] === 'server_wins') {
            return $this->result('conflict', [
                'conflict_id' => $decision['conflict']?->id,
                'server_row' => $this->row($existing),
                'message' => $decision['message'],
            ]);
        }

        if (! empty($decision['payload'])) {
            DB::transaction(fn () => $existing->fill($decision['payload'])
                ->forceFill(['origin' => 'offline', 'device_id' => $device->device_id, 'synced_at' => now()])
                ->save());
        }

        return $this->result($decision['action'] === 'conflict' ? 'conflict' : 'applied', [
            'server_id' => $existing->id,
            'server_uuid' => $existing->uuid,
            'server_row' => $this->row($existing->fresh()),
            'conflict_id' => $decision['conflict']?->id,
            'message' => $decision['message'] ?? 'Customer updated.',
        ]);
    }
}
