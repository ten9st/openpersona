'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';

type PostResponse = {
  message?: string;
  post?: {
    id: number;
    title: string;
    body: string;
    status: string;
  };
};

export default function EditPostPage() {
  const router = useRouter();
  const params = useParams();
  const postId = params.id as string;

  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [status, setStatus] = useState<'draft' | 'published'>('draft');
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  const getToken = () => localStorage.getItem('openpersona_token');

  const fetchPost = async () => {
    const token = getToken();

    if (!token) {
      router.push('/login');
      return;
    }

    setIsLoading(true);
    setIsError(false);

    const res = await fetch(`http://localhost:8000/api/posts/${postId}`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('投稿の取得に失敗しました。');
      setIsError(true);
      setIsLoading(false);
      return;
    }

    setTitle(data.post.title);
    setBody(data.post.body);
    setStatus(data.post.status === 'published' ? 'published' : 'draft');
    setIsLoading(false);
  };

  useEffect(() => {
    fetchPost();
  }, [postId]);

  const updatePost = async (nextStatus: 'draft' | 'published') => {
    const token = getToken();

    if (!token) {
      router.push('/login');
      return;
    }

    setMessage(nextStatus === 'draft' ? '保存中...' : '公開中...');
    setIsError(false);

    const res = await fetch(`http://localhost:8000/api/posts/${postId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        category_id: 1,
        title,
        body,
        status: nextStatus,
      }),
    });

    const data: PostResponse = await res.json();

    if (!res.ok) {
      setMessage(data.message ?? '更新に失敗しました。');
      setIsError(true);
      return;
    }

    if (nextStatus === 'published') {
      router.push('/posts');
      return;
    }

    router.push('/posts/drafts');
  };

  if (isLoading) {
    return (
      <PageShell maxWidth="lg">
        <Alert message="読み込み中..." variant="info" />
      </PageShell>
    );
  }

  return (
    <PageShell maxWidth="lg">
      <PageHeader
        title="投稿を編集"
        description={
          status === 'draft'
            ? '下書きを編集して公開できます'
            : '公開済みの投稿を編集できます'
        }
      />

      <Card>
        <div className="grid gap-6">
          <Label>
            タイトル
            <Input value={title} onChange={(e) => setTitle(e.target.value)} />
          </Label>

          <Label>
            本文
            <Textarea
              rows={12}
              value={body}
              onChange={(e) => setBody(e.target.value)}
            />
          </Label>

          <div className="flex flex-wrap gap-3">
            <Button onClick={() => updatePost('published')}>公開する</Button>
            <Button variant="secondary" onClick={() => updatePost('draft')}>
              下書き保存
            </Button>
            <Button
              variant="ghost"
              onClick={() =>
                router.push(status === 'draft' ? '/posts/drafts' : '/posts')
              }
            >
              キャンセル
            </Button>
          </div>

          {message && (
            <Alert message={message} variant={isError ? 'error' : 'info'} />
          )}
        </div>
      </Card>
    </PageShell>
  );
}
