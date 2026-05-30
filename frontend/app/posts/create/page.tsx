'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

type PostResponse = {
  message: string;
  post?: {
    id: number;
    title: string;
    body: string;
    status: string;
  };
};

export default function CreatePostPage() {
  const router = useRouter();

  const [title, setTitle] = useState('日本のエネルギー政策について');
  const [body, setBody] = useState('ここに本文を書きます。');
  const [message, setMessage] = useState('');

  const createPost = async () => {
    const token = localStorage.getItem('openpersona_token');

    if (!token) {
      router.push('/login');
      return;
    }

    setMessage('投稿中...');

    const res = await fetch('http://localhost:8000/api/posts', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        category_id: 1,
        title,
        body,
        status: 'published',
      }),
    });

    const data: PostResponse = await res.json();

    if (!res.ok) {
      console.log(data);
      setMessage(data.message ?? '投稿に失敗しました。');
      return;
    }

    router.push('/posts');
  };

  return (
    <main style={{ padding: 40 }}>
      <h1>投稿作成</h1>

      <div style={{ display: 'grid', gap: 12, maxWidth: 720 }}>
        <label>
          タイトル
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
          />
        </label>

        <label>
          本文
          <textarea
            rows={12}
            value={body}
            onChange={(e) => setBody(e.target.value)}
          />
        </label>

        <button onClick={createPost}>投稿する</button>

        {message && <p>{message}</p>}
      </div>
    </main>
  );
}