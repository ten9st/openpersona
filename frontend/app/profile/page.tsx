'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { CheckboxLabel, Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';
import { IdentityVerifiedBadge } from '@/components/identity-verified-badge';
import { Select } from '@/components/ui/select';
import { formatTrustScoreRatio } from '@/lib/post-author';
import { isPrefecture, PREFECTURES } from '@/lib/prefectures';
import {
  birthdateInputBounds,
  NAME_MAX,
  parseApiFieldErrors,
  trimBasicInfo,
  validateUserBasicInfo,
  type BasicInfoErrors,
} from '@/lib/validation/user-basic-info';

type ProfileVisibilities = {
  first_name: boolean;
  biography: boolean;
  occupation: boolean;
};

type EducationForm = {
  key: string;
  school_name: string;
  faculty: string;
  degree: string;
  start_year: string;
  end_year: string;
  is_public: boolean;
};

type CareerForm = {
  key: string;
  company_name: string;
  position: string;
  start_year: string;
  end_year: string;
  is_current: boolean;
  is_public: boolean;
};

type ProfileForm = {
  last_name: string;
  first_name: string;
  birthdate: string;
  biography: string;
  occupation: string;
  region: string;
  visibilities: ProfileVisibilities;
  educations: EducationForm[];
  careers: CareerForm[];
};

const defaultVisibilities = (): ProfileVisibilities => ({
  first_name: false,
  biography: false,
  occupation: false,
});

const newKey = () => crypto.randomUUID();

const emptyEducation = (): EducationForm => ({
  key: newKey(),
  school_name: '',
  faculty: '',
  degree: '',
  start_year: '',
  end_year: '',
  is_public: false,
});

const emptyCareer = (): CareerForm => ({
  key: newKey(),
  company_name: '',
  position: '',
  start_year: '',
  end_year: '',
  is_current: false,
  is_public: false,
});

const defaultForm = (): ProfileForm => ({
  last_name: '',
  first_name: '',
  birthdate: '',
  biography: '',
  occupation: '',
  region: '',
  visibilities: defaultVisibilities(),
  educations: [],
  careers: [],
});

const API_BASE = 'http://localhost:8000/api';

const birthdateBounds = birthdateInputBounds();

const FieldError = ({ message }: { message?: string }) =>
  message ? (
    <span className="text-xs font-normal text-destructive">{message}</span>
  ) : null;

const parseYear = (value: string): number | null => {
  const trimmed = value.trim();
  if (!trimmed) return null;
  const year = Number(trimmed);
  return Number.isInteger(year) ? year : null;
};

const mapEducationFromApi = (item: {
  id: number;
  school_name: string;
  faculty: string | null;
  degree: string | null;
  start_year: number | null;
  end_year: number | null;
  is_public: boolean;
}): EducationForm => ({
  key: String(item.id),
  school_name: item.school_name,
  faculty: item.faculty ?? '',
  degree: item.degree ?? '',
  start_year: item.start_year != null ? String(item.start_year) : '',
  end_year: item.end_year != null ? String(item.end_year) : '',
  is_public: item.is_public,
});

const mapCareerFromApi = (item: {
  id: number;
  company_name: string;
  position: string | null;
  start_year: number | null;
  end_year: number | null;
  is_current: boolean;
  is_public: boolean;
}): CareerForm => ({
  key: String(item.id),
  company_name: item.company_name,
  position: item.position ?? '',
  start_year: item.start_year != null ? String(item.start_year) : '',
  end_year: item.end_year != null ? String(item.end_year) : '',
  is_current: item.is_current,
  is_public: item.is_public,
});

export default function ProfilePage() {
  const router = useRouter();
  const [form, setForm] = useState<ProfileForm>(defaultForm);
  const [basicInfoErrors, setBasicInfoErrors] = useState<BasicInfoErrors>({});
  const [basicInfoLocked, setBasicInfoLocked] = useState(false);
  const [identityVerified, setIdentityVerified] = useState(false);
  const [email, setEmail] = useState('');
  const [trustScore, setTrustScore] = useState<{
    total_score: number;
    max_score: number;
  } | null>(null);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  const getToken = () => localStorage.getItem('openpersona_token');

  const clearBasicInfoError = (field: keyof BasicInfoErrors) => {
    setBasicInfoErrors((prev) => {
      if (!prev[field]) return prev;
      const next = { ...prev };
      delete next[field];
      return next;
    });
  };

  const setVisibility = (
    field: keyof ProfileVisibilities,
    isPublic: boolean
  ) => {
    setForm((prev) => ({
      ...prev,
      visibilities: { ...prev.visibilities, [field]: isPublic },
    }));
  };

  const updateEducation = (
    key: string,
    patch: Partial<Omit<EducationForm, 'key'>>
  ) => {
    setForm((prev) => ({
      ...prev,
      educations: prev.educations.map((e) =>
        e.key === key ? { ...e, ...patch } : e
      ),
    }));
  };

  const updateCareer = (
    key: string,
    patch: Partial<Omit<CareerForm, 'key'>>
  ) => {
    setForm((prev) => {
      let careers = prev.careers.map((c) =>
        c.key === key ? { ...c, ...patch } : c
      );

      if (patch.is_current) {
        careers = careers.map((c) => ({
          ...c,
          is_current: c.key === key,
          end_year: c.key === key ? '' : c.end_year,
        }));
      }

      return { ...prev, careers };
    });
  };

  const fetchProfile = async () => {
    const token = getToken();
    if (!token) {
      router.push('/login');
      return;
    }

    const res = await fetch(`${API_BASE}/profile`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });

    const data = await res.json();

    if (!res.ok) {
      console.log(data);
      setMessage('プロフィール取得に失敗しました。');
      setIsError(true);
      return;
    }

    setBasicInfoLocked(Boolean(data.meta?.basic_info_locked));
    setIdentityVerified(Boolean(data.meta?.identity_verified));
    setEmail(data.user.email ?? '');
    setTrustScore(data.trust_score ?? null);
    setForm({
      last_name: data.user.last_name ?? '',
      first_name: data.user.first_name ?? '',
      birthdate: data.user.birthdate ?? '',
      biography: data.profile.biography ?? '',
      occupation: data.profile.occupation ?? '',
      region: isPrefecture(data.profile.region ?? '')
        ? data.profile.region
        : '',
      visibilities: { ...defaultVisibilities(), ...data.visibilities },
      educations: (data.educations ?? []).map(mapEducationFromApi),
      careers: (data.careers ?? []).map(mapCareerFromApi),
    });
  };

  const updateProfile = async () => {
    const token = getToken();
    if (!token) {
      router.push('/login');
      return;
    }

    const trimmedBasicInfo = trimBasicInfo({
      last_name: form.last_name,
      first_name: form.first_name,
      birthdate: form.birthdate,
      region: form.region,
    });

    const validationErrors = validateUserBasicInfo(trimmedBasicInfo, {
      requireRegion: true,
    });

    if (Object.keys(validationErrors).length > 0) {
      setBasicInfoErrors(validationErrors);
      setMessage('基本情報に入力エラーがあります。');
      setIsError(true);
      return;
    }

    setBasicInfoErrors({});

    const res = await fetch(`${API_BASE}/profile`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        last_name: trimmedBasicInfo.last_name,
        first_name: trimmedBasicInfo.first_name,
        birthdate: trimmedBasicInfo.birthdate,
        biography: form.biography || null,
        occupation: form.occupation || null,
        region: trimmedBasicInfo.region,
        visibilities: form.visibilities,
        educations: form.educations
          .filter((e) => e.school_name.trim())
          .map((e) => ({
            school_name: e.school_name.trim(),
            faculty: e.faculty.trim() || null,
            degree: e.degree.trim() || null,
            start_year: parseYear(e.start_year),
            end_year: parseYear(e.end_year),
            is_public: e.is_public,
          })),
        careers: form.careers
          .filter((c) => c.company_name.trim())
          .map((c) => ({
            company_name: c.company_name.trim(),
            position: c.position.trim() || null,
            start_year: parseYear(c.start_year),
            end_year: c.is_current ? null : parseYear(c.end_year),
            is_current: c.is_current,
            is_public: c.is_public,
          })),
      }),
    });

    const data = await res.json();

    if (!res.ok) {
      console.log(data);
      setBasicInfoErrors(parseApiFieldErrors(data.errors));
      setMessage(
        data.message ??
          (data.errors
            ? '基本情報に入力エラーがあります。'
            : 'プロフィール更新に失敗しました。')
      );
      setIsError(true);
      return;
    }

    setMessage(data.message);
    setIsError(false);
    router.push('/posts');
  };

  useEffect(() => {
    fetchProfile();
  }, []);

  return (
    <PageShell maxWidth="lg">
      <PageHeader
        title="プロフィール編集"
        description="本名・公開プロフィール・学歴・職歴と各項目の公開設定を編集します"
      />

      <div className="grid gap-6">
        <Card>
          <div className="grid gap-6">
            <div className="flex flex-wrap items-center gap-3">
              <h2 className="text-lg font-semibold text-foreground">基本情報</h2>
              <IdentityVerifiedBadge verified={identityVerified} />
            </div>
            <p className="-mt-4 text-sm text-muted">
              姓・年齢・都道府県は投稿一覧で常に表示されます。名の公開は下のチェックで設定できます。
            </p>
            {basicInfoLocked && (
              <p className="rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm text-muted">
                本人確認済みのため、姓・名・生年月日・メールアドレスは変更できません。都道府県・職業・自己紹介などは引き続き編集できます。
              </p>
            )}
            {trustScore && (
              <p className="text-sm text-muted">
                透明性スコア: {formatTrustScoreRatio(trustScore)}
              </p>
            )}

            {email && (
              <Label>
                メールアドレス
                <Input value={email} disabled readOnly />
                {basicInfoLocked && (
                  <span className="mt-1 text-xs font-normal text-muted">
                    本人確認後は変更できません
                  </span>
                )}
              </Label>
            )}

            <div className="grid gap-5 sm:grid-cols-2">
              <Label>
                姓
                <Input
                  value={form.last_name}
                  maxLength={NAME_MAX}
                  disabled={basicInfoLocked}
                  readOnly={basicInfoLocked}
                  onChange={(e) => {
                    clearBasicInfoError('last_name');
                    setForm({ ...form, last_name: e.target.value });
                  }}
                />
                <FieldError message={basicInfoErrors.last_name} />
                <span className="mt-1 text-xs font-normal text-muted">
                  投稿一覧に常に表示
                </span>
              </Label>

              <Label>
                名
                <Input
                  value={form.first_name}
                  maxLength={NAME_MAX}
                  disabled={basicInfoLocked}
                  readOnly={basicInfoLocked}
                  onChange={(e) => {
                    clearBasicInfoError('first_name');
                    setForm({ ...form, first_name: e.target.value });
                  }}
                />
                <FieldError message={basicInfoErrors.first_name} />
                <CheckboxLabel className="mt-2 font-normal">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-border text-primary focus:ring-ring"
                    checked={form.visibilities.first_name}
                    onChange={(e) =>
                      setVisibility('first_name', e.target.checked)
                    }
                  />
                  名を公開する
                </CheckboxLabel>
              </Label>
            </div>

            <Label>
              都道府県
              <Select
                value={form.region}
                onChange={(e) => {
                  clearBasicInfoError('region');
                  setForm({ ...form, region: e.target.value });
                }}
              >
                <option value="">未選択</option>
                {PREFECTURES.map((prefecture) => (
                  <option key={prefecture} value={prefecture}>
                    {prefecture}
                  </option>
                ))}
              </Select>
              <FieldError message={basicInfoErrors.region} />
              <span className="mt-1 text-xs font-normal text-muted">
                投稿一覧に常に表示
              </span>
            </Label>

            <Label>
              生年月日
              <Input
                type="date"
                value={form.birthdate}
                min={birthdateBounds.min}
                max={birthdateBounds.max}
                disabled={basicInfoLocked}
                readOnly={basicInfoLocked}
                onChange={(e) => {
                  clearBasicInfoError('birthdate');
                  setForm({ ...form, birthdate: e.target.value });
                }}
              />
              <FieldError message={basicInfoErrors.birthdate} />
              <span className="mt-1 text-xs font-normal text-muted">
                投稿一覧に常に表示（13歳以上120歳以下）
              </span>
            </Label>
          </div>
        </Card>

        <Card>
          <div className="grid gap-6">
            <h2 className="text-lg font-semibold text-foreground">
              公開プロフィール
            </h2>

            <Label>
              自己紹介
              <Textarea
                rows={5}
                value={form.biography}
                onChange={(e) =>
                  setForm({ ...form, biography: e.target.value })
                }
              />
              <CheckboxLabel className="mt-2 font-normal">
                <input
                  type="checkbox"
                  className="size-4 rounded border-border text-primary focus:ring-ring"
                  checked={form.visibilities.biography}
                  onChange={(e) =>
                    setVisibility('biography', e.target.checked)
                  }
                />
                自己紹介を公開する
              </CheckboxLabel>
            </Label>

            <div className="grid gap-5 sm:grid-cols-2">
              <Label>
                職業
                <Input
                  value={form.occupation}
                  onChange={(e) =>
                    setForm({ ...form, occupation: e.target.value })
                  }
                />
                <CheckboxLabel className="mt-2 font-normal">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-border text-primary focus:ring-ring"
                    checked={form.visibilities.occupation}
                    onChange={(e) =>
                      setVisibility('occupation', e.target.checked)
                    }
                  />
                  職業を公開する
                </CheckboxLabel>
              </Label>
            </div>
          </div>
        </Card>

        <Card>
          <div className="grid gap-6">
            <div>
              <h2 className="text-lg font-semibold text-foreground">学歴</h2>
              <p className="mt-1 text-sm text-muted">
                複数登録できます。上から順に表示されます。
              </p>
            </div>

            {form.educations.length === 0 && (
              <p className="text-sm text-muted">学歴はまだ登録されていません。</p>
            )}

            {form.educations.map((education, index) => (
              <div
                key={education.key}
                className="grid gap-4 rounded-lg border border-border p-4"
              >
                <div className="flex items-center justify-between gap-2">
                  <p className="text-sm font-medium text-foreground">
                    学歴 {index + 1}
                  </p>
                  <Button
                    type="button"
                    variant="ghost"
                    className="shrink-0 px-2 py-1 text-xs"
                    onClick={() =>
                      setForm((prev) => ({
                        ...prev,
                        educations: prev.educations.filter(
                          (e) => e.key !== education.key
                        ),
                      }))
                    }
                  >
                    削除
                  </Button>
                </div>

                <Label>
                  学校名
                  <Input
                    value={education.school_name}
                    onChange={(e) =>
                      updateEducation(education.key, {
                        school_name: e.target.value,
                      })
                    }
                  />
                </Label>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Label>
                    学部
                    <Input
                      value={education.faculty}
                      onChange={(e) =>
                        updateEducation(education.key, {
                          faculty: e.target.value,
                        })
                      }
                    />
                  </Label>
                  <Label>
                    学位
                    <Input
                      value={education.degree}
                      onChange={(e) =>
                        updateEducation(education.key, {
                          degree: e.target.value,
                        })
                      }
                    />
                  </Label>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Label>
                    開始年
                    <Input
                      type="number"
                      min={1900}
                      max={2100}
                      placeholder="例: 2010"
                      value={education.start_year}
                      onChange={(e) =>
                        updateEducation(education.key, {
                          start_year: e.target.value,
                        })
                      }
                    />
                  </Label>
                  <Label>
                    終了年
                    <Input
                      type="number"
                      min={1900}
                      max={2100}
                      placeholder="例: 2014"
                      value={education.end_year}
                      onChange={(e) =>
                        updateEducation(education.key, {
                          end_year: e.target.value,
                        })
                      }
                    />
                  </Label>
                </div>

                <CheckboxLabel>
                  <input
                    type="checkbox"
                    className="size-4 rounded border-border text-primary focus:ring-ring"
                    checked={education.is_public}
                    onChange={(e) =>
                      updateEducation(education.key, {
                        is_public: e.target.checked,
                      })
                    }
                  />
                  この学歴を公開する
                </CheckboxLabel>
              </div>
            ))}

            <Button
              type="button"
              variant="secondary"
              size="sm"
              className="w-auto"
              onClick={() =>
                setForm((prev) => ({
                  ...prev,
                  educations: [...prev.educations, emptyEducation()],
                }))
              }
            >
              学歴を追加
            </Button>
          </div>
        </Card>

        <Card>
          <div className="grid gap-6">
            <div>
              <h2 className="text-lg font-semibold text-foreground">職歴</h2>
              <p className="mt-1 text-sm text-muted">
                現職は1件のみ指定できます。上から順に表示されます。
              </p>
            </div>

            {form.careers.length === 0 && (
              <p className="text-sm text-muted">職歴はまだ登録されていません。</p>
            )}

            {form.careers.map((career, index) => (
              <div
                key={career.key}
                className="grid gap-4 rounded-lg border border-border p-4"
              >
                <div className="flex items-center justify-between gap-2">
                  <p className="text-sm font-medium text-foreground">
                    職歴 {index + 1}
                  </p>
                  <Button
                    type="button"
                    variant="ghost"
                    className="shrink-0 px-2 py-1 text-xs"
                    onClick={() =>
                      setForm((prev) => ({
                        ...prev,
                        careers: prev.careers.filter(
                          (c) => c.key !== career.key
                        ),
                      }))
                    }
                  >
                    削除
                  </Button>
                </div>

                <Label>
                  会社名
                  <Input
                    value={career.company_name}
                    onChange={(e) =>
                      updateCareer(career.key, {
                        company_name: e.target.value,
                      })
                    }
                  />
                </Label>

                <Label>
                  役職
                  <Input
                    value={career.position}
                    onChange={(e) =>
                      updateCareer(career.key, { position: e.target.value })
                    }
                  />
                </Label>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Label>
                    開始年
                    <Input
                      type="number"
                      min={1900}
                      max={2100}
                      placeholder="例: 2018"
                      value={career.start_year}
                      onChange={(e) =>
                        updateCareer(career.key, {
                          start_year: e.target.value,
                        })
                      }
                    />
                  </Label>
                  <Label>
                    終了年
                    <Input
                      type="number"
                      min={1900}
                      max={2100}
                      placeholder="例: 2024"
                      value={career.end_year}
                      disabled={career.is_current}
                      onChange={(e) =>
                        updateCareer(career.key, { end_year: e.target.value })
                      }
                    />
                  </Label>
                </div>

                <div className="grid gap-3">
                  <CheckboxLabel>
                    <input
                      type="checkbox"
                      className="size-4 rounded border-border text-primary focus:ring-ring"
                      checked={career.is_current}
                      onChange={(e) =>
                        updateCareer(career.key, {
                          is_current: e.target.checked,
                        })
                      }
                    />
                    現職
                  </CheckboxLabel>
                  <CheckboxLabel>
                    <input
                      type="checkbox"
                      className="size-4 rounded border-border text-primary focus:ring-ring"
                      checked={career.is_public}
                      onChange={(e) =>
                        updateCareer(career.key, { is_public: e.target.checked })
                      }
                    />
                    この職歴を公開する
                  </CheckboxLabel>
                </div>
              </div>
            ))}

            <Button
              type="button"
              variant="secondary"
              size="sm"
              className="w-auto"
              onClick={() =>
                setForm((prev) => ({
                  ...prev,
                  careers: [...prev.careers, emptyCareer()],
                }))
              }
            >
              職歴を追加
            </Button>
          </div>
        </Card>

        <div className="flex flex-wrap gap-3">
          <Button onClick={updateProfile}>プロフィールを保存</Button>
          <Button variant="secondary" onClick={() => router.push('/posts')}>
            キャンセル
          </Button>
        </div>

        {message && (
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        )}
      </div>
    </PageShell>
  );
}
