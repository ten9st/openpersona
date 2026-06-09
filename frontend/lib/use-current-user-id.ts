'use client';

import { useEffect, useState } from 'react';
import { API_BASE, authHeaders, getAuthToken } from '@/lib/api';

export function useCurrentUserId(): number | null {
  const [userId, setUserId] = useState<number | null>(null);

  useEffect(() => {
    const token = getAuthToken();

    if (!token) {
      setUserId(null);
      return;
    }

    fetch(`${API_BASE}/api/me`, {
      headers: authHeaders(token),
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.user?.id != null) {
          setUserId(data.user.id);
        }
      })
      .catch(() => setUserId(null));
  }, []);

  return userId;
}
