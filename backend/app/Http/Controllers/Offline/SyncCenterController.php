<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Models\OfflineMeta;
use App\Models\OfflineOutbox;
use App\Models\SyncConflict;
use App\Services\Offline\CentralClient;
use App\Services\Offline\DeviceIdentity;
use App\Services\Offline\OfflineBackup;
use App\Services\Offline\OfflineSyncException;
use App\Services\Offline\SyncRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Sync Center: what the local dashboard calls to show sync state and to
 * run "Sync Now". Offline Mode only (see `offline.mode` middleware), Sanctum
 * authenticated, `sync-now` permission — the same guards as the server-side
 * sync admin surface.
 */
class SyncCenterController extends Controller
{
    /** Connection, cursor, counts, last run — everything the header cards need. */
    public function status(Request $request): JsonResponse
    {
        $identity = DeviceIdentity::load();

        $counts = OfflineOutbox::query()
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')->pluck('n', 'status');

        $reachable = null;
        $serverSeq = null;

        if ($request->boolean('probe') && $identity['device_id'] && $identity['device_token']) {
            try {
                $probe = CentralClient::fromIdentity()->status(5);
                $reachable = true;
                $serverSeq = $probe['server_seq'] ?? null;
            } catch (OfflineSyncException $e) {
                $reachable = false;
            }
        }

        $lastSummary = OfflineMeta::get('sync.last_summary');
        if (is_string($lastSummary)) {
            $lastSummary = json_decode($lastSummary, true);
        }

        return response()->json([
            'mode' => 'offline',
            'app_version' => config('offline.app_version'),
            'device' => [
                'device_id' => $identity['device_id'],
                'name' => $identity['name'],
                'company_id' => $identity['company_id'],
                'branch_id' => $identity['branch_id'],
                'registered' => DeviceIdentity::isRegistered(),
            ],
            'central_url' => config('offline.central_url'),
            'reachable' => $reachable,
            'cursor' => (int) OfflineMeta::get('sync.cursor', 0),
            'server_seq' => $serverSeq,
            'seeded_at' => OfflineMeta::get('sync.seeded_at'),
            'last_run_at' => OfflineMeta::get('sync.last_run_at'),
            'last_ok_at' => OfflineMeta::get('sync.last_ok_at'),
            'last_error' => OfflineMeta::get('sync.last_error'),
            'last_summary' => $lastSummary,
            'outbox' => [
                'pending' => (int) ($counts[OfflineOutbox::STATUS_PENDING] ?? 0),
                'processing' => (int) ($counts[OfflineOutbox::STATUS_PROCESSING] ?? 0),
                'synced' => (int) ($counts[OfflineOutbox::STATUS_SYNCED] ?? 0),
                'failed' => (int) ($counts[OfflineOutbox::STATUS_FAILED] ?? 0),
                'conflict' => (int) ($counts[OfflineOutbox::STATUS_CONFLICT] ?? 0),
            ],
            'conflicts_pending' => SyncConflict::withoutGlobalScopes()->where('status', SyncConflict::STATUS_PENDING)->count(),
        ]);
    }

    /** The "Sync Now" button. Runs the whole cycle and returns its summary. */
    public function sync(Request $request): JsonResponse
    {
        set_time_limit(0);

        try {
            $summary = SyncRunner::make()->run([
                'reason' => 'sync-center:'.($request->user()?->email ?? 'cli'),
            ]);
        } catch (OfflineSyncException $e) {
            return response()->json([
                'ok' => false,
                'code' => $e->code,
                'message' => $e->getMessage(),
                'device_rejected' => $e->deviceRejected,
            ], $e->deviceRejected ? 403 : 502);
        }

        return response()->json(['ok' => true, 'summary' => $summary]);
    }

    /** Register this installation with a one-time activation code. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:64'],
            'activation_code' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $identity = DeviceIdentity::load();
        $deviceId = $data['device_id'] ?: $identity['device_id'] ?: DeviceIdentity::suggest();

        try {
            $answer = CentralClient::fromIdentity()->register($deviceId, $data['activation_code'], $data['name'] ?? null);
        } catch (OfflineSyncException $e) {
            return response()->json(['ok' => false, 'code' => $e->code, 'message' => $e->getMessage()], 422);
        }

        DeviceIdentity::save([
            'device_id' => $answer['device']['device_id'] ?? $deviceId,
            'device_token' => $answer['device_token'] ?? null,
            'company_id' => $answer['company_id'] ?? null,
            'branch_id' => $answer['branch_id'] ?? null,
            'name' => $answer['device']['name'] ?? $data['name'] ?? null,
        ]);

        OfflineMeta::set('device.company_id', $answer['company_id'] ?? null);
        OfflineMeta::set('device.branch_id', $answer['branch_id'] ?? null);

        return response()->json(['ok' => true, 'device' => $answer['device'] ?? []]);
    }

    /** Move failed/conflict rows back to pending (one, or all). */
    public function retry(Request $request): JsonResponse
    {
        $query = OfflineOutbox::query()->whereIn('status', [OfflineOutbox::STATUS_FAILED, OfflineOutbox::STATUS_CONFLICT]);

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }

        $n = (clone $query)->update(['status' => OfflineOutbox::STATUS_PENDING, 'last_error' => null]);

        return response()->json(['ok' => true, 'requeued' => $n]);
    }

    /** The outbox, newest first, for the Sync Center table. */
    public function outbox(Request $request): JsonResponse
    {
        $rows = OfflineOutbox::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->orderByDesc('id')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json($rows);
    }

    /** Local conflicts plus (best-effort) Central's view of this device's conflicts. */
    public function conflicts(Request $request): JsonResponse
    {
        $local = SyncConflict::withoutGlobalScopes()
            ->when($request->boolean('pending_only', true), fn ($q) => $q->where('status', SyncConflict::STATUS_PENDING))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $central = null;

        if (DeviceIdentity::isRegistered()) {
            try {
                $central = CentralClient::fromIdentity()->conflicts($request->boolean('pending_only', true));
            } catch (OfflineSyncException $e) {
                $central = ['unavailable' => $e->getMessage()];
            }
        }

        return response()->json(['local' => $local, 'central' => $central]);
    }

    public function backups(): JsonResponse
    {
        return response()->json(['data' => OfflineBackup::list()]);
    }

    public function backup(): JsonResponse
    {
        try {
            $result = OfflineBackup::run('manual');
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'backup' => $result]);
    }

    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:500']]);

        try {
            $result = OfflineBackup::restore($data['file']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'restored' => $result]);
    }
}
