import { API_BASE, getAuthToken } from '@/lib/api';

export type PostAttachment = {
  id: number;
  file_name: string;
  file_type: 'image' | 'pdf';
  file_size: number;
  url: string;
  created_at?: string;
};

export type PendingAttachment = {
  file: File;
  previewUrl: string | null;
};

const IMAGE_ACCEPT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

export const ATTACHMENT_ACCEPT =
  '.jpg,.jpeg,.png,.gif,.webp,.pdf,image/jpeg,image/png,image/gif,image/webp,application/pdf';

export function isImageFile(file: File): boolean {
  return (
    IMAGE_ACCEPT.includes(file.type) ||
    /\.(jpe?g|png|gif|webp)$/i.test(file.name)
  );
}

export function isPdfFile(file: File): boolean {
  return file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
}

export function isAllowedAttachmentFile(file: File): boolean {
  return isImageFile(file) || isPdfFile(file);
}

export function createPendingAttachment(file: File): PendingAttachment {
  return {
    file,
    previewUrl: isImageFile(file) ? URL.createObjectURL(file) : null,
  };
}

export function revokePendingAttachmentPreview(item: PendingAttachment): void {
  if (item.previewUrl) {
    URL.revokeObjectURL(item.previewUrl);
  }
}

/**
 * 添付ファイルの一括送信(POST /api/posts/{post}/attachments)の結果。
 * - saved: 送信した全ファイルが保存されたと応答から確認できた
 * - rejected: 保存より前に拒否されたと確認できた(未保存)
 * - unknown: 保存されたかどうか確認できない(再送すると重複しうる)
 */
export type PostAttachmentsUploadResult =
  | { status: 'saved'; attachments: PostAttachment[] }
  | { status: 'rejected'; message: string }
  | { status: 'unknown' };

// 添付の追加が保存(PostAttachmentService::store)より前に拒否される応答。
// - 401: auth:sanctum ミドルウェア
// - 404: ルートモデル結合で投稿が見つからない
// - 403: PostAttachmentController::store 冒頭の Gate::authorize('attach')
// - 422: 同じく store の $request->validate(保存処理の呼び出しより前)
// - 413: Laravel のグローバルミドルウェア ValidatePostSize(ルーティングより前)
// Laravel が返すJSON(message を含む)を読めた場合だけ未保存とみなす。
// 5xx は、既知の失敗(後片付け・ロールバック済み)以外の経路を区別できないため、結果不明とする。
const ATTACHMENT_REJECTED_STATUSES = new Set([401, 403, 404, 413, 422]);

const isSavedAttachment = (value: unknown): value is PostAttachment =>
  typeof value === 'object' &&
  value !== null &&
  Number.isInteger((value as { id?: unknown }).id) &&
  ((value as { id: number }).id > 0);

/**
 * files を1回のリクエストでまとめて送信し、保存の結果を分類して返す。例外は投げない。
 * 成功応答でも、attachments の件数が送信数と一致し、各要素に投稿添付のIDがある場合だけ
 * saved とする。
 */
export async function sendPostAttachments(
  postId: number,
  files: File[],
): Promise<PostAttachmentsUploadResult> {
  const token = getAuthToken();

  if (!token) {
    // 送信していないため未保存
    return { status: 'rejected', message: 'ログインが必要です。' };
  }

  const formData = new FormData();
  files.forEach((file) => {
    formData.append('files[]', file);
  });

  let res: Response;
  let data: unknown;

  try {
    res = await fetch(`${API_BASE}/api/posts/${postId}/attachments`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: formData,
    });
    data = await res.json();
  } catch {
    // 通信失敗・JSONとして読めない応答(サーバーに届いたかどうか分からない)
    return { status: 'unknown' };
  }

  const body = (typeof data === 'object' && data !== null ? data : {}) as {
    attachments?: unknown;
    message?: unknown;
  };

  if (res.ok) {
    const { attachments } = body;

    if (
      Array.isArray(attachments) &&
      attachments.length === files.length &&
      attachments.every(isSavedAttachment)
    ) {
      return { status: 'saved', attachments };
    }

    return { status: 'unknown' };
  }

  if (ATTACHMENT_REJECTED_STATUSES.has(res.status) && typeof body.message === 'string') {
    return { status: 'rejected', message: body.message };
  }

  return { status: 'unknown' };
}

export async function uploadPostAttachments(
  postId: number | string,
  files: File[],
): Promise<PostAttachment[]> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const formData = new FormData();
  files.forEach((file) => {
    formData.append('files[]', file);
  });

  const res = await fetch(`${API_BASE}/api/posts/${postId}/attachments`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: formData,
  });

  const data: { attachments?: PostAttachment[]; message?: string } = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? '添付ファイルのアップロードに失敗しました。');
  }

  return data.attachments ?? [];
}
