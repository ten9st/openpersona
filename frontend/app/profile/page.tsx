'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { CheckboxLabel, Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';

type Profile = {
  display_last_name: string;
  display_first_name: string | null;
  age_public: boolean;
  full_name_public: boolean;
  biography: string | null;
  occupation: string | null;
  occupation_public: boolean;
  region: string | null;
  region_public: boolean;
};

export default function ProfilePage() {
  const router = useRouter();

  const [profile, setProfile] = useState<Profile>({
    display_last_name: '',
    display_first_name: '',
    age_public: true,
    full_name_public: false,
    biography: '',
    occupation: '',
    occupation_public: false,
    region: '',
    region_public: false,
  });

  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  const getToken = () => {
    return localStorage.getItem('openpersona_token');
  };

  const fetchProfile = async () => {
    const token = getToken();

    if (!token) {
      router.push('/login');
      return;
    }

    const res = await fetch('http://localhost:8000/api/profile', {
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

    setProfile(data.profile);
  };

  const updateProfile = async () => {
    const token = getToken();

    if (!token) {
      router.push('/login');
      return;
    }

    const res = await fetch('http://localhost:8000/api/profile', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(profile),
    });

    const data = await res.json();

    if (!res.ok) {
      console.log(data);
      setMessage('プロフィール更新に失敗しました。');
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
        description="公開プロフィールの内容を設定します"
      />

      <Card>
        <div className="grid gap-6">
          <div className="grid gap-5 sm:grid-cols-2">
            <Label>
              公開用の姓
              <Input
                value={profile.display_last_name}
                onChange={(e) =>
                  setProfile({
                    ...profile,
                    display_last_name: e.target.value,
                  })
                }
              />
            </Label>

            <Label>
              公開用の名
              <Input
                value={profile.display_first_name ?? ''}
                onChange={(e) =>
                  setProfile({
                    ...profile,
                    display_first_name: e.target.value,
                  })
                }
              />
            </Label>
          </div>

          <CheckboxLabel>
            <input
              type="checkbox"
              className="size-4 rounded border-border text-primary focus:ring-ring"
              checked={profile.full_name_public}
              onChange={(e) =>
                setProfile({
                  ...profile,
                  full_name_public: e.target.checked,
                })
              }
            />
            氏名を公開する
          </CheckboxLabel>

          <Label>
            自己紹介
            <Textarea
              rows={5}
              value={profile.biography ?? ''}
              onChange={(e) =>
                setProfile({
                  ...profile,
                  biography: e.target.value,
                })
              }
            />
          </Label>

          <div className="grid gap-5 sm:grid-cols-2">
            <Label>
              職業
              <Input
                value={profile.occupation ?? ''}
                onChange={(e) =>
                  setProfile({
                    ...profile,
                    occupation: e.target.value,
                  })
                }
              />
            </Label>

            <Label>
              地域
              <Input
                value={profile.region ?? ''}
                onChange={(e) =>
                  setProfile({
                    ...profile,
                    region: e.target.value,
                  })
                }
              />
            </Label>
          </div>

          <div className="flex flex-wrap gap-3 pt-2">
            <Button onClick={updateProfile}>プロフィールを保存</Button>
            <Button variant="secondary" onClick={() => router.push('/posts')}>
              キャンセル
            </Button>
          </div>

          {message && (
            <Alert message={message} variant={isError ? 'error' : 'info'} />
          )}
        </div>
      </Card>
    </PageShell>
  );
}
