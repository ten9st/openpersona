import Link from 'next/link';
import { PageHeader, PageShell } from '@/components/page-shell';
import { Card } from '@/components/ui/card';

const links = [
  { href: '/login', label: 'ログイン', description: 'アカウントにサインイン' },
  { href: '/register', label: '新規登録', description: '新しいアカウントを作成' },
  { href: '/posts', label: '投稿一覧', description: 'みんなの投稿を読む' },
  { href: '/bookmarks', label: '付箋一覧', description: 'あとで読みたい投稿' },
  { href: '/timeline', label: 'タイムライン', description: 'フォロー中ユーザーの投稿' },
  {
    href: '/profile',
    label: 'プロフィール編集',
    description: '公開プロフィールを設定',
  },
];

export default function Home() {
  return (
    <PageShell maxWidth="md">
      <PageHeader
        title="OpenPersona"
        description="信頼できる情報ソースを公開するSNS"
      />

      <div className="grid gap-3">
        {links.map((link) => (
          <Link key={link.href} href={link.href} className="group">
            <Card className="transition-colors group-hover:border-primary/40 group-hover:bg-accent/50">
              <p className="font-medium text-foreground group-hover:text-primary">
                {link.label}
              </p>
              <p className="mt-1 text-sm text-muted">{link.description}</p>
            </Card>
          </Link>
        ))}
      </div>
    </PageShell>
  );
}
