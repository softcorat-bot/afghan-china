<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One push, pull or acknowledgement from one device. Kept so that "did the tills
 * sync last night?" is answerable from the server, not from memory.
 */
class SyncBatch extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cursor_before' => 'integer',
            'cursor_after' => 'integer',
        ];
    }

    public function scopeProblems($query)
    {
        return $query->whereIn('status', ['partial', 'failed'])->orWhereIn('direction', ['ack']);
    }
}
