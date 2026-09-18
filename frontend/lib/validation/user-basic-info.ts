import { isPrefecture } from '@/lib/prefectures';

export const NAME_MAX = 50;
export const MIN_AGE = 13;
export const MAX_AGE = 120;

/** 漢字・ひらがな・カタカナ・長音・中点・スペース・英字（\p{} は SWC 非対応のため範囲指定） */
export const NAME_PATTERN =
  /^[\u4E00-\u9FFF\u3040-\u309F\u30A0-\u30FFー・\sA-Za-z]+$/;

export type BasicInfoFields = {
  last_name: string;
  first_name: string;
  birthdate?: string;
  region?: string;
};

export type BasicInfoErrors = Partial<Record<keyof BasicInfoFields, string>>;

const validateName = (value: string, label: '姓' | '名'): string | undefined => {
  const trimmed = value.trim();

  if (!trimmed) {
    return `${label}を入力してください。`;
  }

  if (trimmed.length > NAME_MAX) {
    return `${label}は${NAME_MAX}文字以内で入力してください。`;
  }

  if (!NAME_PATTERN.test(trimmed)) {
    return `${label}は漢字・ひらがな・カタカナ・英字のみ使用できます。`;
  }

  return undefined;
};

const validateBirthdate = (value: string): string | undefined => {
  const trimmed = value.trim();

  if (!trimmed) {
    return '生年月日を入力してください。';
  }

  if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    return '生年月日は YYYY-MM-DD 形式で入力してください。';
  }

  const [year, month, day] = trimmed.split('-').map(Number);
  const birthdate = new Date(year, month - 1, day);

  if (
    birthdate.getFullYear() !== year ||
    birthdate.getMonth() !== month - 1 ||
    birthdate.getDate() !== day
  ) {
    return '生年月日の形式が正しくありません。';
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  birthdate.setHours(0, 0, 0, 0);

  if (birthdate > today) {
    return '生年月日に未来の日付は指定できません。';
  }

  const age = today.getFullYear() - birthdate.getFullYear();
  const hadBirthdayThisYear =
    today.getMonth() > birthdate.getMonth() ||
    (today.getMonth() === birthdate.getMonth() &&
      today.getDate() >= birthdate.getDate());
  const actualAge = hadBirthdayThisYear ? age : age - 1;

  if (actualAge < MIN_AGE || actualAge > MAX_AGE) {
    return `${MIN_AGE}歳以上${MAX_AGE}歳以下で入力してください。`;
  }

  return undefined;
};

const validateRegion = (value: string | undefined): string | undefined => {
  const trimmed = (value ?? '').trim();

  if (!trimmed) {
    return '都道府県を選択してください。';
  }

  if (!isPrefecture(trimmed)) {
    return '都道府県を正しく選択してください。';
  }

  return undefined;
};

export const validateUserBasicInfo = (
  fields: BasicInfoFields,
  options: { requireRegion?: boolean; skipBirthdate?: boolean } = {}
): BasicInfoErrors => {
  const errors: BasicInfoErrors = {};

  const lastNameError = validateName(fields.last_name, '姓');
  if (lastNameError) errors.last_name = lastNameError;

  const firstNameError = validateName(fields.first_name, '名');
  if (firstNameError) errors.first_name = firstNameError;

  if (!options.skipBirthdate) {
    const birthdateError = validateBirthdate(fields.birthdate ?? '');
    if (birthdateError) errors.birthdate = birthdateError;
  }

  if (options.requireRegion) {
    const regionError = validateRegion(fields.region);
    if (regionError) errors.region = regionError;
  }

  return errors;
};

export const trimBasicInfo = (
  fields: BasicInfoFields
): BasicInfoFields => ({
  last_name: fields.last_name.trim(),
  first_name: fields.first_name.trim(),
  ...(fields.birthdate !== undefined
    ? { birthdate: fields.birthdate.trim() }
    : {}),
  region: fields.region?.trim(),
});

export const parseApiFieldErrors = (
  errors: Record<string, string[]> | undefined
): BasicInfoErrors => {
  if (!errors) return {};

  const result: BasicInfoErrors = {};
  for (const key of ['last_name', 'first_name', 'birthdate', 'region'] as const) {
    if (errors[key]?.[0]) {
      result[key] = errors[key][0];
    }
  }
  return result;
};

export const birthdateInputBounds = (): { min: string; max: string } => {
  const today = new Date();
  const max = new Date(today);
  max.setFullYear(today.getFullYear() - MIN_AGE);

  const min = new Date(today);
  min.setFullYear(today.getFullYear() - MAX_AGE);

  const format = (date: Date) => date.toISOString().slice(0, 10);

  return { min: format(min), max: format(max) };
};
