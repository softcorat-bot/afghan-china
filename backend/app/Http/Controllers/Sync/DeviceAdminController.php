<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PosDevice;
use App\Models\SyncBatch;
use App\Models\SyncConflict;
use App\Services\Sync\DeviceRegistrar;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The server-side control room for the offline fleet: Settings → Devices and
 * Settings → Synchronization → Conflicts in the web POS.
 *
 * This is what makes an offline till safe to hand out. While it is in the field,
 * its status, its last sync, what it is still holding and any disagreement with
 * the central database are all visible from here — and a device that walks away
 * can be cut off with one click.
 */
class DeviceAdminController extends Controller
{
    public function __construct(private readonly DeviceRegistrar $devices)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PosDevice::with('branch:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('device_id', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('device_id');

        $devices = $query->get()->map(fn (PosDevice $device) => array_merge($device->toArray(), [
            'pending_conflicts' => SyncConflict::where('device_id', $device->device_id)
                ->where('status', SyncConflict::STATUS_PENDING)->count(),
            'last_batch' => SyncBatch::where('device_id', $device->device_id)
                ->latest('id')->first(['direction', 'status', 'finished_at', 'changes_received', 'rows_sent']),
            'is_stale' => $device->last_seen_at === null || $device->last_seen_at->lt(now()->subDays(3)),
        ]));

        return response()->json([
            'data' => $devices,
            'summary' => [
                'total' => $devices->count(),
                'active' => $devices->where('status', PosDevice::STATUS_ACTIVE)->count(),
                'pending' => $devices->where('status', PosDevice::STATUS_PENDING)->count(),
                'disabled' => $devices->where('status', PosDevice::STATUS_DISABLED)->count(),
                'revoked' => $devices->where('status', PosDevice::STATUS_REVOKED)->count(),
                'pending_conflicts' => SyncConflict::where('status', SyncConflict::STATUS_PENDING)->count(),
            ],
        ]);
    }

    /** Authorize a new till; the activation code is returned once. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'device_id' => ['nullable', 'string', 'max:64', 'unique:pos_devices,device_id'],
            'platform' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:500'],
            'activation_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $branchName = $data['branch_id'] ? \App\Models\Branch::find($data['branch_id'])?->name : null;

        ['device' => $device, 'activation_code' => $code] = $this->devices->create($request->user(), [
            ...$data,
            'company_id' => Tenant::id(),
            'branch_id' => $data['branch_id'] ?? Branch::id(),
            'branch_name' => $branchName,
        ]);

        return response()->json([
            'device' => $device,
            'activation_code' => $code,
            'expires_at' => $device->activation_expires_at?->toIso8601String(),
            'instructions' => 'Enter this code on the till during first-run setup.',
        ], 201);
    }

    public function show(PosDevice $device): JsonResponse
    {
        return response()->json([
            'device' => $device,
            'batches' => SyncBatch::where('device_id', $device->device_id)->latest('id')->limit(30)->get(),
            'conflicts' => SyncConflict::where('device_id', $device->device_id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, PosDevice $device): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $device->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json(['device' => $device->fresh()]);
    }

    public function disable(Request $request, PosDevice $device): JsonResponse
    {
        $this->devices->disable($device, (string) ($request->input('reason') ?: 'Disabled by an administrator.'));

        return response()->json(['device' => $device->fresh(), 'message' => 'The device can no longer sync.']);
    }

    public function enable(PosDevice $device): JsonResponse
    {
        $this->devices->enable($device);

        return response()->json(['device' => $device->fresh(), 'message' => 'The device may sync again.']);
    }

    public function revoke(Request $request, PosDevice $device): JsonResponse
    {
        $this->devices->revoke($device, (string) ($request->input('reason') ?: 'Reported lost or stolen.'));

        return response()->json(['device' => $device->fresh(), 'message' => 'Device revoked and its credentials destroyed.']);
    }

    /** Issue a replacement code for a device that has not been activated yet. */
    public function reauthorize(PosDevice $device): JsonResponse
    {
        $code = strtoupper(\Illuminate\Support\Str::random(4).'-'.\Illuminate\Support\Str::random(4));

        $device->forceFill([
            'status' => PosDevice::STATUS_PENDING,
            'activation_code_hash' => hash('sha256', $code),
            'activation_expires_at' => now()->addDays(14),
        ])->save();

        ActivityLog::log('updated', 'PosDevice', "New activation code issued for {$device->device_id}");

        return response()->json(['device' => $device->fresh(), 'activation_code' => $code]);
    }

    public function destroy(PosDevice $device): JsonResponse
    {
        $this->devices->revoke($device, 'Removed from the fleet.');
        $device->delete();

        return response()->json(['ok' => true]);
    }
}
