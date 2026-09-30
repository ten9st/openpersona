'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ActionBar, NavLink } from '@/components/nav-links';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Card } from '@/components/ui/card';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

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

type DraftsResult =
  | { status: 'success'; drafts: DraftPost[] }
  | { status: 'error' };

export default function DraftsPage() {
  const router = useRouter();

  // 未取得(null = 読み込み中)から始まる
  const [result, setResult] = useState<DraftsResult | null>(null);

  useEffect(() => {
    const token = getAuthToken();

    // トークンが無ければログイン画面へ移動する(読み込み表示のまま)
    if (!token) {
      router.push('/login');
      return;
    }

    // cleanup(アンマウント、Strict Mode での再実行、router の変更による再実行など)の後に
    // 届いた成功・失敗は反映しない。通信自体は中断しない。
    let ignore = false;

    const fetchDrafts = async () => {
      try {
        const res = await fetch(`${API_BASE}/api/posts/drafts`, {
          headers: authHeaders(token),
        });

        const data = await res.json();

        if (ignore) {
          return;
        }

        setResult(
          res.ok ? { status: 'success', drafts: data.posts } : { status: 'error' },
        );
      } catch {
        // 通信失敗・JSONとして読めない応答
        if (!ignore) {
          setResult({ status: 'error' });
        }
      }
    };

    fetchDrafts();

    return () => {
      ignore = true;
    };
  }, [router]);

  const drafts = result?.status === 'success' ? result.drafts : [];

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

      {result === null && (
        <div className="mb-6">
          <Alert message="読み込み中..." variant="info" />
        </div>
      )}

      {result?.status === 'error' && (
        <div className="mb-6">
          <Alert message="下書き一覧の取得に失敗しました。" variant="error" />
        </div>
      )}

      <div className="grid gap-4">
        {result?.status === 'success' && drafts.length === 0 && (
          <Card>
            <p className="text-center text-muted">下書きはありません。</p>
          </Card>
        )}

        {drafts.map((post) => (
          <Card key={post.id}>
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

            <div className="mt-4 flex flex-wrap gap-2">
              <NavLink href={`/posts/${post.id}/edit`} variant="primary">
                編集する<span className="sr-only">：{post.title}</span>
              </NavLink>
              <NavLink href={`/posts/${post.id}`}>
                詳細を見る<span className="sr-only">：{post.title}</span>
              </NavLink>
            </div>
          </Card>
        ))}
      </div>
    </PageShell>
  );
}
