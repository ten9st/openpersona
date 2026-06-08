'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Card } from '@/components/ui/card';
import { PostAttachmentsEditor } from '@/components/post-attachments-editor';
import { PostSourcesEditor } from '@/components/post-sources-editor';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';
import {
  toApiPostSources,
  validatePostSources,
  type PostSourceInput,
} from '@/lib/post-source';
import {
  uploadPostAttachments,
  type PendingAttachment,
} from '@/lib/post-attachment';

type Category = {
  id: number;
  name: string;
  slug: string;
};

type PostResponse = {
  message: string;
  post?: {
    id: number;
    title: string;
    body: string;
    status: string;
  };
};

export default function CreatePostPage() {
  const router = useRouter();

  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryId, setCategoryId] = useState('');
  const [title, setTitle] = useState('日本のエネルギー政策について');
  const [body, setBody] = useState('ここに本文を書きます。');
  const [sources, setSources] = useState<PostSourceInput[]>([]);
  const [attachments, setAttachments] = useState<PendingAttachment[]>([]);
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);

  useEffect(() => {
    const fetchCategories = async () => {
      const res = await fetch(`${API_BASE}/api/categories`, {
        headers: { Accept: 'application/json' },
      });
      const data = await res.json();

      if (!res.ok) {
        return;
      }

      const list: Category[] = data.categories ?? [];
      setCategories(list);
      if (list.length > 0) {
        setCategoryId(String(list[0].id));
      }
    };

    fetchCategories();
  }, []);

  const createPost = async (status: 'draft' | 'published') => {
    const token = getAuthToken();

    if (!token) {
      router.push('/login');
      return;
    }

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

    setMessage(status === 'draft' ? '下書き保存中...' : '公開中...');
    setIsError(false);

    const apiSources = toApiPostSources(sources);

    const res = await fetch(`${API_BASE}/api/posts`, {
      method: 'POST',
      headers: {
        ...authHeaders(token),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        category_id: Number(categoryId),
        title,
        body,
        status,
        ...(apiSources.length > 0 ? { sources: apiSources } : {}),
      }),
    });

    const data: PostResponse = await res.json();

    if (!res.ok) {
      console.log(data);
      setMessage(data.message ?? '保存に失敗しました。');
      setIsError(true);
      return;
    }

    const postId = data.post?.id;

    if (postId && attachments.length > 0) {
      try {
        await uploadPostAttachments(
          postId,
          attachments.map((item) => item.file),
        );
      } catch (error) {
        setMessage(
          error instanceof Error
            ? `投稿は保存しましたが、${error.message}`
            : '投稿は保存しましたが、添付ファイルのアップロードに失敗しました。',
        );
        setIsError(true);
        return;
      }
    }

    router.push(status === 'draft' ? '/posts/drafts' : '/posts');
  };

  return (
    <PageShell maxWidth="lg">
      <PageHeader
        title="投稿作成"
        description="信頼できる情報を共有しましょう"
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
            <Input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
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

          <PostAttachmentsEditor
            attachments={attachments}
            onChange={setAttachments}
          />

          <div className="flex flex-wrap gap-3">
            <Button onClick={() => createPost('published')}>公開する</Button>
            <Button
              variant="secondary"
              onClick={() => createPost('draft')}
            >
              下書き保存
            </Button>
            <Button variant="ghost" onClick={() => router.push('/posts')}>
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
