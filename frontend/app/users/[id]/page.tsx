'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { IdentityVerifiedBadge } from '@/components/identity-verified-badge';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';
import { followUser, unfollowUser } from '@/lib/follow';
import {
  formatAuthorSummary,
  formatTrustScoreRatio,
  type TrustScoreSummary,
} from '@/lib/post-author';

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
  trust_score: TrustScoreSummary;
  identity_verified: boolean;
  followers_count: number;
  following_count: number;
  is_following?: boolean;
  educations: PublicEducation[];
  careers: PublicCareer[];
};

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
  const router = useRouter();
  const params = useParams();
  const userId = params.id as string;

  const [profile, setProfile] = useState<PublicProfile | null>(null);
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [isFollowing, setIsFollowing] = useState(false);
  const [followersCount, setFollowersCount] = useState(0);
  const [followingCount, setFollowingCount] = useState(0);
  const [isTogglingFollow, setIsTogglingFollow] = useState(false);
  const [followMessage, setFollowMessage] = useState('');
  const [followIsError, setFollowIsError] = useState(false);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);
  const loadedUserId = useRef<string | undefined>(undefined);

  const fetchProfile = useCallback(async () => {
    if (!userId) {
      return;
    }

    setMessage('読み込み中...');
    setIsError(false);

    const token = getAuthToken();

    const res = await fetch(`${API_BASE}/api/users/${userId}`, {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('プロフィールの取得に失敗しました。');
      setIsError(true);
      setProfile(null);
      return;
    }

    setProfile(data);
    setFollowersCount(data.followers_count ?? 0);
    setFollowingCount(data.following_count ?? 0);
    setIsFollowing(Boolean(data.is_following));
    setMessage('');
  }, [userId]);

  useEffect(() => {
    const token = getAuthToken();
    setIsLoggedIn(!!token);

    if (!token) {
      setCurrentUserId(null);
    } else {
      fetch(`${API_BASE}/api/me`, {
        headers: authHeaders(token),
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.user?.id != null) {
            setCurrentUserId(data.user.id);
          }
        })
        .catch(() => setCurrentUserId(null));
    }

    if (loadedUserId.current === userId) {
      return;
    }

    loadedUserId.current = userId;
    fetchProfile();
  }, [fetchProfile, userId]);

  const isOwnProfile =
    profile != null &&
    currentUserId != null &&
    profile.user.id === currentUserId;

  const toggleFollow = async () => {
    const token = getAuthToken();

    if (!token) {
      router.push('/login');
      return;
    }

    setIsTogglingFollow(true);
    setFollowMessage('');
    setFollowIsError(false);

    try {
      const data = isFollowing
        ? await unfollowUser(userId)
        : await followUser(userId);

      setIsFollowing(data.is_following);
      setFollowersCount(data.followers_count);
      setFollowingCount(data.following_count);
    } catch (error) {
      setFollowMessage(
        error instanceof Error ? error.message : 'フォロー操作に失敗しました。',
      );
      setFollowIsError(true);
    } finally {
      setIsTogglingFollow(false);
    }
  };

  const displayName = profile
    ? `${profile.user.last_name}${profile.user.first_name ?? ''}`
    : '';

  return (
    <PageShell maxWidth="lg">
      <PageHeader title={profile ? displayName : 'プロフィール'} />

      <div className="mb-6">
        <button
          type="button"
          onClick={() => router.back()}
          className="text-sm text-primary hover:underline"
        >
          ← 戻る
        </button>
      </div>

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      {profile && (
        <div className="grid gap-6">
          <Card>
            <div className="flex flex-wrap items-center gap-3">
              <h2 className="text-lg font-semibold text-foreground">基本情報</h2>
              <IdentityVerifiedBadge verified={profile.identity_verified} />
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-4 text-sm">
              {isLoggedIn ? (
                <>
                  <Link
                    href={`/users/${userId}/followers`}
                    className="text-primary hover:underline"
                  >
                    フォロワー {followersCount}
                  </Link>
                  <Link
                    href={`/users/${userId}/following`}
                    className="text-primary hover:underline"
                  >
                    フォロイング {followingCount}
                  </Link>
                </>
              ) : (
                <>
                  <span className="text-muted">フォロワー {followersCount}</span>
                  <span className="text-muted">フォロイング {followingCount}</span>
                </>
              )}

              {isLoggedIn && !isOwnProfile && (
                <Button
                  type="button"
                  variant={isFollowing ? 'primary' : 'secondary'}
                  onClick={toggleFollow}
                  disabled={isTogglingFollow}
                >
                  {isTogglingFollow
                    ? '処理中...'
                    : isFollowing
                      ? 'フォロー中'
                      : 'フォローする'}
                </Button>
              )}
            </div>

            {followMessage && (
              <div className="mt-4">
                <Alert
                  message={followMessage}
                  variant={followIsError ? 'error' : 'info'}
                />
              </div>
            )}

            <dl className="mt-4 grid gap-3 text-sm">
              <div className="flex flex-wrap gap-x-2">
                <dt className="font-medium text-muted">透明性スコア</dt>
                <dd className="text-foreground">
                  {formatTrustScoreRatio(profile.trust_score)}
                </dd>
              </div>
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
                    education.end_year,
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
