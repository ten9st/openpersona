'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';
import { API_BASE, ensureCsrfCookie, getCsrfToken } from '@/lib/api';

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
  const [isError, setIsError] = useState(false);

  const login = async () => {
    setMessage('ログイン中...');
    setIsError(false);

    await ensureCsrfCookie();

    const csrfToken = getCsrfToken();

    const res = await fetch(`${API_BASE}/api/login`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
      body: JSON.stringify({
        email,
        password,
      }),
    });

    const data: LoginResponse = await res.json();

    if (!res.ok || !data.token) {
      setMessage(data.message ?? 'ログインに失敗しました。');
      setIsError(true);
      return;
    }

    localStorage.setItem('openpersona_token', data.token);
    router.push('/posts');
  };

  return (
    <PageShell maxWidth="sm">
      <PageHeader
        title="ログイン"
        description="メールアドレスとパスワードでサインイン"
      />

      <Card>
        <div className="grid gap-5">
          <Label>
            メールアドレス
            <Input
              placeholder="you@example.com"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </Label>

          <Label>
            パスワード
            <Input
              placeholder="パスワード"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </Label>

          <Button onClick={login} className="w-full">
            ログインする
          </Button>

          {message && (
            <Alert message={message} variant={isError ? 'error' : 'info'} />
          )}

          <p className="text-center text-sm text-muted">
            アカウントをお持ちでない方は{' '}
            <Link href="/register" className="font-medium text-primary hover:underline">
              新規登録
            </Link>
          </p>
        </div>
      </Card>
    </PageShell>
  );
}
