'use client';

import { Suspense, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { ActionBar, NavLink } from '@/components/nav-links';
import { AuthorLink } from '@/components/author-link';
import { PostTagBadges } from '@/components/post-tag-badges';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { API_BASE, logout as apiLogout } from '@/lib/api';
import { type PostAuthor } from '@/lib/post-author';
import { type PostTag } from '@/lib/post-tag';

type Post = {
  id: number;
  title: string;
  view_count: number;
  bookmark_count: number;
  published_at: string | null;
  user: PostAuthor;
  category: {
    id: number;
    name: string;
  };
  tags?: PostTag[];
};

function PostsPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const tagSlug = searchParams.get('tag');

  const [posts, setPosts] = useState<Post[]>([]);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);

  const logout = async () => {
    await apiLogout();
    setIsLoggedIn(false);
    router.push('/login');
  };

  const fetchPosts = async () => {
    setMessage('読み込み中...');
    setIsError(false);

    const params = new URLSearchParams();
    if (tagSlug) {
      params.set('tag', tagSlug);
    }

    const query = params.toString();
    const res = await fetch(`${API_BASE}/api/posts${query ? `?${query}` : ''}`, {
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
    setIsLoggedIn(Boolean(localStorage.getItem('openpersona_token')));
  }, []);

  useEffect(() => {
    fetchPosts();
  }, [tagSlug]);

  const activeTagName =
    tagSlug != null
      ? posts.flatMap((post) => post.tags ?? []).find((tag) => tag.slug === tagSlug)
          ?.name ?? tagSlug
      : null;

  return (
    <PageShell maxWidth="xl">
      <PageHeader
        title="投稿一覧"
        description="みんなの投稿を読んで、信頼できる情報を見つけましょう"
      />

      <ActionBar>
        {isLoggedIn ? (
          <>
            <NavLink href="/posts/create" variant="primary">
              投稿する
            </NavLink>
            <NavLink href="/posts/drafts">下書き一覧</NavLink>
            <NavLink href="/bookmarks">付箋一覧</NavLink>
            <NavLink href="/timeline">タイムライン</NavLink>
            <NavLink href="/profile">プロフィール編集</NavLink>
            <Button variant="ghost" onClick={logout}>
              ログアウト
            </Button>
          </>
        ) : (
          <NavLink href="/login" variant="primary">
            ログインして投稿する
          </NavLink>
        )}
      </ActionBar>

      {tagSlug && (
        <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm">
          <span className="text-muted">
            タグ{' '}
            <span className="font-medium text-foreground">#{activeTagName}</span>{' '}
            で絞り込み中
          </span>
          <Link
            href="/posts"
            className="font-medium text-primary hover:underline"
          >
            絞り込みを解除
          </Link>
        </div>
      )}

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      <div className="grid gap-4">
        {posts.length === 0 && !message && (
          <Card>
            <p className="text-center text-muted">
              {tagSlug
                ? 'このタグの投稿はまだありません。'
                : 'まだ投稿がありません。'}
            </p>
          </Card>
        )}

        {posts.map((post) => (
          <Card
            key={post.id}
            className="transition-colors hover:border-primary/30"
          >
            <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
              <span className="rounded-full bg-accent px-2.5 py-0.5 font-medium text-primary">
                {post.category.name}
              </span>
              <AuthorLink user={post.user} />
            </div>

            <Link href={`/posts/${post.id}`}>
              <h2 className="text-lg font-semibold text-foreground">
                {post.title}
              </h2>
            </Link>

            <PostTagBadges tags={post.tags ?? []} className="mt-3" />

            <div className="mt-4 flex gap-4 border-t border-border pt-4 text-xs text-muted">
              <span>閲覧 {post.view_count}</span>
              <span>付箋 {post.bookmark_count}</span>
            </div>
          </Card>
        ))}
      </div>
    </PageShell>
  );
}

export default function PostsPage() {
  return (
    <Suspense
      fallback={
        <PageShell maxWidth="xl">
          <PageHeader title="投稿一覧" description="読み込み中..." />
        </PageShell>
      }
    >
      <PostsPageContent />
    </Suspense>
  );
}
