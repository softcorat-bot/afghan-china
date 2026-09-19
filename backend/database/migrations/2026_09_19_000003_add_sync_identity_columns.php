<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Global identity + change-tracking columns for every synchronizable table.
 *
 *  uuid        globally unique id — synchronization never relies on the local
 *              auto-increment id, because two offline devices will both have a
 *              sale with id 17.
 *  revision    bumped by the server on every write; a device push carries the
 *              revision it based its edit on, so a stale write is detected as a
 *              conflict instead of silently overwriting someone else's change.
 *  sync_seq    stamp from the global sequence (config('sync.seq_column')) — what
 *              the device pulls "everything after" for incremental sync.
 *  origin      web | offline — how the row entered the system.
 *  device_id   which offline installation created/last touched it (null = web).
 *  synced_at   when a device-originated row landed centrally.
 *
 * Existing rows are back-filled so a live database can be upgraded in place.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        // Master data (server → POS)
        'products', 'product_categories', 'customers', 'suppliers', 'users',
        'counters', 'branches', 'currencies', 'exchange_rates',
        // Transactions (POS → server)
        'sales', 'sale_items', 'sale_payments', 'refunds', 'refund_items',
        'shifts', 'cash_movements', 'stock_adjustments',
        'stock_transfers', 'stock_transfer_items',
        'purchases', 'purchase_items', 'purchase_returns', 'purchase_return_items',
        'expenses', 'inventory_expiry_batches', 'counter_end_of_days',
        'wholesale_payments',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'uuid')) {
                    $blueprint->string('uuid', 36)->nullable();
                }
                if (! Schema::hasColumn($table, 'revision')) {
                    $blueprint->integer('revision')->default(1);
                }
                if (! Schema::hasColumn($table, 'sync_seq')) {
                    $blueprint->unsignedBigInteger('sync_seq')->nullable();
                }
                if (! Schema::hasColumn($table, 'origin')) {
                    $blueprint->string('origin', 16)->nullable();
                }
                if (! Schema::hasColumn($table, 'device_id')) {
                    $blueprint->string('device_id', 64)->nullable();
                }
                if (! Schema::hasColumn($table, 'synced_at')) {
                    $blueprint->timestamp('synced_at')->nullable();
                }
            });

            // A device's own clock, kept separate from `sold_at`: reports and
            // audits must be able to see that the sale happened at 18:40 in
            // Kabul while the server only heard about it the next morning. The
            // device's own invoice number is kept beside the central INV- number
            // as issued by the server, so a paper bill can always be matched to
            // the row it produced even when two tills numbered the same day
            // independently.
            if ($table === 'sales') {
                Schema::table('sales', function (Blueprint $blueprint) {
                    if (! Schema::hasColumn('sales', 'captured_at')) {
                        $blueprint->timestamp('captured_at')->nullable();
                    }
                    if (! Schema::hasColumn('sales', 'device_invoice_no')) {
                        $blueprint->string('device_invoice_no')->nullable();
                    }
                });
            }

            // Which catalogue row an offline line was rung against, even when
            // that product could not be resolved centrally at sync time.
            if ($table === 'sale_items' && ! Schema::hasColumn('sale_items', 'product_uuid')) {
                Schema::table('sale_items', function (Blueprint $blueprint) {
                    $blueprint->string('product_uuid', 36)->nullable()->index();
                });
            }

            $this->index($table, ['uuid'], true);
            $this->index($table, ['sync_seq']);
            $this->index($table, ['device_id']);
        }

        $this->backfill();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->installPostgresTriggers();
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['uuid', 'revision', 'sync_seq', 'origin', 'device_id', 'synced_at'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
                if ($table === 'sales' && Schema::hasColumn('sales', 'captured_at')) {
                    $blueprint->dropColumn('captured_at');
                }
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::statement("DROP TRIGGER IF EXISTS {$table}_sync_touch ON {$table}");
                }
            }
            DB::statement('DROP FUNCTION IF EXISTS sync_touch_row()');
            DB::statement('DROP SEQUENCE IF EXISTS sync_seq_global');
        }
    }

    /** Idempotent index creation (Laravel has no "add index if missing"). */
    private function index(string $table, array $columns, bool $unique = false): void
    {
        $name = $table.'_'.implode('_', $columns).'_'.($unique ? 'unique' : 'index');

        if (DB::connection()->getDriverName() === 'pgsql') {
            $exists = DB::selectOne('select 1 from pg_indexes where indexname = ?', [$name]);
            if ($exists) {
                return;
            }
            $unique ? DB::statement("CREATE UNIQUE INDEX $name ON $table (".implode(',', $columns).')')
                : DB::statement("CREATE INDEX $name ON $table (".implode(',', $columns).')');

            return;
        }

        $exists = collect(DB::select("PRAGMA index_list($table)"))->contains(fn ($row) => ($row->name ?? null) === $name);
        if ($exists) {
            return;
        }
        DB::statement('CREATE '.($unique ? 'UNIQUE ' : '')."INDEX $name ON $table (".implode(',', $columns).')');
    }

    /** Give every existing row an identity and a place in the sync stream. */
    private function backfill(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            $hasSeq = Schema::hasColumn($table, 'sync_seq');

            DB::table($table)->whereNull('uuid')->orderBy('id')->chunkById(200, function ($rows) use ($table, $hasSeq) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(array_filter([
                        'uuid' => Str::uuid()->toString(),
                        'sync_seq' => $hasSeq ? app(\App\Services\Sync\SyncSequence::class)->next() : null,
                    ], fn ($v) => $v !== null));
                }
            });

            if ($hasSeq) {
                DB::table($table)->whereNull('sync_seq')->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)
                            ->update(['sync_seq' => app(\App\Services\Sync\SyncSequence::class)->next()]);
                    }
                });
            }
        }
    }

    /**
     * On PostgreSQL the stamp is applied by the database itself, so a change made
     * by any writer — the web POS, a migration, an admin with psql — enters the
     * sync stream. Other drivers rely on the Eloquent hooks in
     * App\Providers\SyncServiceProvider (same values, application side).
     */
    private function installPostgresTriggers(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sync_seq_global');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_touch_row() RETURNS trigger AS $$
            BEGIN
                NEW.sync_seq := nextval('sync_seq_global');
                IF TG_OP = 'UPDATE' THEN
                    NEW.revision := GREATEST(COALESCE(OLD.revision, 0) + 1, 1);
                ELSIF NEW.revision IS NULL THEN
                    NEW.revision := 1;
                END IF;
                IF NEW.uuid IS NULL THEN
                    NEW.uuid := gen_random_uuid()::text;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement("DROP TRIGGER IF EXISTS {$table}_sync_touch ON {$table}");
            DB::statement("CREATE TRIGGER {$table}_sync_touch BEFORE INSERT OR UPDATE ON {$table}
                           FOR EACH ROW EXECUTE FUNCTION sync_touch_row()");
        }
    }
};
