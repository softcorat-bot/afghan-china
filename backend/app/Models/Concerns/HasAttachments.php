<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Adds polymorphic attachments (photos, receipts, documents) to any model.
 * Avatars are stored as attachments with kind = 'avatar'; the latest one wins.
 */
trait HasAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    /** The current profile photo (latest avatar attachment), if any. */
    public function avatar(): ?Attachment
    {
        return $this->attachments()->where('kind', 'avatar')->first();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $a = $this->relationLoaded('attachments')
            ? $this->attachments->firstWhere('kind', 'avatar')
            : $this->avatar();

        return $a ? "/api/attachments/{$a->id}/view" : null;
    }
}
