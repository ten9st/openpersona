'use client';

import { useCallback, useEffect, useState } from 'react';

const API_BASE = 'http://localhost:8000/api';

type Category = {
  id: number;
  name: string;
  slug: string;
};

type Author = {
  id: number;
  last_name: string;
  first_name: string;
};

type Post = {
  id: number;
  title: string;
  body: string;
  status: string;
  published_at: string | null;
  view_count: number;
  bookmark_count: number;
  user: Author;
  category: Category;
};

type PostListResponse = {
  posts: Post[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

type PostCreateResponse = {
  message: string;
  post?: Post;
};

export default function Home() {
  const [title, setTitle] = useState('日本のエネルギー政策について');
  const [body, setBody] = useState('ここに本文を書きます。');
  const [message, setMessage] = useState('');
  const [posts, setPosts] = useState<Post[]>([]);
  const [listError, setListError] = useState('');
  const [isLoadingList, setIsLoadingList] = useState(true);

  const fetchPosts = useCallback(async () => {
    setIsLoadingList(true);
    setListError('');

    const res = await fetch(`${API_BASE}/posts`, {
      headers: { Accept: 'application/json' },
    });

    const data: PostListResponse = await res.json();

    if (!res.ok) {
      setListError('投稿一覧の取得に失敗しました。');
      setPosts([]);
      setIsLoadingList(false);
      return;
    }

    setPosts(data.posts);
    setIsLoadingList(false);
  }, []);

  useEffect(() => {
    fetchPosts();
  }, [fetchPosts]);

  const createPost = async () => {
    const token = localStorage.getItem('openpersona_token');

    if (!token) {
      setMessage('先にログインしてください。');
      return;
    }

    setMessage('投稿中...');

    const res = await fetch(`${API_BASE}/posts`, {
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

    const data: PostCreateResponse = await res.json();

    if (!res.ok) {
      setMessage(data.message ?? '投稿に失敗しました。');
      return;
    }

    setMessage(data.message);
    await fetchPosts();
  };

  return (
    <main style={{ padding: 40 }}>
      <h1>OpenPersona</h1>

      <section style={{ marginBottom: 48 }}>
        <h2>投稿作成</h2>

        <div style={{ display: 'grid', gap: 12, maxWidth: 600 }}>
          <input
            placeholder="タイトル"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
          />

          <textarea
            placeholder="本文"
            value={body}
            rows={10}
            onChange={(e) => setBody(e.target.value)}
          />

          <button onClick={createPost}>投稿する</button>

          {message && <p>{message}</p>}
        </div>
      </section>

      <section>
        <h2>投稿一覧</h2>

        {isLoadingList && <p>読み込み中...</p>}
        {listError && <p>{listError}</p>}

        {!isLoadingList && !listError && posts.length === 0 && (
          <p>公開中の投稿はまだありません。</p>
        )}

        <ul style={{ display: 'grid', gap: 16, maxWidth: 720, padding: 0, listStyle: 'none' }}>
          {posts.map((post) => (
            <li
              key={post.id}
              style={{
                border: '1px solid #ddd',
                borderRadius: 8,
                padding: 16,
              }}
            >
              <p style={{ margin: 0, color: '#666', fontSize: 14 }}>
                {post.category.name} ・ {post.user.last_name} {post.user.first_name}
              </p>
              <h3 style={{ margin: '8px 0' }}>{post.title}</h3>
              <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{post.body}</p>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}
