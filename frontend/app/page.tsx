import Link from 'next/link';

export default function Home() {
  return (
    <main style={{ padding: 40 }}>
      <h1>OpenPersona</h1>

      <p>信頼できる情報ソースを公開するSNS</p>

      <div style={{ display: 'grid', gap: 12, maxWidth: 240 }}>
        <Link href="/login">ログイン</Link>
        <Link href="/register">新規登録</Link>
        <Link href="/posts">投稿一覧</Link>
        <Link href="/profile">プロフィール編集</Link>
      </div>
    </main>
  );
}