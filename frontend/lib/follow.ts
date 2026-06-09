import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

type FollowToggleResponse = {
  message: string;
  followers_count: number;
  following_count: number;
  is_following: boolean;
};

export async function followUser(userId: number | string): Promise<FollowToggleResponse> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const res = await fetch(`${API_BASE}/api/users/${userId}/follow`, {
    method: 'POST',
    headers: authHeaders(token),
  });

  const data: FollowToggleResponse & { message?: string } = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? 'フォローに失敗しました。');
  }

  return data;
}

export async function unfollowUser(userId: number | string): Promise<FollowToggleResponse> {
  const token = getAuthToken();

  if (!token) {
    throw new Error('ログインが必要です。');
  }

  const res = await fetch(`${API_BASE}/api/users/${userId}/follow`, {
    method: 'DELETE',
    headers: authHeaders(token),
  });

  const data: FollowToggleResponse & { message?: string } = await res.json();

  if (!res.ok) {
    throw new Error(data.message ?? 'フォロー解除に失敗しました。');
  }

  return data;
}
