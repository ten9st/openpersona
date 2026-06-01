'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';

type ProfileFields = {
  biography: string | null;
  occupation: string | null;
  region: string | null;
};

type ProfileVisibilities = {
  last_name: boolean;
  first_name: boolean;
  full_name: boolean;
  age: boolean;
  biography: boolean;
  occupation: boolean;
  region: boolean;
};

type ProfileUser = {
  last_name: string;
  first_name: string;
};

const defaultVisibilities: ProfileVisibilities = {
  last_name: false,
  first_name: false,
  full_name: false,
  age: true,
  biography: false,
  occupation: false,
  region: false,
};

export default function ProfilePage() {
  const router = useRouter();

  const [profile, setProfile] = useState<ProfileFields>({
    biography: '',
    occupation: '',
    region: '',
  });
  const [visibilities, setVisibilities] =
    useState<ProfileVisibilities>(defaultVisibilities);
  const [user, setUser] = useState<ProfileUser>({
    last_name: '',
    first_name: '',
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

    setProfile({
      biography: data.profile.biography ?? '',
      occupation: data.profile.occupation ?? '',
      region: data.profile.region ?? '',
    });
    setVisibilities(data.visibilities);
    setUser(data.user);
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
      body: JSON.stringify({
        biography: profile.biography,
        occupation: profile.occupation,
        region: profile.region,
        visibilities,
      }),
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

  const toggleVisibility = (field: keyof ProfileVisibilities) => {
    setVisibilities((current) => ({
      ...current,
      [field]: !current[field],
    }));
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