'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';

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
      return;
    }

    setMessage(data.message);

    router.push('/posts');
  };

  useEffect(() => {
    fetchProfile();
  }, []);

  return (
    <main style={{ padding: 40 }}>
      <h1>プロフィール編集</h1>

      <div style={{ display: 'grid', gap: 12, maxWidth: 560 }}>
        <label>
          公開用の姓
          <input
            value={profile.display_last_name}
            onChange={(e) =>
              setProfile({
                ...profile,
                display_last_name: e.target.value,
              })
            }
          />
        </label>

        <label>
          公開用の名
          <input
            value={profile.display_first_name ?? ''}
            onChange={(e) =>
              setProfile({
                ...profile,
                display_first_name: e.target.value,
              })
            }
          />
        </label>

        <label>
          <input
            type="checkbox"
            checked={profile.full_name_public}
            onChange={(e) =>
              setProfile({
                ...profile,
                full_name_public: e.target.checked,
              })
            }
          />
          氏名を公開する
        </label>

        <label>
          自己紹介
          <textarea
            rows={5}
            value={profile.biography ?? ''}
            onChange={(e) =>
              setProfile({
                ...profile,
                biography: e.target.value,
              })
            }
          />
        </label>

        <label>
          職業
          <input
            value={profile.occupation ?? ''}
            onChange={(e) =>
              setProfile({
                ...profile,
                occupation: e.target.value,
              })
            }
          />
        </label>

        <label>
          地域
          <input
            value={profile.region ?? ''}
            onChange={(e) =>
              setProfile({
                ...profile,
                region: e.target.value,
              })
            }
          />
        </label>

        <button onClick={updateProfile}>
          プロフィールを保存
        </button>

        {message && <p>{message}</p>}
      </div>
    </main>
  );
}