import Link from 'next/link';
import { formatAuthorSummary, type PostAuthor } from '@/lib/post-author';

type AuthorLinkProps = {
  user: PostAuthor;
  className?: string;
};

export function AuthorLink({ user, className = '' }: AuthorLinkProps) {
  return (
    <Link
      href={`/users/${user.id}`}
      className={`text-muted transition-colors hover:text-primary hover:underline ${className}`}
      onClick={(event) => event.stopPropagation()}
    >
      {formatAuthorSummary(user)}
    </Link>
  );
}
