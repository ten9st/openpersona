'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ActionBar, NavLink } from '@/components/nav-links';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Card } from '@/components/ui/card';

type DraftPost = {
  id: number;
  title: string;
  status: string;
  updated_at: string;
  category: {
    id: number;
    name: string;
  };
};

export default function DraftsPage() {
  const router = useRouter();

  const [drafts, setDrafts] = useState<DraftPost[]>([]);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  const fetchDrafts = async () => {
    const token = localStorage.getItem('openpersona_token');

    if (!token) {
      router.push('/login');
      return;
    }

    setMessage('読み込み中...');
    setIsError(false);

    const res = await fetch('http://localhost:8000/api/posts/drafts', {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('下書き一覧の取得に失敗しました。');
      setIsError(true);
      return;
    }

    setDrafts(data.posts);
    setMessage('');
  };

  useEffect(() => {
    fetchDrafts();
  }, []);

  const formatDate = (iso: string) => {
    return new Date(iso).toLocaleString('ja-JP');
  };

  return (
    <PageShell maxWidth="xl">
      <PageHeader
        title="下書き一覧"
        description="保存した下書きを編集して公開できます"
      />

      <ActionBar>
        <NavLink href="/posts/create" variant="primary">
          新規作成
        </NavLink>
        <NavLink href="/posts">公開済み一覧</NavLink>
      </ActionBar>

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      <div className="grid gap-4">
        {drafts.length === 0 && !message && (
          <Card>
            <p className="text-center text-muted">下書きはありません。</p>
          </Card>
        )}

        {drafts.map((post) => (
          <Link key={post.id} href={`/posts/${post.id}/edit`}>
            <Card className="transition-colors hover:border-primary/30">
              <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span className="rounded-full bg-accent px-2.5 py-0.5 font-medium text-primary">
                  {post.category.name}
                </span>
                <span className="rounded-full border border-border px-2.5 py-0.5 text-muted">
                  下書き
                </span>
              </div>

              <h2 className="text-lg font-semibold text-foreground">
                {post.title}
              </h2>

              <p className="mt-3 text-xs text-muted">
                更新 {formatDate(post.updated_at)}
              </p>
            </Card>
          </Link>
        ))}
      </div>
    </PageShell>
  );
}
