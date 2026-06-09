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
const PDF_ACCEPT = ['application/pdf'];

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
