<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'backup_time' => 'datetime',
            'backup_size' => 'integer',
            'duration_seconds' => 'integer',
            'records_backed_up' => 'integer',
        ];
    }
}
