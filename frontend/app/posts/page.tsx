'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';

type Post = {
  id: number;
  title: string;
  body: string;
  status: string;
  view_count: number;
  bookmark_count: number;
  published_at: string | null;

  user: {
    id: number;
    last_name: string;
    first_name: string;
  };

  category: {
    id: number;
    name: string;
  };
};

export default function PostsPage() {
  const router = useRouter();

  const [posts, setPosts] = useState<Post[]>([]);
  const [message, setMessage] = useState('');

  const logout = () => {
    localStorage.removeItem('openpersona_token');
    router.push('/login');
  };

  const fetchPosts = async () => {
    setMessage('読み込み中...');

    const res = await fetch('http://localhost:8000/api/posts', {
      headers: {
        Accept: 'application/json',
      },
    });

    const data = await res.json();

    if (!res.ok) {
      setMessage('投稿一覧取得に失敗しました。');
      return;
    }

    setPosts(data.posts);
    setMessage('');
  };

  useEffect(() => {
    fetchPosts();
  }, []);

  return (
    <main style={{ padding: 40 }}>
      <h1>OpenPersona 投稿一覧</h1>

      <div
        style={{
          display: 'flex',
          gap: 12,
          marginBottom: 24,
        }}
      >
        <Link href="/posts/create">投稿する</Link>
        <Link href="/profile">プロフィール編集</Link>

        <button onClick={logout}>ログアウト</button>
      </div>

      {message && <p>{message}</p>}

      <div style={{ display: 'grid', gap: 16 }}>
        {posts.map((post) => (
          <article
            key={post.id}
            style={{
              border: '1px solid #ddd',
              borderRadius: 8,
              padding: 16,
              maxWidth: 720,
            }}
          >
            <p>
              {post.category.name} /
              {' '}
              {post.user.last_name}
              {post.user.first_name}
            </p>

            <h2>{post.title}</h2>

            <p>{post.body}</p>

            <p>
              閲覧数:
              {' '}
              {post.view_count}
            </p>

            <p>
              付箋数:
              {' '}
              {post.bookmark_count}
            </p>
          </article>
        ))}
      </div>
    </main>
  );
}