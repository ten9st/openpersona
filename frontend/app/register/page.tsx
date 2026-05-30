'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';

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
  const [isError, setIsError] = useState(false);

  const register = async () => {
    setMessage('登録中...');
    setIsError(false);

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
      setIsError(true);
      return;
    }

    setMessage(data.message);
    router.push('/login');
  };

  return (
    <PageShell maxWidth="sm">
      <PageHeader
        title="新規登録"
        description="OpenPersona アカウントを作成"
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

          <div className="grid gap-5 sm:grid-cols-2">
            <Label>
              姓
              <Input
                placeholder="山田"
                value={lastName}
                onChange={(e) => setLastName(e.target.value)}
              />
            </Label>

            <Label>
              名
              <Input
                placeholder="太郎"
                value={firstName}
                onChange={(e) => setFirstName(e.target.value)}
              />
            </Label>
          </div>

          <Label>
            生年月日
            <Input
              type="date"
              value={birthdate}
              onChange={(e) => setBirthdate(e.target.value)}
            />
          </Label>

          <Button onClick={register} className="w-full">
            登録する
          </Button>

          {message && (
            <Alert message={message} variant={isError ? 'error' : 'info'} />
          )}

          <p className="text-center text-sm text-muted">
            すでにアカウントをお持ちの方は{' '}
            <Link href="/login" className="font-medium text-primary hover:underline">
              ログイン
            </Link>
          </p>
        </div>
      </Card>
    </PageShell>
  );
}
