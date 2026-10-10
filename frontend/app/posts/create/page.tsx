'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
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
import { PostTagsEditor } from '@/components/post-tags-editor';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';
import {
  toApiPostSources,
  validatePostSources,
  type PostSourceInput,
} from '@/lib/post-source';
import {
  sendPostAttachments,
  type PendingAttachment,
  type PostAttachmentsUploadResult,
} from '@/lib/post-attachment';
import { type PostTag } from '@/lib/post-tag';

type Category = {
  id: number;
  name: string;
  slug: string;
};

type PostStatus = 'draft' | 'published';

/** 保存できた投稿(以後この画面から POST /api/posts は送らない) */
type CreatedPost = {
  id: number;
  status: PostStatus;
};

/**
 * 投稿作成(POST /api/posts)の結果。
 * - saved: 投稿IDと保存した状態を応答から確認できた
 * - rejected: 保存より前に拒否されたと確認できた(未保存。入力を直して再送できる)
 * - unknown: 保存されたかどうか確認できない(再送すると重複しうる)
 */
type CreatePostResult =
  | { status: 'saved'; post: CreatedPost }
  | { status: 'rejected'; message: string }
  | { status: 'unknown' };

/**
 * 画面の状態。
 * - editing: 入力中(message は直前の確定した失敗・入力エラーの表示)
 * - submittingPost: 投稿送信中
 * - postUnknown: 投稿作成の結果不明
 * - uploading: 投稿作成成功後の添付送信中(再送を含む)
 * - attachmentsRejected: 添付が未保存と確認でき、再送を待っている
 * - attachmentsUnknown: 添付の保存結果不明
 * - done: 完了(遷移中)
 * 投稿作成成功(投稿IDと保存した状態)は、uploading 以降の post に保持する。
 */
type Phase =
  | { kind: 'editing'; message: string; isError: boolean }
  | { kind: 'submittingPost'; status: PostStatus }
  | { kind: 'postUnknown'; status: PostStatus }
  | { kind: 'uploading'; post: CreatedPost }
  | { kind: 'attachmentsRejected'; post: CreatedPost; message: string }
  | { kind: 'attachmentsUnknown'; post: CreatedPost }
  | { kind: 'done'; post: CreatedPost };

// 投稿作成が保存(PostService::store)より前に拒否される応答。
// - 401: auth:sanctum ミドルウェア
// - 422: StorePostRequest の検証(コントローラーの処理より前)
// - 413: Laravel のグローバルミドルウェア ValidatePostSize(ルーティングより前)
// StorePostRequest::authorize は常に true のため、403 は保存前の拒否として扱わない。
// Laravel が返すJSON(message を含む)を読めた場合だけ未保存とみなす。
// 5xx は保存後に失敗した可能性を否定できないため、結果不明とする。
const POST_REJECTED_STATUSES = new Set([401, 413, 422]);

const isPostStatus = (value: unknown): value is PostStatus =>
  value === 'draft' || value === 'published';

/** 下書き保存・公開に成功した後の既存の遷移先 */
const completionPath = (status: PostStatus) =>
  status === 'draft' ? '/posts/drafts' : '/posts';

const savedStatusLabel = (status: PostStatus) =>
  status === 'draft' ? '下書きとして保存' : '公開';

/**
 * 投稿を作成し、保存の結果を分類して返す。例外は投げない。
 * 成功応答でも、投稿ID(正の整数)と、要求した状態と同じ status を確認できた場合だけ saved とする。
 */
async function requestCreatePost(
  token: string,
  status: PostStatus,
  payload: Record<string, unknown>,
): Promise<CreatePostResult> {
  let res: Response;
  let data: unknown;

  try {
    res = await fetch(`${API_BASE}/api/posts`, {
      method: 'POST',
      headers: {
        ...authHeaders(token),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ ...payload, status }),
    });
    data = await res.json();
  } catch {
    // 通信失敗・JSONとして読めない応答(サーバーに届いたかどうか分からない)
    return { status: 'unknown' };
  }

  const body = (typeof data === 'object' && data !== null ? data : {}) as {
    post?: { id?: unknown; status?: unknown } | null;
    message?: unknown;
  };

  if (res.ok) {
    const id = body.post?.id;
    const savedStatus = body.post?.status;

    if (
      typeof id === 'number' &&
      Number.isInteger(id) &&
      id > 0 &&
      isPostStatus(savedStatus) &&
      savedStatus === status
    ) {
      return { status: 'saved', post: { id, status: savedStatus } };
    }

    return { status: 'unknown' };
  }

  if (POST_REJECTED_STATUSES.has(res.status) && typeof body.message === 'string') {
    return { status: 'rejected', message: body.message };
  }

  return { status: 'unknown' };
}

export default function CreatePostPage() {
  const router = useRouter();

  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryId, setCategoryId] = useState('');
  const [title, setTitle] = useState('日本のエネルギー政策について');
  const [body, setBody] = useState('ここに本文を書きます。');
  const [sources, setSources] = useState<PostSourceInput[]>([]);
  const [tags, setTags] = useState<PostTag[]>([]);
  const [attachments, setAttachments] = useState<PendingAttachment[]>([]);
  const [phase, setPhase] = useState<Phase>({
    kind: 'editing',
    message: '',
    isError: false,
  });

  // 投稿作成(POST /api/posts)の開始を同期的に判定するガード。
  // 表示用の phase は次の描画まで反映されないため、連続した操作は ref で止める。
  // - idle: 送信できる
  // - sending: 送信中。未保存と確認できた場合だけ idle に戻す
  // - closed: 投稿IDを取得した、または結果不明。この画面からは二度と送らない
  const postGuardRef = useRef<'idle' | 'sending' | 'closed'>('idle');
  // 添付送信(初回・再送)の開始を同期的に判定するガード。
  // - idle: 送信できる(投稿作成成功後の初回、または添付が未保存と確認できた後)
  // - sending: 送信中。未保存と確認できた場合だけ idle に戻す
  // - closed: 保存できた、または結果不明。この画面からは二度と送らない
  const uploadGuardRef = useRef<'idle' | 'sending' | 'closed'>('idle');

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

  /** 保存済みの投稿へ、現在の待機ファイルを一括送信する(初回・再送で共通) */
  const uploadAttachments = async (post: CreatedPost) => {
    if (uploadGuardRef.current !== 'idle') {
      return;
    }

    uploadGuardRef.current = 'sending';
    setPhase({ kind: 'uploading', post });

    let result: PostAttachmentsUploadResult;

    try {
      result = await sendPostAttachments(
        post.id,
        attachments.map((item) => item.file),
      );
    } catch {
      // sendPostAttachments は例外を投げない想定だが、送信中の表示を残さないため結果不明にする
      result = { status: 'unknown' };
    }

    if (result.status === 'saved') {
      uploadGuardRef.current = 'closed';
      setPhase({ kind: 'done', post });
      router.push(completionPath(post.status));
      return;
    }

    if (result.status === 'rejected') {
      uploadGuardRef.current = 'idle';
      setPhase({ kind: 'attachmentsRejected', post, message: result.message });
      return;
    }

    uploadGuardRef.current = 'closed';
    setPhase({ kind: 'attachmentsUnknown', post });
  };

  const createPost = async (status: PostStatus) => {
    if (postGuardRef.current !== 'idle') {
      return;
    }

    const token = getAuthToken();

    if (!token) {
      router.push('/login');
      return;
    }

    if (!categoryId) {
      setPhase({ kind: 'editing', message: 'カテゴリを選択してください。', isError: true });
      return;
    }

    const sourceValidationError = validatePostSources(sources);

    if (sourceValidationError) {
      setPhase({ kind: 'editing', message: sourceValidationError, isError: true });
      return;
    }

    postGuardRef.current = 'sending';
    setPhase({ kind: 'submittingPost', status });

    const apiSources = toApiPostSources(sources);

    let result: CreatePostResult;

    try {
      result = await requestCreatePost(token, status, {
        category_id: Number(categoryId),
        title,
        body,
        ...(apiSources.length > 0 ? { sources: apiSources } : {}),
        ...(tags.length > 0 ? { tag_ids: tags.map((tag) => tag.id) } : {}),
      });
    } catch {
      // requestCreatePost は例外を投げない想定だが、送信中の表示を残さないため結果不明にする
      result = { status: 'unknown' };
    }

    if (result.status === 'rejected') {
      postGuardRef.current = 'idle';
      setPhase({ kind: 'editing', message: result.message, isError: true });
      return;
    }

    // ここから先は、投稿IDを取得した場合も結果不明の場合も、投稿を二度と送らない
    postGuardRef.current = 'closed';

    if (result.status === 'unknown') {
      setPhase({ kind: 'postUnknown', status });
      return;
    }

    if (attachments.length === 0) {
      uploadGuardRef.current = 'closed';
      setPhase({ kind: 'done', post: result.post });
      router.push(completionPath(result.post.status));
      return;
    }

    await uploadAttachments(result.post);
  };

  // 投稿の内容(カテゴリ・タイトル・本文・出典・タグ)は、入力中だけ変更できる。
  // 投稿作成後は保存済みの内容と一致させるため固定し、結果不明では確認・コピーできるよう
  // タイトル・本文は読み取り専用にする。
  const canEditPost = phase.kind === 'editing';
  // 待機ファイルは、入力中と、添付が未保存と確認できた場合だけ変更できる
  const canEditAttachments =
    phase.kind === 'editing' || phase.kind === 'attachmentsRejected';
  const hasCreatedPost =
    phase.kind === 'uploading' ||
    phase.kind === 'attachmentsRejected' ||
    phase.kind === 'attachmentsUnknown' ||
    phase.kind === 'done';

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
              disabled={categories.length === 0 || !canEditPost}
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
              readOnly={!canEditPost}
            />
          </Label>

          <Label>
            本文
            <Textarea
              rows={12}
              value={body}
              onChange={(e) => setBody(e.target.value)}
              readOnly={!canEditPost}
            />
          </Label>

          {/* 部品を変更せずに、内部の入力・ボタンをまとめて無効にする */}
          <fieldset disabled={!canEditPost} className="grid min-w-0 gap-6">
            <PostSourcesEditor sources={sources} onChange={setSources} />

            <PostTagsEditor tags={tags} onChange={setTags} />
          </fieldset>

          <fieldset disabled={!canEditAttachments} className="min-w-0">
            <PostAttachmentsEditor
              attachments={attachments}
              onChange={setAttachments}
            />
          </fieldset>

          {!hasCreatedPost && (
            <div className="flex flex-wrap gap-3">
              <Button onClick={() => createPost('published')} disabled={!canEditPost}>
                公開する
              </Button>
              <Button
                variant="secondary"
                onClick={() => createPost('draft')}
                disabled={!canEditPost}
              >
                下書き保存
              </Button>
              <Button
                variant="ghost"
                onClick={() => router.push('/posts')}
                disabled={phase.kind === 'submittingPost'}
              >
                キャンセル
              </Button>
            </div>
          )}

          {phase.kind === 'editing' && phase.message && (
            <Alert message={phase.message} variant={phase.isError ? 'error' : 'info'} />
          )}

          {phase.kind === 'submittingPost' && (
            <Alert
              message={phase.status === 'draft' ? '下書き保存中...' : '公開中...'}
              variant="info"
            />
          )}

          {phase.kind === 'postUnknown' && (
            <div className="grid gap-3">
              <Alert
                message="保存結果を確認できませんでした。投稿が保存されている可能性があるため、重複を避けるためにこの画面からは再送しません。下書き一覧または投稿一覧で保存されたかを確認してください。入力した内容は、この画面で確認・コピーできます。"
                variant="error"
              />
              <div className="flex flex-wrap gap-4 text-sm">
                <ConfirmLink href="/posts/drafts">下書き一覧で確認する</ConfirmLink>
                <ConfirmLink href="/posts">投稿一覧で確認する</ConfirmLink>
              </div>
            </div>
          )}

          {phase.kind === 'uploading' && (
            <Alert
              message={`投稿を${savedStatusLabel(phase.post.status)}しました。添付ファイルを送信中...`}
              variant="info"
            />
          )}

          {phase.kind === 'attachmentsRejected' && (
            <div className="grid gap-3">
              <Alert
                message={`投稿は${savedStatusLabel(phase.post.status)}されました。添付ファイルは保存されていません（${phase.message}）。添付ファイルを選び直して再アップロードするか、添付せずに進んでください。投稿の内容は変更できません。`}
                variant="error"
              />
              <div className="flex flex-wrap gap-3">
                <Button
                  onClick={() => uploadAttachments(phase.post)}
                  disabled={attachments.length === 0}
                >
                  添付ファイルを再アップロード
                </Button>
                <Button
                  variant="secondary"
                  onClick={() => router.push(completionPath(phase.post.status))}
                >
                  添付せずに進む
                </Button>
              </div>
              <div className="text-sm">
                <ConfirmLink href={`/posts/${phase.post.id}`}>
                  保存済みの投稿を確認する
                </ConfirmLink>
              </div>
            </div>
          )}

          {phase.kind === 'attachmentsUnknown' && (
            <div className="grid gap-3">
              <Alert
                message={`投稿は${savedStatusLabel(phase.post.status)}されました。添付ファイルが保存されたかを確認できませんでした。重複を避けるため、この画面からは再アップロードしません。保存済みの投稿で添付ファイルを確認してください。`}
                variant="error"
              />
              <div className="flex flex-wrap gap-3">
                <Button
                  variant="secondary"
                  onClick={() => router.push(completionPath(phase.post.status))}
                >
                  完了して進む
                </Button>
              </div>
              <div className="text-sm">
                <ConfirmLink href={`/posts/${phase.post.id}`}>
                  保存済みの投稿を確認する
                </ConfirmLink>
              </div>
            </div>
          )}
        </div>
      </Card>
    </PageShell>
  );
}

/** 作成画面の状態を保ったまま確認できるよう、新しいタブで開くリンク */
function ConfirmLink({ href, children }: { href: string; children: string }) {
  return (
    <Link
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="font-medium text-primary hover:underline"
    >
      {children}（新しいタブ）
    </Link>
  );
}
