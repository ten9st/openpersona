export const API_BASE = 'http://localhost:8000';

export function getAuthToken(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }

  return localStorage.getItem('openpersona_token');
}

export function authHeaders(token: string): HeadersInit {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };
}

export async function logout(): Promise<void> {
  const token = getAuthToken();

  if (token) {
    await fetch(`${API_BASE}/api/logout`, {
      method: 'POST',
      headers: authHeaders(token),
    });
  }

  localStorage.removeItem('openpersona_token');
}

export function getCsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}

export async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${API_BASE}/sanctum/csrf-cookie`, {
    credentials: 'include',
  });
}
