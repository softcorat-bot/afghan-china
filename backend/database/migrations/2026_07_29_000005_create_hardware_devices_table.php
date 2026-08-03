<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric/RFID hardware devices for employee attendance tracking.
 * Each device is assigned to a branch and syncs attendance records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('device_id')->unique(); // MAC address or serial number
            $table->enum('device_type', ['biometric', 'rfid', 'qr_code', 'manual'])->default('biometric');
            $table->string('device_name'); // "Main Gate Scanner", "Office Attendance"
            $table->string('location'); // Where it's placed
            $table->string('ip_address')->nullable();
            $table->string('api_key')->nullable(); // For API communication

            // Status
            $table->boolean('active')->default(true);
            $table->dateTime('last_sync')->nullable();
            $table->boolean('sync_in_progress')->default(false);

            // Configuration
            $table->json('settings')->nullable(); // Device-specific settings
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'branch_id']);
        });

        // Hardware attendance records (raw data from devices)
        Schema::create('hardware_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hardware_device_id')->constrained('hardware_devices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('scanned_at');
            $table->enum('action', ['check_in', 'check_out', 'unknown'])->default('unknown');
            $table->string('rfid_card')->nullable(); // Card ID if RFID
            $table->string('fingerprint_id')->nullable(); // Fingerprint ID if biometric
            $table->boolean('synced_to_attendance')->default(false);

            $table->timestamps();
            $table->index(['company_id', 'scanned_at']);
            $table->index(['hardware_device_id', 'scanned_at']);
            $table->index(['user_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_attendance_records');
        Schema::dropIfExists('hardware_devices');
    }
};
