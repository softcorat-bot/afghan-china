<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PosDevice;
use App\Models\SyncBatch;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Sync\ConflictResolver;
use App\Services\Sync\SyncPuller;
use App\Services\Sync\SyncPusher;
use App\Services\Sync\SyncSequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The sync endpoints an offline installation talks to.
 *
 * They are device-authenticated (see App\Http\Middleware\DeviceAuth), scoped to
 * the device's own company and branch, and safe to retry: a push that is
 * repeated returns the same results instead of applying anything twice.
 *
 *   GET  /api/v1/sync/status   — is this device welcome, and what is waiting?
 *   POST /api/v1/sync/heartbeat
 *   POST /api/v1/sync/push     — offline work → central database
 *   GET  /api/v1/sync/pull     — central changes → offline database
 *   POST /api/v1/sync/ack      — "I stored the rows you sent me"
 *
 * The legacy `api/sync/pull` and `api/sync/push` paths are registered as aliases
 * of the same endpoints, so a device built against the earlier names keeps
 * working.
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly SyncPusher $pusher,
        private readonly SyncPuller $puller,
        private readonly SyncSequence $sequence,
    ) {
    }

    /** What the device needs to know before it decides what to do next. */
    public function status(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'device' => [
                'device_id' => $device->device_id,
                'name' => $device->name,
                'status' => $device->status,
                'branch_id' => $device->branch_id,
                'app_version' => $device->app_version,
                'last_sync_at' => optional($device->last_sync_at)->toIso8601String(),
            ],
            'server_time' => now()->toIso8601String(),
            'server_seq' => $this->sequence->current(),
            'cursor' => (int) $device->last_pull_seq,
            'conflicts_pending' => SyncConflict::where('status', SyncConflict::STATUS_PENDING)->count(),
            'limits' => [
                'pull' => (int) config('sync.pull_limit', 500),
                'pull_max' => (int) config('sync.max_pull_limit', 2000),
                'push' => (int) config('sync.max_push_batch', 500),
            ],
            'features' => [
                'pull_tables' => config('sync.pull_tables'),
                'push_entities' => config('sync.push_entities'),
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'app_version' => ['nullable', 'string', 'max:32'],
            'pending' => ['nullable', 'integer', 'min:0'],
            'failed' => ['nullable', 'integer', 'min:0'],
            'last_error' => ['nullable', 'array'],
            'os_version' => ['nullable', 'string', 'max:64'],
        ]);

        $device->forceFill([
            'app_version' => $data['app_version'] ?? $device->app_version,
            'os_version' => $data['os_version'] ?? $device->os_version,
            'pending_count' => $data['pending'] ?? 0,
            'failed_count' => $data['failed'] ?? 0,
            'last_error' => $data['last_error'] ?? null,
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'status' => $device->status,
        ]);
    }

    /** Offline work arrives here. */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_uuid' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.change_uuid' => ['nullable', 'string', 'max:64'],
            'changes.*.entity_type' => ['required', 'string', 'max:40'],
            'changes.*.operation' => ['nullable', 'in:create,update,delete'],
            'changes.*.uuid' => ['required', 'string', 'max:64'],
            'changes.*.base_revision' => ['nullable', 'integer'],
            'changes.*.captured_at' => ['nullable', 'string'],
            'changes.*.payload' => ['nullable', 'array'],
        ]);

        $device = $this->device($request);

        $result = $this->pusher->push(
            $device,
            $data['changes'],
            $data['batch_uuid'] ?? null,
            $data['app_version'] ?? null,
            $request->ip(),
        );

        $result['server_time'] = now()->toIso8601String();

        return response()->json($result);
    }

    /** Central changes leave from here. */
    public function pull(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', config('sync.pull_limit', 500));
        $tables = $request->input('tables');

        if (is_string($tables)) {
            $tables = array_filter(array_map('trim', explode(',', $tables)));
        }

        $device = $this->device($request);
        $since = (int) $request->input('since_seq', $request->input('cursor', $device->last_pull_seq));

        return response()->json($this->puller->pull(
            $device,
            max(0, $since),
            is_array($tables) ? $tables : [],
            $limit,
            $request->input('app_version'),
            $request->ip(),
        ));
    }

    /**
     * The device confirms it stored what it was sent. The server records the
     * cursor and any rows the device could not apply, so a failure is visible
     * centrally instead of only on the till.
     */
    public function ack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['required', 'integer', 'min:0'],
            'applied' => ['nullable', 'array'],
            'failed' => ['nullable', 'array'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $device = $this->device($request);

        SyncBatch::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->device_id,
            'direction' => 'ack',
            'status' => empty($data['failed']) ? 'completed' : 'partial',
            'cursor_after' => $data['cursor'],
            'rows_sent' => count($data['applied'] ?? []),
            'error_message' => empty($data['failed']) ? null : json_encode($data['failed']),
            'app_version' => $request->input('app_version'),
            'ip' => $request->ip(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $device->forceFill([
            'last_pull_seq' => max((int) $device->last_pull_seq, (int) $data['cursor']),
            'last_sync_at' => now(),
        ])->save();

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }

    /**
     * The device's own conflicts: what it is waiting on, and what an
     * administrator has already decided (so the till can release the record and
     * show the outcome instead of an eternal "Conflict" badge).
     */
    public function conflicts(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $rows = SyncConflict::where('device_id', $device->device_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('pending_only'), fn ($q) => $q->where('status', SyncConflict::STATUS_PENDING))
            ->orderByDesc('id')
            ->limit((int) $request->input('limit', 100))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SyncConflict $c) => [
                'id' => $c->id,
                'entity_type' => $c->entity_type,
                'entity_uuid' => $c->entity_uuid,
                'severity' => $c->severity,
                'policy' => $c->policy,
                'reason' => $c->reason,
                'status' => $c->status,
                'differing_fields' => array_keys($c->differing_fields ?? []),
                'local_payload' => $c->local_payload,
                'server_payload' => $c->server_payload,
                'detected_at' => optional($c->detected_at)->toIso8601String(),
                'resolved_at' => optional($c->resolved_at)->toIso8601String(),
                'resolution_note' => $c->resolution_note,
                'actions' => app(ConflictResolver::class)->optionsFor($c)['actions'],
            ]),
            'pending' => $rows->where('status', SyncConflict::STATUS_PENDING)->count(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Seed context for an offline installation: its company row, who belongs
     * to that company, and the RBAC snapshot (roles, permissions and who holds
     * them). The till applies it with PullApplier::applyContext() on its first
     * `offline:seed` and on every Sync Now, before the sequenced pull — so the
     * shape here must match what that method reads, table for table.
     *
     * Everything is scoped to the device's own company; roles/permissions are
     * global because spatie/laravel-permission runs without teams here.
     */
    public function context(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $companyId = (int) $device->company_id;

        $rows = fn ($query) => $query->get()->map(fn ($row) => (array) $row)->values()->all();

        $company = DB::table('companies')->where('id', $companyId)->first();
        $userIds = DB::table('company_user')->where('company_id', $companyId)->pluck('user_id')
            ->merge(User::withoutGlobalScopes()->withTrashed()->where('company_id', $companyId)->pluck('id'))
            ->unique()->values()->all();

        return response()->json([
            'company' => $company ? (array) $company : null,
            'company_user' => $rows(DB::table('company_user')->where('company_id', $companyId)),
            'roles' => $rows(DB::table('roles')),
            'permissions' => $rows(DB::table('permissions')),
            'role_has_permissions' => $rows(DB::table('role_has_permissions')),
            'model_has_roles' => $rows(DB::table('model_has_roles')
                ->where('model_type', User::class)->whereIn('model_id', $userIds)),
            'model_has_permissions' => $rows(DB::table('model_has_permissions')
                ->where('model_type', User::class)->whereIn('model_id', $userIds)),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function device(Request $request): PosDevice
    {
        /** @var PosDevice $device */
        $device = $request->attributes->get('pos_device');

        return $device;
    }
}
