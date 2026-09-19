<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server half of the sync engine.
 *
 *  sync_sequences — one monotonic counter; every changed row is stamped with the
 *                   next value so a device can pull "everything after N" instead
 *                   of re-downloading the database.
 *  sync_inbox     — the idempotency ledger. One row per outbox change a device
 *                   ever sent. A retried batch (lost response, app restart,
 *                   user hammering "Sync Now") matches its `change_uuid` and the
 *                   stored result is replayed instead of the change being
 *                   applied twice. This is what makes duplicate sales
 *                   impossible.
 *  sync_batches   — the audit trail of every push/pull: who, when, how many
 *                   applied/duplicate/conflict/rejected, cursor before/after.
 *  sync_conflicts — conflicts are never silently overwritten; they land here
 *                   with both versions for an authorized human to resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_sequences')) {
            Schema::create('sync_sequences', function (Blueprint $table) {
                $table->string('name')->primary();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
            });

            // Seed the global counter.
            \Illuminate\Support\Facades\DB::table('sync_sequences')->insertOrIgnore([
                'name' => 'global',
                'value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('sync_inbox')) {
            Schema::create('sync_inbox', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('device_id')->index();
                $table->string('batch_uuid')->nullable()->index();
                $table->string('change_uuid')->unique();  // the outbox row id — idempotency key
                $table->string('entity_type');
                $table->string('entity_uuid');
                $table->string('operation');
                $table->string('payload_hash', 64)->nullable();
                $table->string('status');                 // applied|duplicate|conflict|rejected|error
                $table->unsignedSmallInteger('http_status')->default(200);
                $table->string('server_id')->nullable();
                $table->json('response')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'device_id', 'processed_at']);
                $table->index(['entity_type', 'entity_uuid']);
            });
        }

        if (! Schema::hasTable('sync_batches')) {
            Schema::create('sync_batches', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('device_id')->index();
                $table->string('direction');              // push | pull
                $table->string('status');                 // completed | partial | failed
                $table->unsignedInteger('changes_received')->default(0);
                $table->unsignedInteger('applied')->default(0);
                $table->unsignedInteger('duplicates')->default(0);
                $table->unsignedInteger('conflicts')->default(0);
                $table->unsignedInteger('rejected')->default(0);
                $table->unsignedInteger('rows_sent')->default(0);
                $table->unsignedBigInteger('cursor_before')->nullable();
                $table->unsignedBigInteger('cursor_after')->nullable();
                $table->string('app_version')->nullable();
                $table->string('ip')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'device_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('sync_conflicts')) {
            Schema::create('sync_conflicts', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('device_id')->nullable()->index();
                $table->string('entity_type');
                $table->string('entity_uuid');
                $table->string('severity')->default('warning');  // info|warning|critical
                $table->string('policy');                        // server_wins|field_merge|manual|append_only
                $table->string('reason')->nullable();
                $table->json('local_payload')->nullable();
                $table->json('server_payload')->nullable();
                $table->json('differing_fields')->nullable();
                $table->string('status')->default('pending')->index(); // pending|accepted_server|kept_local|merged|dismissed
                $table->timestamp('detected_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')->nullable();
                $table->string('resolution_note')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status', 'severity']);
                $table->index(['entity_type', 'entity_uuid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('sync_inbox');
        Schema::dropIfExists('sync_sequences');
    }
};
