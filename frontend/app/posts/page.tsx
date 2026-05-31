'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ActionBar, NavLink } from '@/components/nav-links';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

type Post = {
  id: number;
  title: string;
  view_count: number;
  bookmark_count: number;
  published_at: string | null;

  user: {
    id: number;
    last_name: string;
    first_name: string;
  };

  category: {
    id: number;
    name: string;
  };
};

export default function PostsPage() {
  const router = useRouter();

  const [posts, setPosts] = useState<Post[]>([]);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  const logout = () => {
    localStorage.removeItem('openpersona_token');
    router.push('/login');
  };

  const fetchPosts = async () => {
    setMessage('読み込み中...');
    setIsError(false);

    const res = await fetch('http://localhost:8000/api/posts', {
      headers: {
        Accept: 'application/json',
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('投稿一覧取得に失敗しました。');
      setIsError(true);
      return;
    }

    setPosts(data.posts);
    setMessage('');
  };

  useEffect(() => {
    fetchPosts();
  }, []);

  return (
    <PageShell maxWidth="xl">
      <PageHeader
        title="投稿一覧"
        description="みんなの投稿を読んで、信頼できる情報を見つけましょう"
      />

      <ActionBar>
        <NavLink href="/posts/create" variant="primary">
          投稿する
        </NavLink>
        <NavLink href="/profile">プロフィール編集</NavLink>
        <Button variant="ghost" onClick={logout}>
          ログアウト
        </Button>
      </ActionBar>

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      <div className="grid gap-4">
        {posts.length === 0 && !message && (
          <Card>
            <p className="text-center text-muted">まだ投稿がありません。</p>
          </Card>
        )}

        {posts.map((post) => (
          <Link key={post.id} href={`/posts/${post.id}`}>
            <Card className="transition-colors hover:border-primary/30">
              <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span className="rounded-full bg-accent px-2.5 py-0.5 font-medium text-primary">
                  {post.category.name}
                </span>
                <span className="text-muted">
                  {post.user.last_name}
                  {post.user.first_name}
                </span>
              </div>

              <h2 className="text-lg font-semibold text-foreground">
                {post.title}
              </h2>

              <div className="mt-4 flex gap-4 border-t border-border pt-4 text-xs text-muted">
                <span>閲覧 {post.view_count}</span>
                <span>付箋 {post.bookmark_count}</span>
              </div>
            </Card>
          </Link>
        ))}
      </div>
    </PageShell>
  );
}
