<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sync engine between an offline device (IndexedDB) and PostgreSQL, the single
 * source of truth. Records are matched by their client-generated `uuid`, so
 * rows created offline on different devices never collide. Every write bumps a
 * `revision`; a push carrying a stale base revision is flagged as a conflict
 * rather than silently overwriting. Deletes are propagated as soft-deletes.
 */
class SyncController extends Controller
{
    /**
     * Pull everything that changed on the server since the device last synced,
     * scoped to the tables the device asks for. Includes soft-deleted rows so
     * the device can tombstone them locally.
     */
    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tables' => ['nullable', 'array'],
            'tables.*' => ['string'],
            'since' => ['nullable', 'date'],
        ]);
        $data['tables'] = $data['tables'] ?? [];

        $since = $data['since'] ?? null;
        $limit = (int) config('sync.pull_limit', 500);
        $out = [];

        foreach ($data['tables'] as $table) {
            $class = $this->modelFor($table);
            if (! $class) {
                continue;
            }

            $query = $this->withTrashed($class::query(), $class)
                ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
                ->orderBy('updated_at')->orderBy('id')
                ->limit($limit);

            $out[$table] = $query->get()->map(fn (Model $m) => $m->attributesToArray())->all();
        }

        return response()->json([
            'server_time' => now()->toISOString(),
            'data' => $out,
        ]);
    }

    /**
     * Push a batch of offline mutations. Each change is applied by uuid and
     * returns one of: applied | conflict | error, so the device can clear its
     * outbox for the applied ones and surface conflicts for review.
     */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.table' => ['required', 'string'],
            'changes.*.op' => ['required', 'in:create,update,delete'],
            'changes.*.uuid' => ['required', 'uuid'],
            'changes.*.base_revision' => ['nullable', 'integer'],
            'changes.*.payload' => ['nullable', 'array'],
        ]);

        $key = config('sync.key_column', 'uuid');
        $rev = config('sync.revision_column', 'revision');
        $results = [];

        foreach ($data['changes'] as $change) {
            $class = $this->modelFor($change['table']);
            if (! $class) {
                $results[] = $this->result($change, 'error', null, 'Unknown or unsynced table.');

                continue;
            }

            try {
                $results[] = DB::transaction(fn () => $this->applyChange($class, $change, $key, $rev));
            } catch (\Throwable $e) {
                $results[] = $this->result($change, 'error', null, $e->getMessage());
            }
        }

        return response()->json([
            'server_time' => now()->toISOString(),
            'results' => $results,
        ]);
    }

    private function applyChange(string $class, array $change, string $key, string $rev): array
    {
        $existing = $this->withTrashed($class::query(), $class)->where($key, $change['uuid'])->first();
        $payload = $this->sanitize($change['payload'] ?? [], $key, $rev);

        if ($change['op'] === 'delete') {
            if (! $existing) {
                return $this->result($change, 'applied', null);
            }
            $this->usesSoftDeletes($class) ? $existing->delete() : $existing->forceDelete();
            ActivityLog::log('deleted', 'Sync', "Synced delete of {$change['table']} {$change['uuid']}");

            return $this->result($change, 'applied', $existing->fresh() ?? null);
        }

        if (! $existing) {
            $payload[$key] = $change['uuid'];
            $model = $class::create($payload);
            ActivityLog::log('created', 'Sync', "Synced create of {$change['table']} {$change['uuid']}");

            return $this->result($change, 'applied', $model->fresh());
        }

        $base = $change['base_revision'] ?? null;
        if ($base !== null && (int) $existing->{$rev} !== (int) $base) {
            return $this->result($change, 'conflict', $existing->fresh());
        }

        $existing->fill($payload)->save();
        ActivityLog::log('updated', 'Sync', "Synced update of {$change['table']} {$change['uuid']}");

        return $this->result($change, 'applied', $existing->fresh());
    }

    private function result(array $change, string $status, ?Model $row, ?string $message = null): array
    {
        return array_filter([
            'uuid' => $change['uuid'],
            'table' => $change['table'],
            'op' => $change['op'],
            'status' => $status,
            'server_row' => $row?->attributesToArray(),
            'message' => $message,
        ], fn ($v) => $v !== null);
    }

    /** Never let a device overwrite the server-managed key/revision or the numeric id. */
    private function sanitize(array $payload, string $key, string $rev): array
    {
        unset($payload['id'], $payload[$key], $payload[$rev]);

        return $payload;
    }

    private function modelFor(string $table): ?string
    {
        $class = config('sync.tables')[$table] ?? null;

        return ($class && class_exists($class)) ? $class : null;
    }

    private function usesSoftDeletes(string $class): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }

    private function withTrashed($query, string $class)
    {
        return $this->usesSoftDeletes($class) ? $query->withTrashed() : $query;
    }
}
