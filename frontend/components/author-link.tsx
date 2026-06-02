import Link from 'next/link';
import { IdentityVerifiedBadge } from '@/components/identity-verified-badge';
import { formatAuthorSummary, type PostAuthor } from '@/lib/post-author';

type AuthorLinkProps = {
  user: PostAuthor;
  className?: string;
};

export function AuthorLink({ user, className = '' }: AuthorLinkProps) {
  return (
    <span className={`inline-flex flex-wrap items-center gap-2 ${className}`}>
      <Link
        href={`/users/${user.id}`}
        className="text-muted transition-colors hover:text-primary hover:underline"
        onClick={(event) => event.stopPropagation()}
      >
        {formatAuthorSummary(user)}
      </Link>
      <IdentityVerifiedBadge verified={user.identity_verified} />
    </span>
  );
}
