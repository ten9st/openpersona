'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

type RegisterResponse = {
  message: string;
  user?: {
    id: number;
    email: string;
    last_name: string;
    first_name: string;
    birthdate: string;
  };
};

export default function RegisterPage() {
  const router = useRouter();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('password123');
  const [lastName, setLastName] = useState('山田');
  const [firstName, setFirstName] = useState('太郎');
  const [birthdate, setBirthdate] = useState('1990-01-01');
  const [message, setMessage] = useState('');

  const register = async () => {
    setMessage('登録中...');

    const res = await fetch('http://localhost:8000/api/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        email,
        password,
        last_name: lastName,
        first_name: firstName,
        birthdate,
      }),
    });

    const data: RegisterResponse = await res.json();

    if (!res.ok) {
      setMessage(data.message ?? '登録に失敗しました。');
      return;
    }

    setMessage(data.message);
    router.push('/login');
  };

  return (
    <main style={{ padding: 40 }}>
      <h1>新規登録</h1>

      <div style={{ display: 'grid', gap: 12, maxWidth: 400 }}>
        <input
          placeholder="メールアドレス"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />

        <input
          placeholder="パスワード"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />

        <input
          placeholder="姓"
          value={lastName}
          onChange={(e) => setLastName(e.target.value)}
        />

        <input
          placeholder="名"
          value={firstName}
          onChange={(e) => setFirstName(e.target.value)}
        />

        <input
          type="date"
          value={birthdate}
          onChange={(e) => setBirthdate(e.target.value)}
        />

        <button onClick={register}>登録する</button>

        {message && <p>{message}</p>}
      </div>
    </main>
  );
}