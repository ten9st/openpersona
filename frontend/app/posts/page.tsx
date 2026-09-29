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

type PostsResult =
  | { status: 'success'; posts: Post[] }
  | { status: 'error' };

type PostsListSectionProps = {
  tagSlug: string | null;
};

// 投稿一覧の取得・絞り込み表示・一覧を担当する。
// 親は取得条件(タグ)をkeyにして描画するため、条件が変わるとこの部分だけ作り直され、
// 結果は未取得(null = 読み込み中)から始まる。前の条件の一覧やエラーは表示しない。
function PostsListSection({ tagSlug }: PostsListSectionProps) {
  const [result, setResult] = useState<PostsResult | null>(null);

  useEffect(() => {
    // cleanup(アンマウント、Strict Mode での再実行など)の後に届いた成功・失敗は反映しない。
    // 通信自体は中断しない。
    let ignore = false;

    const fetchPosts = async () => {
      const params = new URLSearchParams();
      if (tagSlug) {
        params.set('tag', tagSlug);
      }

      const query = params.toString();

      try {
        const res = await fetch(`${API_BASE}/api/posts${query ? `?${query}` : ''}`, {
          headers: {
            Accept: 'application/json',
          },
        });

        const data = await res.json();

        if (ignore) {
          return;
        }

        setResult(
          res.ok ? { status: 'success', posts: data.posts } : { status: 'error' },
        );
      } catch {
        // 通信失敗・JSONとして読めない応答
        if (!ignore) {
          setResult({ status: 'error' });
        }
      }
    };

    fetchPosts();

    return () => {
      ignore = true;
    };
  }, [tagSlug]);

  const posts = result?.status === 'success' ? result.posts : [];

  // タグの表示名は、取得した一覧に含まれるタグから求める。
  // 取得前・取得失敗時・一覧に該当タグが無い場合は、URLのslugをそのまま表示する。
  const activeTagName =
    tagSlug != null
      ? posts.flatMap((post) => post.tags ?? []).find((tag) => tag.slug === tagSlug)
          ?.name ?? tagSlug
      : null;

  return (
    <>
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

      {result === null && (
        <div className="mb-6">
          <Alert message="読み込み中..." variant="info" />
        </div>
      )}

      {result?.status === 'error' && (
        <div className="mb-6">
          <Alert message="投稿一覧取得に失敗しました。" variant="error" />
        </div>
      )}

      {result?.status === 'success' && (
        <div className="grid gap-4">
          {posts.length === 0 && (
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
      )}
    </>
  );
}

function PostsPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const tagSlug = searchParams.get('tag');
  // タグ指定なし(null)と空文字(?tag=)を区別する。APIへ送るクエリは PostsListSection で
  // 従来どおり組み立てる(空文字の場合は tag を送らない)。
  const requestKey = tagSlug === null ? 'all' : `tag:${tagSlug}`;

  const [isLoggedIn, setIsLoggedIn] = useState(false);

  const logout = async () => {
    await apiLogout();
    setIsLoggedIn(false);
    router.push('/login');
  };

  useEffect(() => {
    setIsLoggedIn(Boolean(localStorage.getItem('openpersona_token')));
  }, []);

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

      <PostsListSection key={requestKey} tagSlug={tagSlug} />
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
