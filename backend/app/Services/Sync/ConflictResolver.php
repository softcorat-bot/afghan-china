<?php

namespace App\Services\Sync;

use App\Models\PosDevice;
use App\Models\SyncConflict;
use App\Models\User;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Conflict rules, stated once and enforced everywhere.
 *
 *  Master data     — merged field by field where a merge is safe; otherwise the
 *                    server wins and the device is told to refresh.
 *  Financial rows  — append-only. Two devices can never "both be right" about a
 *                    sale, a payment, a refund or a cash movement, so a second
 *                    version of the same uuid is *never* written over the first:
 *                    it is raised as a critical conflict for an authorized human.
 *
 * Resolving a conflict is always a human decision. `accepted_server` re-sends the
 * server row so the till visibly corrects itself; `kept_local` writes the till's
 * version centrally (master data only, and only where the policy allows);
 * `merged` writes exactly the fields an administrator ticked.
 */
class ConflictResolver
{
    /** Entity name used by devices → the config/sync.php table key it lives in. */
    private const ENTITY_TABLE = [
        'sale' => 'sales',
        'sale_payment' => 'sale_payments',
        'refund' => 'refunds',
        'stock_movement' => 'stock_adjustments',
        'stock_adjustment' => 'stock_adjustments',
        'cash_session' => 'shifts',
        'cash_movement' => 'cash_movements',
        'counter_end_of_day' => 'counter_end_of_day',
        'expense' => 'expenses',
    ];

    public function policy(string $entityType): string
    {
        return config("sync.conflict_policies.$entityType", 'server_wins');
    }

    public function isFinancial(string $entityType): bool
    {
        return $this->policy($entityType) === 'append_only';
    }

    /**
     * Decide what to do with a device's write to an existing master-data row.
     *
     * @return array{action:string,payload:array,conflict:?SyncConflict,message:?string}
     */
    public function reconcileMaster(string $entityType, Model $existing, array $payload, PosDevice $device, string $changeUuid): array
    {
        $policy = $this->policy($entityType);
        $incoming = $this->sanitize($payload);
        $differing = $this->differingFields($existing, $incoming, $entityType);

        if (empty($differing)) {
            // Nothing actually changed — a replay, not an edit. Say so instead of
            // bumping revisions and inventing sync traffic.
            return ['action' => 'unchanged', 'payload' => [], 'conflict' => null, 'message' => 'No changes to apply.'];
        }

        switch ($policy) {
            case 'client_wins':
                return ['action' => 'apply', 'payload' => $incoming, 'conflict' => null, 'message' => null];

            case 'field_merge':
                $mergeable = config("sync.merge_fields.$entityType", []);
                $apply = [];
                $rejected = [];

                foreach ($incoming as $field => $value) {
                    if (in_array($field, $mergeable, true)) {
                        $apply[$field] = $value;
                    } elseif (array_key_exists($field, $differing)) {
                        $rejected[] = $field;
                    }
                }

                if (! empty($rejected)) {
                    $conflict = $this->raise($entityType, (string) $existing->uuid, $payload, $this->snapshot($existing), [
                        'device' => $device,
                        'policy' => 'field_merge',
                        'reason' => 'Fields the till may not change: '.implode(', ', $rejected),
                        'differing' => $differing,
                        'severity' => 'warning',
                    ]);

                    return [
                        'action' => 'conflict',
                        'payload' => $apply,
                        'conflict' => $conflict,
                        'message' => 'Some fields are controlled centrally; they were kept and the conflict is logged.',
                    ];
                }

                return ['action' => 'apply', 'payload' => $apply, 'conflict' => null, 'message' => null];

            case 'manual':
                $conflict = $this->raise($entityType, (string) $existing->uuid, $payload, $this->snapshot($existing), [
                    'device' => $device,
                    'policy' => 'manual',
                    'reason' => 'Manual resolution required.',
                    'differing' => $differing,
                    'severity' => 'warning',
                ]);

                return ['action' => 'conflict', 'payload' => [], 'conflict' => $conflict, 'message' => 'Held for a human to resolve.'];

            case 'server_wins':
            default:
                $conflict = $this->raise($entityType, (string) $existing->uuid, $payload, $this->snapshot($existing), [
                    'device' => $device,
                    'policy' => 'server_wins',
                    'reason' => 'The server copy is authoritative for this record.',
                    'differing' => $differing,
                    'severity' => 'info',
                ]);

                return [
                    'action' => 'server_wins',
                    'payload' => [],
                    'conflict' => $conflict,
                    'message' => 'The server version wins; the device has been told to refresh.',
                ];
        }
    }

    /**
     * A financial row with the same uuid but different content. Never merged,
     * never overwritten — recorded and surfaced.
     */
    public function raiseFinancial(string $entityType, string $uuid, array $local, ?array $server, PosDevice $device, string $reason): SyncConflict
    {
        return $this->raise($entityType, $uuid, $local, $server, [
            'device' => $device,
            'policy' => 'append_only',
            'reason' => $reason,
            'differing' => $this->differingFields((object) ($server ?? []), $local, $entityType),
            'severity' => 'critical',
        ]);
    }

    public function raise(string $entityType, string $uuid, ?array $local, ?array $server, array $options = []): SyncConflict
    {
        /** @var PosDevice|null $device */
        $device = $options['device'] ?? null;

        return SyncConflict::create([
            'company_id' => Tenant::id() ?? ($device->company_id ?? null),
            'branch_id' => Branch::id() ?? ($device->branch_id ?? null),
            'device_id' => $device?->device_id ?? ($options['device_id'] ?? null),
            'entity_type' => $entityType,
            'entity_uuid' => $uuid,
            'severity' => $options['severity'] ?? 'warning',
            'policy' => $options['policy'] ?? $this->policy($entityType),
            'reason' => $options['reason'] ?? null,
            'local_payload' => $local,
            'server_payload' => $server,
            'differing_fields' => $options['differing'] ?? null,
            'status' => SyncConflict::STATUS_PENDING,
            'detected_at' => now(),
        ]);
    }

    /**
     * Apply an administrator's decision.
     *
     * `kept_local` is refused for critical (financial) conflicts held under the
     * append-only policy: the historical record is not rewritten. A correction
     * must be made the way the business already corrects sales — a refund or a
     * stock adjustment — so the audit trail stays intact.
     */
    public function resolve(SyncConflict $conflict, string $resolution, User $user, ?string $note = null, ?array $merged = null): SyncConflict
    {
        if ($conflict->status !== SyncConflict::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'resolution' => 'This conflict has already been resolved.',
            ]);
        }

        $financial = $conflict->isFinancial();

        if ($financial && in_array($resolution, ['kept_local', 'merged'], true) && ! $this->canOverrideFinancial($user)) {
            throw ValidationException::withMessages([
                'resolution' => 'Financial records cannot be overwritten or merged. Record a refund or an adjustment instead, or ask the owner to override.',
            ]);
        }

        if ($financial && $resolution === 'dismissed' && ! $this->canOverrideFinancial($user)) {
            throw ValidationException::withMessages([
                'resolution' => 'A financial conflict has to be decided, not dismissed: accept the recorded server figures, or ask the owner to override.',
            ]);
        }

        DB::transaction(function () use ($conflict, $resolution, $user, $note, $merged, $financial) {
            $conflict->status = match ($resolution) {
                SyncConflict::STATUS_ACCEPTED_SERVER => SyncConflict::STATUS_ACCEPTED_SERVER,
                SyncConflict::STATUS_KEPT_LOCAL => SyncConflict::STATUS_KEPT_LOCAL,
                SyncConflict::STATUS_MERGED => SyncConflict::STATUS_MERGED,
                default => SyncConflict::STATUS_DISMISSED,
            };
            $conflict->resolved_at = now();
            $conflict->resolved_by = $user->id;
            $conflict->resolution_note = $note ?? ($financial ? 'Financial conflict — resolved without rewriting history.' : null);
            $conflict->save();

            $this->applyDecision($conflict, $resolution, $merged);
        });

        return $conflict->fresh();
    }

    /**
     * What an administrator may do with this conflict, and which fields they may
     * merge — the UI in Settings → Synchronization → Conflicts is driven by this.
     */
    public function optionsFor(SyncConflict $conflict): array
    {
        $financial = $conflict->isFinancial();
        $mergeable = config("sync.merge_fields.{$conflict->entity_type}", []);

        return [
            'policy' => $conflict->policy,
            'financial' => $financial,
            'resolved' => $conflict->status !== SyncConflict::STATUS_PENDING,
            'differing_fields' => array_keys($conflict->differing_fields ?? []),
            'actions' => array_values(array_filter([
                'accepted_server',
                $financial ? null : 'kept_local',
                $financial ? null : ($mergeable ? 'merged' : null),
                $financial ? null : 'dismissed',
            ])),
            'mergeable_fields' => $mergeable,
            'fields_needing_override' => $financial ? ['kept_local', 'merged', 'dismissed'] : [],
            'explanation' => $financial
                ? 'This is a financial record. The history on the server is never overwritten; a correction is made with a refund or an adjustment.'
                : 'Master data: the server copy can be accepted, the device copy kept, or the safe fields merged.',
        ];
    }

    private function applyDecision(SyncConflict $conflict, string $resolution, ?array $merged): void
    {
        $class = config('sync.tables')[$this->tableFor($conflict->entity_type)] ?? null;

        if (! $class || ! is_subclass_of($class, Model::class)) {
            return;
        }

        $row = $class::where('uuid', $conflict->entity_uuid)->first();

        if (! $row) {
            return;
        }

        // Keep the till's version: write the safe fields onto the server row so
        // the decision becomes the new truth, then let it flow back out.
        if (in_array($resolution, ['kept_local', 'merged'], true) && ! $conflict->isFinancial()) {
            $payload = $resolution === 'kept_local'
                ? $this->sanitize($conflict->local_payload ?? [])
                : $this->mergeableSubset($conflict->entity_type, $merged ?? []);

            if ($payload) {
                $row->fill($payload)->save();
            }

            return;
        }

        // Accept the server copy: re-send it so the device corrects itself
        // instead of living with a different value silently.
        if ($resolution === 'accepted_server') {
            $row->save();
        }
    }

    /** Only the fields the config declares as safely mergeable are ever applied. */
    private function mergeableSubset(string $entityType, array $merged): array
    {
        $allowed = config("sync.merge_fields.$entityType", []);

        return array_filter(
            $this->sanitize($merged),
            fn ($field) => in_array($field, $allowed, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Who may set a financial record aside: a super admin, the platform owner,
     * the owner's VIP seat, or anyone explicitly trusted with the permission.
     */
    private function canOverrideFinancial(User $user): bool
    {
        return (bool) $user->is_super_admin
            || $user->isPlatformOwner()
            || $user->roles->contains('name', 'VIP')
            || $user->can('override-financial-sync-conflicts');
    }

    /** Strip anything the client has no business setting. */
    public function sanitize(array $payload): array
    {
        $owned = config('sync.server_owned_fields', []);

        return array_filter(
            $payload,
            fn ($key) => ! in_array($key, $owned, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** Which fields differ between a stored row and an incoming payload. */
    public function differingFields(object $existing, array $incoming, string $entityType): array
    {
        $skip = array_merge(config('sync.server_owned_fields', []), ['captured_at']);
        $diff = [];

        foreach ($incoming as $field => $value) {
            if (in_array($field, $skip, true)) {
                continue;
            }
            $current = $existing->{$field} ?? null;
            if ($this->normalize($current) !== $this->normalize($value)) {
                $diff[$field] = ['server' => $current, 'device' => $value];
            }
        }

        return $diff;
    }

    private function normalize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
    }

    private function snapshot(Model $model): array
    {
        return $model->attributesToArray();
    }

    /** The handler's entity name → the configured table key. */
    public function tableFor(string $entityType): string
    {
        return self::ENTITY_TABLE[$entityType] ?? Str::plural($entityType);
    }
}
