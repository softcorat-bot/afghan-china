<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareAttendanceRecord extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'synced_to_attendance' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(HardwareDevice::class, 'hardware_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
