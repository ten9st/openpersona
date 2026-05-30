'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Alert, PageHeader, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';

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
  const [isError, setIsError] = useState(false);

  const createPost = async () => {
    const token = localStorage.getItem('openpersona_token');

    if (!token) {
      router.push('/login');
      return;
    }

    setMessage('投稿中...');
    setIsError(false);

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
      setIsError(true);
      return;
    }

    router.push('/posts');
  };

  return (
    <PageShell maxWidth="lg">
      <PageHeader
        title="投稿作成"
        description="信頼できる情報を共有しましょう"
      />

      <Card>
        <div className="grid gap-6">
          <Label>
            タイトル
            <Input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
          </Label>

          <Label>
            本文
            <Textarea
              rows={12}
              value={body}
              onChange={(e) => setBody(e.target.value)}
            />
          </Label>

          <div className="flex flex-wrap gap-3">
            <Button onClick={createPost}>投稿する</Button>
            <Button variant="secondary" onClick={() => router.push('/posts')}>
              キャンセル
            </Button>
          </div>

          {message && (
            <Alert message={message} variant={isError ? 'error' : 'info'} />
          )}
        </div>
      </Card>
    </PageShell>
  );
}
