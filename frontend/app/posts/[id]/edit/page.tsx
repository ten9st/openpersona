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

/** 編集画面の初期化に使う、取得した投稿(GET /api/posts/{id} の post の一部) */
type EditablePost = {
  title: string;
  body: string;
  status: string;
  category_id: number;
  sources?: Parameters<typeof fromApiPostSource>[0][];
};

type PostResult =
  | { status: 'success'; post: EditablePost }
  | { status: 'error' };

// 投稿IDを読み、投稿IDごとに取得部分を作り直す。
// 投稿IDが変わると EditPostLoader とその内側のフォームは key により新しく作られ、
// 取得結果・カテゴリ一覧・未保存の入力は破棄される(前の投稿の結果は表示しない)。
// ページ遷移でアンマウントされるかどうかには依存しない。
export default function EditPostPage() {
  const params = useParams();
  const postId = params.id as string;

  return <EditPostLoader key={postId} postId={postId} />;
}

type EditPostLoaderProps = {
  postId: string;
};

// 投稿とカテゴリの取得と、読み込み中・取得失敗・フォームの表示の切り替えを担当する。
// 投稿の取得に失敗した場合は、編集・保存・公開・コピーの操作を表示しない。
function EditPostLoader({ postId }: EditPostLoaderProps) {
  const router = useRouter();

  // 未取得(null = 読み込み中)から始まる
  const [result, setResult] = useState<PostResult | null>(null);
  // 取得前・取得失敗時は空のまま(カテゴリ欄は従来どおり「読み込み中...」で選択不可)
  const [categories, setCategories] = useState<Category[]>([]);

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

    const fetchPost = async () => {
      try {
        const res = await fetch(`${API_BASE}/api/posts/${postId}`, {
          headers: authHeaders(token),
        });

        const data = await res.json();

        if (ignore) {
          return;
        }

        setResult(
          res.ok ? { status: 'success', post: data.post } : { status: 'error' },
        );
      } catch {
        // 通信失敗・JSONとして読めない応答
        if (!ignore) {
          setResult({ status: 'error' });
        }
      }
    };

    fetchPost();

    return () => {
      ignore = true;
    };
  }, [postId, router]);

  useEffect(() => {
    // cleanup の後に届いた応答は反映しない。取得に失敗した場合は何も反映しない。
    let ignore = false;

    const fetchCategories = async () => {
      try {
        const res = await fetch(`${API_BASE}/api/categories`, {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();

        if (!ignore && res.ok) {
          setCategories(data.categories ?? []);
        }
      } catch {
        // 通信失敗・JSONとして読めない応答。カテゴリ一覧は空のまま
      }
    };

    fetchCategories();

    return () => {
      ignore = true;
    };
  }, []);

  if (result === null) {
    return (
      <PageShell maxWidth="lg">
        <Alert message="読み込み中..." variant="info" />
      </PageShell>
    );
  }

  if (result.status === 'error') {
    return (
      <PageShell maxWidth="lg">
        <Alert message="投稿の取得に失敗しました。" variant="error" />
      </PageShell>
    );
  }

  return (
    <EditPostForm postId={postId} post={result.post} categories={categories} />
  );
}

type EditPostFormProps = {
  postId: string;
  post: EditablePost;
  categories: Category[];
};

// 取得した投稿で入力状態を初期化し、保存・公開・コピーの操作を担当する。
// post は初期値としてだけ使う。このコンポーネントがマウントされている間は、
// props の変更(カテゴリ一覧の取得完了など)で未保存の入力を初期化し直さない。
function EditPostForm({ postId, post, categories }: EditPostFormProps) {
  const router = useRouter();

  const [categoryId, setCategoryId] = useState(() => String(post.category_id));
  const [title, setTitle] = useState(post.title);
  const [body, setBody] = useState(post.body);
  const [sources, setSources] = useState<PostSourceInput[]>(() =>
    (post.sources ?? []).map((source) => fromApiPostSource(source)),
  );
  const status: 'draft' | 'published' =
    post.status === 'published' ? 'published' : 'draft';
  const [message, setMessage] = useState('');
  const [isError, setIsError] = useState(false);
  const [isCopying, setIsCopying] = useState(false);

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
