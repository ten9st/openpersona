'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { NavLink } from '@/components/nav-links';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Card } from '@/components/ui/card';

type Post = {
  id: number;
  title: string;
  body: string;
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
    slug: string;
  };
};

export default function PostDetailPage() {
  const router = useRouter();
  const params = useParams();
  const postId = params.id;

  const [post, setPost] = useState<Post | null>(null);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  useEffect(() => {
    if (!postId) {
      return;
    }

    const fetchPost = async () => {
      setMessage('読み込み中...');
      setIsError(false);

      const res = await fetch(`http://localhost:8000/api/posts/${postId}`, {
        headers: {
          Accept: 'application/json',
        },
      });

      const data = await res.json();

      if (!res.ok) {
        setMessage('投稿の取得に失敗しました。');
        setIsError(true);
        return;
      }

      setPost(data.post);
      setMessage('');
    };

    fetchPost();
  }, [postId]);

  return (
    <PageShell maxWidth="xl">
      <PageHeader
        title={post?.title ?? '投稿詳細'}
        description="投稿の詳細を表示しています"
      />

      <div className="mb-6">
        <NavLink href="/posts">← 投稿一覧に戻る</NavLink>
      </div>

      {message && (
        <div className="mb-6">
          <Alert message={message} variant={isError ? 'error' : 'info'} />
        </div>
      )}

      {post && (
        <Card>
          <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <span className="rounded-full bg-accent px-2.5 py-0.5 font-medium text-primary">
              {post.category.name}
            </span>
            <span className="text-muted">
              {post.user.last_name}
              {post.user.first_name}
            </span>
            {post.published_at && (
              <span className="text-muted">
                {new Date(post.published_at).toLocaleDateString('ja-JP')}
              </span>
            )}
          </div>

          <h1 className="text-2xl font-semibold text-foreground">{post.title}</h1>

          <p className="mt-6 whitespace-pre-wrap text-sm leading-relaxed text-foreground/80">
            {post.body}
          </p>

          <div className="mt-6 flex gap-4 border-t border-border pt-4 text-xs text-muted">
            <span>閲覧 {post.view_count}</span>
            <span>付箋 {post.bookmark_count}</span>
          </div>
        </Card>
      )}

      {isError && (
        <div className="mt-4">
          <button
            type="button"
            onClick={() => router.push('/posts')}
            className="text-sm text-primary hover:underline"
          >
            投稿一覧に戻る
          </button>
        </div>
      )}
    </PageShell>
  );
}
