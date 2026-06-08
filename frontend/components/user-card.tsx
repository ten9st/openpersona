import Link from 'next/link';
import { AuthorLink } from '@/components/author-link';
import { Card } from '@/components/ui/card';
import { formatTrustScore, type PostAuthor } from '@/lib/post-author';

type UserCardProps = {
  user: PostAuthor;
};

export function UserCard({ user }: UserCardProps) {
  return (
    <Link href={`/users/${user.id}`}>
      <Card className="transition-colors hover:border-primary/30">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <AuthorLink user={user} className="font-medium text-foreground" />
          {user.trust_score && (
            <span className="text-xs text-muted">
              透明性スコア {formatTrustScore(user.trust_score)}
            </span>
          )}
        </div>
        {user.region && (
          <p className="mt-2 text-sm text-muted">{user.region}</p>
        )}
      </Card>
    </Link>
  );
}
