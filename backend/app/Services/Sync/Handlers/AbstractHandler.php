<?php

namespace App\Services\Sync\Handlers;

use App\Models\Customer;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\Sync\ConflictResolver;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a sync handler needs to turn one offline change into central rows.
 *
 * Handlers are deliberately small and explicit: what a sale means centrally is
 * a business rule, and burying it in a generic "INSERT the payload" would be
 * exactly the kind of shortcut that loses money.
 */
abstract class AbstractHandler
{
    public function __construct(protected ConflictResolver $conflicts)
    {
    }

    /** The entity_type this handler answers to. */
    abstract public function entityType(): string;

    /**
     * Apply one change from a device.
     *
     * @return array{status:string,server_id?:int|string,server_row?:array,message?:string,warnings?:array}
     */
    abstract public function apply(array $change, PosDevice $device): array;

    protected function payload(array $change): array
    {
        return $this->conflicts->sanitize($change['payload'] ?? []);
    }

    /** Identity columns every device-originated row carries. */
    protected function provenance(PosDevice $device, array $change): array
    {
        return [
            'origin' => 'offline',
            'device_id' => $device->device_id,
            'synced_at' => now(),
            'uuid' => $change['uuid'],
        ];
    }

    protected function companyId(PosDevice $device): int
    {
        return (int) (Tenant::id() ?: $device->company_id);
    }

    protected function branchId(PosDevice $device): ?int
    {
        return Branch::id() ?? $device->branch_id;
    }

    protected function product(?string $uuid): ?Product
    {
        return $uuid ? Product::where('uuid', $uuid)->first() : null;
    }

    protected function customer(?string $uuid): ?Customer
    {
        return $uuid ? Customer::where('uuid', $uuid)->first() : null;
    }

    protected function user(?string $uuid): ?User
    {
        return $uuid ? User::where('uuid', $uuid)->first() : null;
    }

    protected function shift(?string $uuid): ?Shift
    {
        return $uuid ? Shift::where('uuid', $uuid)->first() : null;
    }

    protected function existing(string $table, string $uuid): ?Model
    {
        $class = config('sync.tables')[$table] ?? null;

        return $class ? $class::withTrashed()->where('uuid', $uuid)->first() : null;
    }

    protected function row(Model $model): array
    {
        return $model->attributesToArray();
    }

    protected function result(string $status, array $extra = []): array
    {
        return array_merge(['status' => $status], $extra);
    }

    /** A duplicate is a *success*: the row already exists because it was synced before. */
    protected function duplicate(Model $model, string $message = 'Already synchronized.'): array
    {
        return $this->result('duplicate', [
            'server_id' => $model->getKey(),
            'server_uuid' => $model->uuid ?? null,
            'server_revision' => $model->revision ?? null,
            'server_row' => $this->row($model),
            'message' => $message,
        ]);
    }

    protected function reject(string $message): array
    {
        return $this->result('rejected', ['message' => $message, 'http_status' => 422]);
    }
}
