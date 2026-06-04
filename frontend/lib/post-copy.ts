import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

type CopyPostResponse = {
  message: string;
  copied_from_post_id: number;
  post: {
    id: number;
  };
};

export async function copyPostAsCorrection(postId: number | string): Promise<CopyPostResponse> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const res = await fetch(`${API_BASE}/api/posts/${postId}/copy`, {
    method: 'POST',
    headers: authHeaders(token),
  });

  const data: CopyPostResponse & { message?: string } = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? 'コピーに失敗しました。');
  }

  return data;
}
