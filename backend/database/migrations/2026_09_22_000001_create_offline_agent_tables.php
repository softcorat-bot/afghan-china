<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Offline agent's own tables.
 *
 *  offline_outbox — every local write that Central has not acknowledged yet.
 *                   change_uuid is the idempotency key: the server's sync_inbox
 *                   ledger replays a retried change instead of applying it again.
 *  offline_meta   — the agent's key/value store: sync cursor, last sync times,
 *                   last result summary, seed state.
 *
 * Migrated everywhere (Online and Offline run the same migrations), but only
 * written when OFFLINE_MODE=true.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offline_outbox')) {
            Schema::create('offline_outbox', function (Blueprint $table) {
                $table->id();
                $table->string('change_uuid', 64)->unique();
                $table->string('entity_type', 40)->index();
                $table->string('entity_uuid', 64)->index();
                $table->string('operation', 16)->default('create'); // create|update|delete
                $table->json('payload')->nullable();
                $table->integer('base_revision')->nullable();
                // pending|processing|synced|failed|conflict
                $table->string('status', 16)->default('pending')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->string('server_id')->nullable();
                $table->string('server_uuid', 64)->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'entity_type', 'id']);
            });
        }

        if (! Schema::hasTable('offline_meta')) {
            Schema::create('offline_meta', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_outbox');
        Schema::dropIfExists('offline_meta');
    }
};
