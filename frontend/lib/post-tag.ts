import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

export type PostTag = {
  id: number;
  name: string;
  slug: string;
};

export async function searchTags(keyword: string): Promise<PostTag[]> {
  const params = new URLSearchParams();
  if (keyword.trim()) {
    params.set('search', keyword.trim());
  }

  const query = params.toString();
  const res = await fetch(
    `${API_BASE}/api/tags${query ? `?${query}` : ''}`,
    {
      headers: { Accept: 'application/json' },
    },
  );

  const data = await res.json();

  if (!res.ok) {
    throw new Error('タグの取得に失敗しました。');
  }

  return data.tags ?? [];
}

export async function createTag(name: string): Promise<PostTag> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const res = await fetch(`${API_BASE}/api/tags`, {
    method: 'POST',
    headers: {
      ...authHeaders(token),
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ name: name.trim() }),
  });

  const data = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? 'タグの作成に失敗しました。');
  }

  return data.tag;
}
