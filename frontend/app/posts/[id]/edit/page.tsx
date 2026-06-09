'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Card } from '@/components/ui/card';
import { PostSourcesEditor } from '@/components/post-sources-editor';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';
import { copyPostAsCorrection } from '@/lib/post-copy';
import {
  fromApiPostSource,
  toApiPostSources,
  validatePostSources,
  type PostSourceInput,
} from '@/lib/post-source';

type Category = {
  id: number;
  name: string;
  slug: string;
};

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

  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryId, setCategoryId] = useState('');
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [sources, setSources] = useState<PostSourceInput[]>([]);
  const [status, setStatus] = useState<'draft' | 'published'>('draft');
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isCopying, setIsCopying] = useState(false);

  const fetchCategories = async () => {
    const res = await fetch(`${API_BASE}/api/categories`, {
      headers: { Accept: 'application/json' },
    });
    const data = await res.json();

    if (res.ok) {
      setCategories(data.categories ?? []);
    }
  };

  const fetchPost = async () => {
    const token = getAuthToken();

    if (!token) {
      router.push('/login');
      return;
    }

    setIsLoading(true);
    setIsError(false);

    const res = await fetch(`${API_BASE}/api/posts/${postId}`, {
      headers: authHeaders(token),
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
    setCategoryId(String(data.post.category_id));
    setStatus(data.post.status === 'published' ? 'published' : 'draft');
    setSources(
      (data.post.sources ?? []).map((source: Parameters<typeof fromApiPostSource>[0]) =>
        fromApiPostSource(source),
      ),
    );
    setIsLoading(false);
  };

  useEffect(() => {
    fetchCategories();
    fetchPost();
  }, [postId]);

  const copyForCorrection = async () => {
    setIsCopying(true);
    setMessage('訂正用の下書きを作成中...');
    setIsError(false);

    try {
      const data = await copyPostAsCorrection(postId);
      router.push(`/posts/${data.post.id}/edit`);
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : 'コピーに失敗しました。',
      );
      setIsError(true);
      setIsCopying(false);
    }
  };

  const updatePost = async (nextStatus: 'draft' | 'published') => {
    const token = getAuthToken();

    if (!token) {
      router.push('/login');
      return;
    }

    setMessage(nextStatus === 'draft' ? '保存中...' : '公開中...');
    setIsError(false);

    if (!categoryId) {
      setMessage('カテゴリを選択してください。');
      setIsError(true);
      return;
    }

    const sourceValidationError = validatePostSources(sources);

    if (sourceValidationError) {
      setMessage(sourceValidationError);
      setIsError(true);
      return;
    }

    const res = await fetch(`${API_BASE}/api/posts/${postId}`, {
      method: 'PUT',
      headers: {
        ...authHeaders(token),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        category_id: Number(categoryId),
        title,
        body,
        status: nextStatus,
        sources: toApiPostSources(sources),
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

  if (status === 'published') {
    return (
      <PageShell maxWidth="lg">
        <PageHeader
          title="公開済みの投稿"
          description="公開済みの投稿は編集できません。コピーして訂正投稿を作成してください。"
        />

        <Card>
          <div className="grid gap-4">
            <p className="text-sm text-muted">
              訂正が必要な場合は、内容を複製した新しい下書きを作成し、修正してから公開してください。元の投稿はそのまま残ります。
            </p>
            <div className="flex flex-wrap gap-3">
              <Button onClick={copyForCorrection} disabled={isCopying}>
                {isCopying ? 'コピー中...' : 'コピーして訂正投稿を作成'}
              </Button>
              <Button variant="ghost" onClick={() => router.push(`/posts/${postId}`)}>
                投稿詳細に戻る
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

  return (
    <PageShell maxWidth="lg">
      <PageHeader
        title="下書きを編集"
        description="下書きを編集して公開できます"
      />

      <Card>
        <div className="grid gap-6">
          <Label>
            カテゴリ
            <Select
              value={categoryId}
              onChange={(e) => setCategoryId(e.target.value)}
              disabled={categories.length === 0}
            >
              {categories.length === 0 ? (
                <option value="">読み込み中...</option>
              ) : (
                categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))
              )}
            </Select>
          </Label>

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

          <PostSourcesEditor sources={sources} onChange={setSources} />

          <div className="flex flex-wrap gap-3">
            <Button onClick={() => updatePost('published')}>公開する</Button>
            <Button variant="secondary" onClick={() => updatePost('draft')}>
              下書き保存
            </Button>
            <Button variant="ghost" onClick={() => router.push('/posts/drafts')}>
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
