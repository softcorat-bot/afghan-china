<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated daily backup tracking. Backups are stored to cloud or local storage
 * and logged for recovery and compliance purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->dateTime('backup_time');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'failed'])->default('in_progress');
            $table->enum('destination', ['aws_s3', 'google_cloud', 'digital_ocean', 'ftp', 'local'])->default('local');

            // Backup details
            $table->string('backup_file')->nullable(); // File path or URL
            $table->unsignedBigInteger('backup_size')->nullable(); // Size in bytes
            $table->integer('duration_seconds')->nullable(); // How long the backup took
            $table->integer('records_backed_up')->nullable(); // Number of records

            // Error tracking
            $table->text('error_message')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'backup_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
