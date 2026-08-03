<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'string'];
    }

    public function translatable()
    {
        return $this->morphTo();
    }

    // Get translated value for a field, with fallback to English
    public static function get(string $type, int $id, string $field, string $language = 'en'): ?string
    {
        $translation = self::where('translatable_type', $type)
            ->where('translatable_id', $id)
            ->where('language', $language)
            ->where('field', $field)
            ->first();

        if ($translation) {
            return $translation->value;
        }

        // Fallback to English if translation not found
        if ($language !== 'en') {
            return self::get($type, $id, $field, 'en');
        }

        return null;
    }

    // Store a translation
    public static function store(string $type, int $id, string $field, string $language, string $value): void
    {
        self::updateOrCreate(
            [
                'translatable_type' => $type,
                'translatable_id' => $id,
                'language' => $language,
                'field' => $field,
            ],
            ['value' => $value]
        );
    }
}
