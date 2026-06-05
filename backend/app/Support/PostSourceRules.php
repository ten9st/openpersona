<?php

namespace App\Support;

use App\Models\PostSource;
use App\Rules\HttpOnlyUrl;
use Illuminate\Validation\Rule;

class PostSourceRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $required = false): array
    {
        $sourcesRule = $required ? ['required', 'array'] : ['nullable', 'array'];

        return [
            'sources' => $sourcesRule,
            ...self::itemRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemRules(): array
    {
        return [
            'sources.*.source_type' => ['required', 'string', Rule::in(PostSource::TYPES)],
            'sources.*.title' => ['nullable', 'string', 'max:255'],
            'sources.*.url' => ['nullable', 'string', 'max:2048', new HttpOnlyUrl],
            'sources.*.note' => ['nullable', 'string'],
        ];
    }
}
