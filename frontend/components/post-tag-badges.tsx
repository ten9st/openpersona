import Link from 'next/link';
import { type PostTag } from '@/lib/post-tag';

type PostTagBadgesProps = {
  tags: PostTag[];
  className?: string;
};

export function PostTagBadges({ tags, className = '' }: PostTagBadgesProps) {
  if (tags.length === 0) {
    return null;
  }

  return (
    <div className={`flex flex-wrap gap-2 ${className}`}>
      {tags.map((tag) => (
        <Link
          key={tag.id}
          href={`/posts?tag=${encodeURIComponent(tag.slug)}`}
          className="inline-flex items-center rounded-full border border-border bg-muted/40 px-2.5 py-0.5 text-xs font-medium text-foreground transition-colors hover:border-primary/40 hover:bg-accent hover:text-primary"
        >
          #{tag.name}
        </Link>
      ))}
    </div>
  );
}
