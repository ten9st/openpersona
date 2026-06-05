<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HttpOnlyUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('URLの形式が正しくありません。');

            return;
        }

        if (! preg_match('/^https?:\/\/.+/i', $value)) {
            $fail('URLは http:// または https:// で始めてください。');
        }
    }
}
