import {
  POST_SOURCE_TYPE_LABELS,
  type PostSource,
  type PostSourceType,
} from '@/lib/post-source';

type PostSourcesListProps = {
  sources: PostSource[];
};

export function PostSourcesList({ sources }: PostSourcesListProps) {
  if (sources.length === 0) {
    return null;
  }

  return (
    <section className="mt-8">
      <h2 className="mb-4 text-lg font-semibold text-foreground">
        参考文献・情報ソース ({sources.length})
      </h2>
      <ul className="grid gap-3">
        {sources.map((source) => (
          <li
            key={source.id}
            className="rounded-lg border border-border bg-card px-4 py-3 text-sm"
          >
            <p className="font-medium text-foreground">
              {source.title?.trim() ||
                POST_SOURCE_TYPE_LABELS[source.source_type as PostSourceType]}
            </p>
            <p className="mt-1 text-xs text-muted">
              {POST_SOURCE_TYPE_LABELS[source.source_type as PostSourceType]}
            </p>
            {source.url?.trim() && (
              <a
                href={source.url}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-2 block break-all text-primary hover:underline"
              >
                {source.url}
              </a>
            )}
            {source.note?.trim() && (
              <p className="mt-2 whitespace-pre-wrap text-foreground/80">{source.note}</p>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}
