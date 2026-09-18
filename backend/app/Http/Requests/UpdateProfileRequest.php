<?php

namespace App\Http\Requests;

use App\Models\ProfileVisibility;
use App\Support\UserBasicInfoRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(UserBasicInfoRules::trimInput($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        $visibilityRules = [];
        foreach (ProfileVisibility::FIELDS as $field) {
            $visibilityRules["visibilities.{$field}"] = ['required', 'boolean'];
        }

        $basicRules = $user->hasLockedBasicInfo()
            ? UserBasicInfoRules::lockedProfileRules($user)
            : UserBasicInfoRules::profileRules();

        return [
            ...$basicRules,
            'biography' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'visibilities' => ['required', 'array'],
            'educations' => ['present', 'array'],
            'educations.*.school_name' => ['required', 'string', 'max:255'],
            'educations.*.faculty' => ['nullable', 'string', 'max:255'],
            'educations.*.degree' => ['nullable', 'string', 'max:255'],
            'educations.*.start_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'educations.*.end_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'educations.*.is_public' => ['required', 'boolean'],
            'careers' => ['present', 'array'],
            'careers.*.company_name' => ['required', 'string', 'max:255'],
            'careers.*.position' => ['nullable', 'string', 'max:255'],
            'careers.*.start_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'careers.*.end_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'careers.*.is_current' => ['required', 'boolean'],
            'careers.*.is_public' => ['required', 'boolean'],
            ...$visibilityRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UserBasicInfoRules::messages();
    }
}
