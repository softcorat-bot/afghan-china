<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PosDevice;
use App\Models\SyncConflict;
use App\Services\Sync\ConflictResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Synchronization → Conflicts: the place where a disagreement between
 * a till and the central database is shown, explained and settled.
 *
 * Nothing here happens by itself. A conflict is a question, not a merge: the
 * administrator sees both values, the device and the time, and chooses. Money is
 * special — accepting a device's version over central figures needs the
 * override permission, and the decision is written to the audit log either way.
 */
class ConflictController extends Controller
{
    public function __construct(private readonly ConflictResolver $resolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = SyncConflict::with(['device:id,device_id,name', 'resolver:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->string('device_id')))
            ->when($request->boolean('pending_only', $request->input('status') === null), fn ($q) => $q->where('status', SyncConflict::STATUS_PENDING))
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->latest('id');

        return response()->json($query->paginate((int) $request->input('per_page', 25)));
    }

    public function show(SyncConflict $conflict): JsonResponse
    {
        return response()->json([
            'conflict' => $conflict->load(['device:id,device_id,name', 'resolver:id,name']),
            'options' => $this->resolver->optionsFor($conflict),
        ]);
    }

    /**
     * Accept the server's version, keep the device's version, or merge the
     * fields that may safely be merged. `POST /api/v1/sync/conflicts/{id}/resolve`.
     */
    public function resolve(Request $request, SyncConflict $conflict): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:accepted_server,kept_local,merged,dismissed'],
            'note' => ['nullable', 'string', 'max:500'],
            'merged' => ['nullable', 'array'],
        ]);

        try {
            $conflict = $this->resolver->resolve(
                $conflict,
                $data['resolution'],
                $request->user(),
                $data['note'] ?? null,
                $data['merged'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        ActivityLog::log('updated', 'SyncConflict', "Conflict #{$conflict->id} ({$conflict->entity_type}) resolved as {$conflict->status}");

        return response()->json([
            'conflict' => $conflict->fresh(['resolver:id,name']),
            'message' => match ($conflict->status) {
                SyncConflict::STATUS_ACCEPTED_SERVER => 'The server version was accepted; the device will be told on its next sync.',
                SyncConflict::STATUS_KEPT_LOCAL => 'The device version was accepted and applied centrally.',
                SyncConflict::STATUS_MERGED => 'The chosen fields were merged.',
                default => 'The conflict was dismissed.',
            },
        ]);
    }

    /** Devices waiting on a decision, so the web UI can show a badge. */
    public function pending(Request $request): JsonResponse
    {
        $byDevice = SyncConflict::where('status', SyncConflict::STATUS_PENDING)
            ->selectRaw('device_id, count(*) as total, sum(case when severity = ? then 1 else 0 end) as critical', ['critical'])
            ->groupBy('device_id')
            ->get()
            ->map(fn ($row) => [
                'device_id' => $row->device_id,
                'device_name' => PosDevice::where('device_id', $row->device_id)->value('name'),
                'total' => (int) $row->total,
                'critical' => (int) $row->critical,
            ]);

        return response()->json([
            'total' => (int) $byDevice->sum('total'),
            'critical' => (int) $byDevice->sum('critical'),
            'devices' => $byDevice,
        ]);
    }
}
