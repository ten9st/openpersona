'use client';

import { useEffect, useState } from 'react';

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

export default function Home() {
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

  const token =
    typeof window !== 'undefined'
      ? localStorage.getItem('openpersona_token')
      : null;

  const fetchProfile = async () => {
    if (!token) {
      setMessage('先にログインしてください。');
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
      setMessage('プロフィール取得に失敗しました。');
      return;
    }

    setProfile(data.profile);
    setMessage('');
  };

  const profilePayload = {
    display_last_name: profile.display_last_name,
    display_first_name: profile.display_first_name,
    age_public: profile.age_public,
    full_name_public: profile.full_name_public,
    biography: profile.biography,
    occupation: profile.occupation,
    occupation_public: profile.occupation_public,
    region: profile.region,
    region_public: profile.region_public,
  };

  const updateProfile = async () => {
    if (!token) {
      setMessage('先にログインしてください。');
      return;
    }

    const res = await fetch('http://localhost:8000/api/profile', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(profilePayload),
    });

    const data = await res.json();

    if (!res.ok) {
      console.log(data);
      setMessage('プロフィール更新に失敗しました。');
      return;
    }

    setProfile(data.profile);
    setMessage(data.message);
  };

  useEffect(() => {
    fetchProfile();
  }, []);

  return (
    <main style={{ padding: 40 }}>
      <h1>OpenPersona プロフィール編集</h1>

      <div style={{ display: 'grid', gap: 12, maxWidth: 560 }}>
        <label>
          公開用の姓
          <input
            value={profile.display_last_name}
            onChange={(e) =>
              setProfile({ ...profile, display_last_name: e.target.value })
            }
          />
        </label>

        <label>
          公開用の名
          <input
            value={profile.display_first_name ?? ''}
            onChange={(e) =>
              setProfile({ ...profile, display_first_name: e.target.value })
            }
          />
        </label>

        <label>
          <input
            type="checkbox"
            checked={profile.full_name_public}
            onChange={(e) =>
              setProfile({ ...profile, full_name_public: e.target.checked })
            }
          />
          氏名を公開する
        </label>

        <label>
          <input
            type="checkbox"
            checked={profile.age_public}
            onChange={(e) =>
              setProfile({ ...profile, age_public: e.target.checked })
            }
          />
          年齢を公開する
        </label>

        <label>
          自己紹介
          <textarea
            rows={4}
            value={profile.biography ?? ''}
            onChange={(e) =>
              setProfile({ ...profile, biography: e.target.value })
            }
          />
        </label>

        <label>
          職業
          <input
            value={profile.occupation ?? ''}
            onChange={(e) =>
              setProfile({ ...profile, occupation: e.target.value })
            }
          />
        </label>

        <label>
          <input
            type="checkbox"
            checked={profile.occupation_public}
            onChange={(e) =>
              setProfile({ ...profile, occupation_public: e.target.checked })
            }
          />
          職業を公開する
        </label>

        <label>
          地域
          <input
            value={profile.region ?? ''}
            onChange={(e) =>
              setProfile({ ...profile, region: e.target.value })
            }
          />
        </label>

        <label>
          <input
            type="checkbox"
            checked={profile.region_public}
            onChange={(e) =>
              setProfile({ ...profile, region_public: e.target.checked })
            }
          />
          地域を公開する
        </label>

        <button onClick={updateProfile}>プロフィールを保存</button>

        {message && <p>{message}</p>}
      </div>
    </main>
  );
}
