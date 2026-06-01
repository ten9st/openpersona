<?php

namespace App\Support;

use App\Models\ProfileVisibility;

class ProfileVisibilityDefaults
{
    /**
     * @return array<string, bool>
     */
    public static function values(): array
    {
        return [
            'last_name' => false,
            'first_name' => false,
            'full_name' => false,
            'age' => true,
            'biography' => false,
            'occupation' => false,
            'region' => false,
        ];
    }

    public static function seedForUser(int $userId): void
    {
        foreach (self::values() as $fieldName => $isPublic) {
            ProfileVisibility::updateOrCreate(
                [
                    'user_id' => $userId,
                    'field_name' => $fieldName,
                ],
                ['is_public' => $isPublic]
            );
        }
    }
}
