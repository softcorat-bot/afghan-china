<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\PosDevice;
use App\Models\SyncBatch;
use App\Models\SyncConflict;
use App\Services\Sync\IdempotencyLedger;
use App\Services\Sync\SyncSequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Synchronization: the shop-floor view of the fleet.
 *
 * It answers the two questions an administrator actually has — "is anything
 * stuck?" and "what did each till send me?" — from the server's own records, and
 * pairs them with the same figures the till shows in its Sync Status panel.
 */
class SyncLogController extends Controller
{
    public function __construct(
        private readonly SyncSequence $sequence,
        private readonly IdempotencyLedger $ledger,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $since = now()->subDays((int) $request->input('days', 7));

        $devices = PosDevice::query()
            ->select(['id', 'device_id', 'name', 'branch_id', 'status', 'last_seen_at', 'last_sync_at', 'pending_count', 'failed_count', 'app_version'])
            ->with('branch:id,name')
            ->orderBy('device_id')
            ->get()
            ->map(function (PosDevice $device) {
                $recent = SyncBatch::where('device_id', $device->device_id)->latest('id')->limit(20)->get();

                return [
                    'device_id' => $device->device_id,
                    'name' => $device->name,
                    'branch' => $device->branch?->name,
                    'status' => $device->status,
                    'last_seen_at' => optional($device->last_seen_at)->toIso8601String(),
                    'last_sync_at' => optional($device->last_sync_at)->toIso8601String(),
                    'pending_on_device' => $device->pending_count,
                    'failed_on_device' => $device->failed_count,
                    'last_pull_seq' => $device->last_pull_seq,
                    'app_version' => $device->app_version,
                    'uploads_7d' => (int) $recent->where('direction', 'push')->sum('applied'),
                    'downloads_7d' => (int) $recent->where('direction', 'pull')->sum('rows_sent'),
                    'last_batch' => $recent->first(),
                    'needs_attention' => $device->failed_count > 0
                        || $recent->contains(fn ($b) => in_array($b->status, ['failed', 'partial'], true)),
                ];
            });

        $batches = SyncBatch::with('device:id,device_id,name')
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->string('device_id')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'server_seq' => $this->sequence->current(),
            'devices' => $devices,
            'batches' => $batches,
            'totals' => [
                'devices' => $devices->count(),
                'online_recently' => $devices->filter(fn ($d) => $d['last_seen_at'] && \Illuminate\Support\Carbon::parse($d['last_seen_at'])->gt(now()->subMinutes(10)))->count(),
                'pending_conflicts' => SyncConflict::where('status', SyncConflict::STATUS_PENDING)->count(),
                'critical_conflicts' => SyncConflict::where('status', SyncConflict::STATUS_PENDING)->where('severity', 'critical')->count(),
                'failed_batches_7d' => $batches->whereIn('status', ['failed', 'partial'])->count(),
                'records_uploaded_24h' => (int) SyncBatch::where('direction', 'push')->where('created_at', '>=', now()->subDay())->sum('applied'),
                'records_downloaded_24h' => (int) SyncBatch::where('direction', 'pull')->where('created_at', '>=', now()->subDay())->sum('rows_sent'),
            ],
            'recent_errors' => SyncBatch::whereNotNull('error_message')->latest('id')->limit(20)->get(['id', 'device_id', 'direction', 'error_message', 'created_at']),
        ]);
    }

    public function batches(Request $request): JsonResponse
    {
        return response()->json(SyncBatch::with('device:id,device_id,name')
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->string('device_id')))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 50)));
    }

    /** Housekeeping: drop ledger rows older than the retention window. */
    public function prune(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', config('sync.idempotency_ttl_days', 120));

        return response()->json(['pruned' => $this->ledger->prune($days), 'days' => $days]);
    }
}
