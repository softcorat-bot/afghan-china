<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Counter extends Model
{
    use BelongsToBranch, BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function endOfDayReports(): HasMany
    {
        return $this->hasMany(CounterEndOfDay::class);
    }

    // Get counter name in specific language (e.g., "Counter A" or "Counter ۱" in Farsi)
    public function getName(string $language = 'en'): string
    {
        $translated = Translation::get('Counter', $this->id, 'name', $language);
        return $translated ?? $this->name;
    }

    // Get today's end-of-day report
    public function getTodayReport()
    {
        return $this->endOfDayReports()
            ->whereDate('report_date', today())
            ->first();
    }
}
