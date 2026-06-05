import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

type BookmarkToggleResponse = {
  message: string;
  bookmark_count: number;
  is_bookmarked: boolean;
};

export async function addBookmark(postId: number | string): Promise<BookmarkToggleResponse> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const res = await fetch(`${API_BASE}/api/posts/${postId}/bookmark`, {
    method: 'POST',
    headers: authHeaders(token),
  });

  const data: BookmarkToggleResponse & { message?: string } = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? '付箋の追加に失敗しました。');
  }

  return data;
}

export async function removeBookmark(postId: number | string): Promise<BookmarkToggleResponse> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const res = await fetch(`${API_BASE}/api/posts/${postId}/bookmark`, {
    method: 'DELETE',
    headers: authHeaders(token),
  });

  const data: BookmarkToggleResponse & { message?: string } = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? '付箋の解除に失敗しました。');
  }

  return data;
}
