'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { NavLink } from '@/components/nav-links';
import { AuthorLink } from '@/components/author-link';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { API_BASE } from '@/lib/api';
import { type PostAuthor } from '@/lib/post-author';

type Comment = {
  id: number;
  body: string;
  created_at: string;
  user: PostAuthor;
};

type Post = {
  id: number;
  title: string;
  body: string;
  view_count: number;
  bookmark_count: number;
  published_at: string | null;
  comments: Comment[];

  user: PostAuthor;

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
  const [commentBody, setCommentBody] = useState('');
  const [commentMessage, setCommentMessage] = useState('');
  const [commentIsError, setCommentIsError] = useState(false);
  const [isSubmittingComment, setIsSubmittingComment] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [deleteMessage, setDeleteMessage] = useState('');
  const [deleteIsError, setDeleteIsError] = useState(false);
  const loadedPostId = useRef<string | string[] | undefined>(undefined);

  const fetchPost = useCallback(async () => {
    if (!postId) {
      return;
    }

    setMessage('読み込み中...');
    setIsError(false);

    const token = localStorage.getItem('openpersona_token');

    const res = await fetch(`${API_BASE}/api/posts/${postId}`, {
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('投稿の取得に失敗しました。');
      setIsError(true);
      return;
    }

    setPost({
      ...data.post,
      comments: data.post.comments ?? [],
    });
    setMessage('');
  }, [postId]);

  useEffect(() => {
    const token = localStorage.getItem('openpersona_token');
    setIsLoggedIn(!!token);

    if (!token) {
      setCurrentUserId(null);
    } else {
      fetch(`${API_BASE}/api/me`, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.user?.id != null) {
            setCurrentUserId(data.user.id);
          }
        })
        .catch(() => setCurrentUserId(null));
    }

    if (loadedPostId.current === postId) {
      return;
    }

    loadedPostId.current = postId;
    fetchPost();
  }, [fetchPost, postId]);

  const isAuthor =
    post != null && currentUserId != null && post.user.id === currentUserId;

  const deletePost = async () => {
    const token = localStorage.getItem('openpersona_token');

    if (!token) {
      router.push('/login');
      return;
    }

    setIsDeleting(true);
    setDeleteMessage('');
    setDeleteIsError(false);

    const res = await fetch(`${API_BASE}/api/posts/${postId}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setDeleteMessage(data.message ?? '投稿の削除に失敗しました。');
      setDeleteIsError(true);
      setIsDeleting(false);
      return;
    }

    setShowDeleteDialog(false);
    router.push('/posts');
  };

  const submitComment = async () => {
    const token = localStorage.getItem('openpersona_token');

    if (!token) {
      router.push('/login');
      return;
    }

    if (!commentBody.trim()) {
      setCommentMessage('コメントを入力してください。');
      setCommentIsError(true);
      return;
    }

    setIsSubmittingComment(true);
    setCommentMessage('投稿中...');
    setCommentIsError(false);

    const res = await fetch(`${API_BASE}/api/posts/${postId}/comments`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ body: commentBody }),
    });

    const data = await res.json();

    if (!res.ok) {
      setCommentMessage(data.message ?? 'コメントの投稿に失敗しました。');
      setCommentIsError(true);
      setIsSubmittingComment(false);
      return;
    }

    setCommentBody('');
    setCommentMessage('');
    setIsSubmittingComment(false);
    await fetchPost();
  };

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
        <>
          <Card>
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
              <span className="rounded-full bg-accent px-2.5 py-0.5 font-medium text-primary">
                {post.category.name}
              </span>
              <AuthorLink user={post.user} />
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

            <div className="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-border pt-4">
              <div className="flex gap-4 text-xs text-muted">
                <span>閲覧 {post.view_count}</span>
                <span>付箋 {post.bookmark_count}</span>
              </div>
              {isAuthor && (
                <Button
                  type="button"
                  variant="destructive"
                  onClick={() => {
                    setDeleteMessage('');
                    setDeleteIsError(false);
                    setShowDeleteDialog(true);
                  }}
                >
                  投稿を削除
                </Button>
              )}
            </div>
          </Card>

          <section className="mt-8">
            <h2 className="mb-4 text-lg font-semibold text-foreground">
              コメント ({post.comments.length})
            </h2>

            {isLoggedIn ? (
              <Card className="mb-6">
                <div className="grid gap-4">
                  <Label>
                    コメントを書く
                    <Textarea
                      rows={4}
                      value={commentBody}
                      onChange={(e) => setCommentBody(e.target.value)}
                      placeholder="コメントを入力..."
                      disabled={isSubmittingComment}
                    />
                  </Label>
                  <div className="flex flex-wrap items-center gap-3">
                    <Button
                      onClick={submitComment}
                      disabled={isSubmittingComment}
                    >
                      コメントを投稿
                    </Button>
                    <p className="text-xs text-muted">
                      投稿したコメントは削除できません。
                    </p>
                  </div>
                  {commentMessage && (
                    <Alert
                      message={commentMessage}
                      variant={commentIsError ? 'error' : 'info'}
                    />
                  )}
                </div>
              </Card>
            ) : (
              <Card className="mb-6">
                <p className="text-sm text-muted">
                  コメントするには{' '}
                  <Link href="/login" className="font-medium text-primary hover:underline">
                    ログイン
                  </Link>
                  してください。
                </p>
              </Card>
            )}

            {post.comments.length === 0 ? (
              <p className="text-sm text-muted">まだコメントはありません。</p>
            ) : (
              <ul className="grid gap-4">
                {post.comments.map((comment) => (
                  <li key={comment.id}>
                    <Card>
                      <div className="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <AuthorLink user={comment.user} className="font-medium" />
                        <span className="text-muted">
                          {new Date(comment.created_at).toLocaleString('ja-JP')}
                        </span>
                      </div>
                      <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground/80">
                        {comment.body}
                      </p>
                    </Card>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
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

      {showDeleteDialog && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="delete-post-dialog-title"
        >
          <Card className="w-full max-w-md shadow-lg">
            <h2
              id="delete-post-dialog-title"
              className="text-lg font-semibold text-foreground"
            >
              投稿を削除しますか？
            </h2>
            <p className="mt-2 text-sm text-muted">
              この操作は取り消せません。投稿は一覧・詳細から非表示になります。
            </p>
            {deleteMessage && (
              <div className="mt-4">
                <Alert message={deleteMessage} variant={deleteIsError ? 'error' : 'info'} />
              </div>
            )}
            <div className="mt-6 flex flex-wrap justify-end gap-3">
              <Button
                type="button"
                variant="secondary"
                onClick={() => setShowDeleteDialog(false)}
                disabled={isDeleting}
              >
                キャンセル
              </Button>
              <Button
                type="button"
                variant="destructive"
                onClick={deletePost}
                disabled={isDeleting}
              >
                {isDeleting ? '削除中...' : '削除する'}
              </Button>
            </div>
          </Card>
        </div>
      )}
    </PageShell>
  );
}
