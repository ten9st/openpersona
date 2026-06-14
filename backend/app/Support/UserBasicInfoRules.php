<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;

class UserBasicInfoRules
{
    public const NAME_MAX = 50;

    public const MIN_AGE = 13;

    public const MAX_AGE = 120;

    /** 漢字・ひらがな・カタカナ・長音・中点・スペース・英字 */
    public const NAME_PATTERN = '/^[\p{Han}\p{Hiragana}\p{Katakana}ー・\sA-Za-z]+$/u';

    /**
     * users テーブルの基本情報（登録・プロフィール共通）
     *
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    public static function userRules(): array
    {
        $minBirthdate = now()->subYears(self::MAX_AGE)->startOfDay()->format('Y-m-d');
        $maxBirthdate = now()->subYears(self::MIN_AGE)->endOfDay()->format('Y-m-d');

        return [
            'last_name' => ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN],
            'first_name' => ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN],
            'birthdate' => [
                'required',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:'.$minBirthdate,
                'before_or_equal:'.$maxBirthdate,
            ],
        ];
    }

    /**
     * プロフィール編集の基本情報（都道府県を含む。email・birthdate は更新対象外）
     *
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    public static function profileRules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN],
            'first_name' => ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN],
            'region' => ['required', 'string', Rule::in(Prefectures::all())],
        ];
    }

    /**
     * 本人確認済みユーザー向け（姓・名は変更不可・都道府県のみ検証）
     *
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    public static function lockedProfileRules(User $user): array
    {
        return [
            'last_name' => ['required', Rule::in([$user->last_name])],
            'first_name' => ['required', Rule::in([$user->first_name])],
            'region' => ['required', 'string', Rule::in(Prefectures::all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'last_name.required' => '姓を入力してください。',
            'last_name.max' => '姓は'.self::NAME_MAX.'文字以内で入力してください。',
            'last_name.regex' => '姓は漢字・ひらがな・カタカナ・英字のみ使用できます。',
            'first_name.required' => '名を入力してください。',
            'first_name.max' => '名は'.self::NAME_MAX.'文字以内で入力してください。',
            'first_name.regex' => '名は漢字・ひらがな・カタカナ・英字のみ使用できます。',
            'birthdate.required' => '生年月日を入力してください。',
            'birthdate.date' => '生年月日の形式が正しくありません。',
            'birthdate.date_format' => '生年月日は YYYY-MM-DD 形式で入力してください。',
            'birthdate.after_or_equal' => '生年月日が正しくありません。',
            'birthdate.before_or_equal' => self::MIN_AGE.'歳以上'.self::MAX_AGE.'歳以下で入力してください。',
            'region.required' => '都道府県を選択してください。',
            'region.in' => '都道府県を正しく選択してください。',
            'last_name.in' => '本人確認後は姓を変更できません。',
            'first_name.in' => '本人確認後は名を変更できません。',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function trimInput(array $input): array
    {
        foreach (['last_name', 'first_name', 'birthdate', 'region'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        return $input;
    }
}
