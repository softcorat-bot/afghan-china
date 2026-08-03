<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model {
    use BelongsToCompany;
    protected $guarded = ['id'];
    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];
    protected $appends = ['created_at_human'];
    public function getCreatedAtHumanAttribute() { return $this->created_at?->diffForHumans(); }
}
