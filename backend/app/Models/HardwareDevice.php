<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HardwareDevice extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sync_in_progress' => 'boolean',
            'last_sync' => 'datetime',
            'settings' => 'json',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(HardwareAttendanceRecord::class);
    }

    public function unsyncedRecords()
    {
        return $this->attendanceRecords()->where('synced_to_attendance', false);
    }
}
