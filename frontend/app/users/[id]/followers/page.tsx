'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { UserCard } from '@/components/user-card';
import { Card } from '@/components/ui/card';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';
import { type PostAuthor } from '@/lib/post-author';

export default function FollowersPage() {
  const router = useRouter();
  const params = useParams();
  const userId = params.id as string;

  const [users, setUsers] = useState<PostAuthor[]>([]);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  useEffect(() => {
    const token = getAuthToken();

    if (!token) {
      router.push('/login');
      return;
    }

    const fetchFollowers = async () => {
      setMessage('読み込み中...');
      setIsError(false);

      const res = await fetch(`${API_BASE}/api/users/${userId}/followers`, {
        headers: authHeaders(token),
      });

      const data = await res.json();

      if (!res.ok) {
        setMessage('フォロワー一覧の取得に失敗しました。');
        setIsError(true);
        return;
      }

      setUsers(data.users ?? []);
      setMessage('');
    };

    fetchFollowers();
  }, [router, userId]);

  return (
    <PageShell maxWidth="lg">
      <PageHeader title="フォロワー" description="このユーザーをフォローしている人" />

      <div className="mb-6">
        <Link
          href={`/users/${userId}`}
          className="text-sm text-primary hover:underline"
        >
          ← プロフィールに戻る
        </Link>
      </div>

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      <div className="grid gap-4">
        {users.length === 0 && !message && (
          <Card>
            <p className="text-center text-muted">フォロワーはいません。</p>
          </Card>
        )}

        {users.map((user) => (
          <UserCard key={user.id} user={user} />
        ))}
      </div>
    </PageShell>
  );
}
