'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { NavLink } from '@/components/nav-links';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { getAuthToken } from '@/lib/api';

const features = [
  {
    title: '実名・本人確認',
    description:
      '姓・年齢・都道府県を公開し、本人確認済みユーザーにはバッジを表示。匿名ではなく、誰が発信しているかが分かります。',
  },
  {
    title: '透明性スコア',
    description:
      'プロフィールの充実度や情報源の明示などをもとに信頼スコアを可視化。発信者の透明性を数値で比較できます。',
  },
  {
    title: '参考文献の明示',
    description:
      '投稿ごとに URL・書籍・論文などの参考文献を添付。根拠のある情報発信を後押しします。',
  },
];

export default function Home() {
  const router = useRouter();
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (getAuthToken()) {
      router.replace('/posts');
      return;
    }

    setReady(true);
  }, [router]);

  if (!ready) {
    return (
      <div className="min-h-full bg-background">
        <div className="mx-auto max-w-5xl px-6 py-10">
          <p className="text-sm text-muted">読み込み中...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-full bg-background">
      <header className="border-b border-border bg-card/80 backdrop-blur-sm">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
          <Link
            href="/"
            className="text-lg font-semibold tracking-tight text-foreground transition-colors hover:text-primary"
          >
            OpenPersona
          </Link>
          <nav className="flex items-center gap-3">
            <NavLink href="/login">ログイン</NavLink>
            <NavLink href="/register" variant="primary">
              新規登録
            </NavLink>
          </nav>
        </div>
      </header>

      <main>
        <section className="border-b border-border bg-gradient-to-b from-accent/60 to-background">
          <div className="mx-auto max-w-5xl px-6 py-16 sm:py-24">
            <div className="mx-auto max-w-2xl text-center">
              <p className="mb-4 text-sm font-medium text-primary">
                信頼できる情報ソースを公開するSNS
              </p>
              <h1 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl lg:text-5xl">
                発信者の顔が見えるSNS
              </h1>
              <p className="mt-6 text-base leading-relaxed text-muted sm:text-lg">
                実名・職歴・透明性スコアで、誰が発信しているかを可視化します
              </p>

              <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <Button
                  type="button"
                  className="w-full px-8 py-3 text-base sm:w-auto"
                  onClick={() => router.push('/posts')}
                >
                  投稿一覧を見る
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  className="w-full px-8 py-3 text-base sm:w-auto"
                  onClick={() => router.push('/register')}
                >
                  新規登録
                </Button>
              </div>
            </div>
          </div>
        </section>

        <section className="mx-auto max-w-5xl px-6 py-16 sm:py-20">
          <div className="mb-10 text-center">
            <h2 className="text-2xl font-bold tracking-tight text-foreground">
              OpenPersona の特徴
            </h2>
            <p className="mt-3 text-sm text-muted sm:text-base">
              匿名性ではなく透明性を重視した、新しい情報発信の形
            </p>
          </div>

          <div className="grid gap-6 sm:grid-cols-3">
            {features.map((feature) => (
              <Card key={feature.title} className="h-full">
                <h3 className="text-lg font-semibold text-foreground">
                  {feature.title}
                </h3>
                <p className="mt-3 text-sm leading-relaxed text-muted">
                  {feature.description}
                </p>
              </Card>
            ))}
          </div>
        </section>
      </main>
    </div>
  );
}
