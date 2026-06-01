'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Card } from '@/components/ui/card';
import { formatAuthorSummary } from '@/lib/post-author';

type PublicEducation = {
  school_name: string;
  faculty: string | null;
  degree: string | null;
  start_year: number | null;
  end_year: number | null;
};

type PublicCareer = {
  company_name: string;
  position: string | null;
  start_year: number | null;
  end_year: number | null;
  is_current: boolean;
};

type PublicProfile = {
  user: {
    id: number;
    last_name: string;
    first_name: string | null;
    age: number | null;
    region: string | null;
  };
  profile: {
    biography: string | null;
    occupation: string | null;
  };
  educations: PublicEducation[];
  careers: PublicCareer[];
};

const API_BASE = 'http://localhost:8000/api';

const formatYearRange = (start: number | null, end: number | null) => {
  if (start == null && end == null) {
    return null;
  }

  if (start != null && end != null) {
    return `${start}年 - ${end}年`;
  }

  if (start != null) {
    return `${start}年 -`;
  }

  return `- ${end}年`;
};

export default function PublicProfilePage() {
  const params = useParams();
  const userId = params.id;

  const [profile, setProfile] = useState<PublicProfile | null>(null);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);
  const loadedUserId = useRef<string | string[] | undefined>(undefined);

  const fetchProfile = useCallback(async () => {
    if (!userId) {
      return;
    }

    setMessage('読み込み中...');
    setIsError(false);

    const res = await fetch(`${API_BASE}/users/${userId}`, {
      headers: { Accept: 'application/json' },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('プロフィールの取得に失敗しました。');
      setIsError(true);
      setProfile(null);
      return;
    }

    setProfile(data);
    setMessage('');
  }, [userId]);

  useEffect(() => {
    if (loadedUserId.current === userId) {
      return;
    }

    loadedUserId.current = userId;
    fetchProfile();
  }, [fetchProfile, userId]);

  const displayName = profile
    ? `${profile.user.last_name}${profile.user.first_name ?? ''}`
    : '';

  return (
    <PageShell maxWidth="lg">
      <PageHeader
        title={profile ? displayName : 'プロフィール'}
        description="公開されているプロフィール情報を表示しています"
      />

      <div className="mb-6">
        <Link href="/posts" className="text-sm text-primary hover:underline">
          ← 投稿一覧に戻る
        </Link>
      </div>

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      {profile && (
        <div className="grid gap-6">
          <Card>
            <h2 className="text-lg font-semibold text-foreground">基本情報</h2>
            <dl className="mt-4 grid gap-3 text-sm">
              <div className="flex flex-wrap gap-x-2">
                <dt className="font-medium text-muted">氏名</dt>
                <dd className="text-foreground">
                  {formatAuthorSummary(profile.user).split(' · ')[0]}
                </dd>
              </div>
              {profile.user.region && (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-muted">都道府県</dt>
                  <dd className="text-foreground">{profile.user.region}</dd>
                </div>
              )}
              {profile.user.age != null && (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-muted">年齢</dt>
                  <dd className="text-foreground">{profile.user.age}歳</dd>
                </div>
              )}
              {profile.profile.occupation && (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-muted">職業</dt>
                  <dd className="text-foreground">{profile.profile.occupation}</dd>
                </div>
              )}
            </dl>
          </Card>

          {profile.profile.biography && (
            <Card>
              <h2 className="text-lg font-semibold text-foreground">自己紹介</h2>
              <p className="mt-4 whitespace-pre-wrap text-sm leading-relaxed text-foreground/80">
                {profile.profile.biography}
              </p>
            </Card>
          )}

          {profile.educations.length > 0 && (
            <Card>
              <h2 className="text-lg font-semibold text-foreground">学歴</h2>
              <ul className="mt-4 grid gap-4">
                {profile.educations.map((education) => {
                  const yearRange = formatYearRange(
                    education.start_year,
                    education.end_year
                  );

                  return (
                    <li
                      key={`${education.school_name}-${education.start_year}-${education.end_year}`}
                      className="border-b border-border pb-4 last:border-b-0 last:pb-0"
                    >
                      <p className="font-medium text-foreground">
                        {education.school_name}
                      </p>
                      {(education.faculty || education.degree) && (
                        <p className="mt-1 text-sm text-muted">
                          {[education.faculty, education.degree]
                            .filter(Boolean)
                            .join(' · ')}
                        </p>
                      )}
                      {yearRange && (
                        <p className="mt-1 text-xs text-muted">{yearRange}</p>
                      )}
                    </li>
                  );
                })}
              </ul>
            </Card>
          )}

          {profile.careers.length > 0 && (
            <Card>
              <h2 className="text-lg font-semibold text-foreground">職歴</h2>
              <ul className="mt-4 grid gap-4">
                {profile.careers.map((career) => {
                  const period = career.is_current
                    ? career.start_year != null
                      ? `${career.start_year}年 - 現在`
                      : '現在'
                    : formatYearRange(career.start_year, career.end_year);

                  return (
                    <li
                      key={`${career.company_name}-${career.start_year}-${career.end_year}`}
                      className="border-b border-border pb-4 last:border-b-0 last:pb-0"
                    >
                      <p className="font-medium text-foreground">
                        {career.company_name}
                        {career.is_current && (
                          <span className="ml-2 text-xs font-normal text-primary">
                            現職
                          </span>
                        )}
                      </p>
                      {career.position && (
                        <p className="mt-1 text-sm text-muted">{career.position}</p>
                      )}
                      {period && (
                        <p className="mt-1 text-xs text-muted">{period}</p>
                      )}
                    </li>
                  );
                })}
              </ul>
            </Card>
          )}
        </div>
      )}
    </PageShell>
  );
}
