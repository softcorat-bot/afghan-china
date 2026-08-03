<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform (SaaS control-plane) tables. Branch provisioning is reserved to the
 * Platform Owner unless a tenant is granted branch_self_service. Every
 * platform-level action is recorded with actor, resource and before/after
 * values. Tenants raise platform_requests that the Platform Owner decides on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'branch_self_service')) {
                $table->boolean('branch_self_service')->default(false)->after('active');
            }
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('action');                 // organization.create, branch.delete, …
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['resource_type', 'resource_id']);
            $table->index('created_at');
        });

        Schema::create('platform_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->string('type');                    // branch.create, limit.increase, module.enable, …
            $table->string('title');
            $table->json('payload')->nullable();
            // pending → approved | rejected | info_requested | scheduled | assigned | escalated
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();

            $table->index(['status', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_requests');
        Schema::dropIfExists('platform_audit_logs');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('branch_self_service');
        });
    }
};
