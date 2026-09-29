'use client';

import { useEffect, useState } from 'react';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

export function useCurrentUserId(): number | null {
  const [userId, setUserId] = useState<number | null>(null);

  useEffect(() => {
    const token = getAuthToken();

    // トークンが無ければ取得せず、状態は変更しない(初期値は null)。
    // 同じマウントのまま effect が再実行され、その間にトークンが消えた場合は、
    // それまでの値が残る。ログイン状態の変化に追従する仕組みではない。
    if (!token) {
      return;
    }

    // cleanup(アンマウント、Strict Mode での再実行など)の後に届いた応答・失敗は
    // 状態に反映しない。通信自体は中断しない。
    let ignore = false;

    fetch(`${API_BASE}/api/me`, {
      headers: authHeaders(token),
    })
      .then((res) => res.json())
      .then((data) => {
        if (!ignore && data.user?.id != null) {
          setUserId(data.user.id);
        }
      })
      .catch(() => {
        if (!ignore) {
          setUserId(null);
        }
      });

    return () => {
      ignore = true;
    };
  }, []);

  return userId;
}
