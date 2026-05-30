'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

type LoginResponse = {
  message: string;
  token?: string;
  user?: {
    id: number;
    email: string;
    last_name: string;
    first_name: string;
  };
};

export default function LoginPage() {
  const router = useRouter();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('password123');
  const [message, setMessage] = useState('');

  const login = async () => {
    setMessage('ログイン中...');

    const res = await fetch('http://localhost:8000/api/login', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        email,
        password,
      }),
    });

    const data: LoginResponse = await res.json();

    if (!res.ok || !data.token) {
      setMessage(data.message ?? 'ログインに失敗しました。');
      return;
    }

    localStorage.setItem('openpersona_token', data.token);
    router.push('/posts');
  };

  return (
    <main style={{ padding: 40 }}>
      <h1>ログイン</h1>

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

        <button onClick={login}>ログインする</button>

        {message && <p>{message}</p>}
      </div>
    </main>
  );
}