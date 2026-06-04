export const POST_SOURCE_TYPES = [
  'url',
  'book',
  'paper',
  'government_document',
  'other',
] as const;

export type PostSourceType = (typeof POST_SOURCE_TYPES)[number];

export type PostSourceInput = {
  source_type: PostSourceType;
  title: string;
  url: string;
  note: string;
};

export type PostSource = PostSourceInput & {
  id: number;
};

export type PostSourcePayload = {
  source_type: PostSourceType;
  title: string | null;
  url: string | null;
  note: string | null;
};

export const POST_SOURCE_TYPE_LABELS: Record<PostSourceType, string> = {
  url: 'Web（URL）',
  book: '書籍',
  paper: '論文',
  government_document: '公的資料',
  other: 'その他',
};

export function createEmptyPostSource(): PostSourceInput {
  return {
    source_type: 'url',
    title: '',
    url: '',
    note: '',
  };
}

export function postSourceHasContent(source: PostSourceInput): boolean {
  return (
    source.title.trim() !== '' ||
    source.url.trim() !== '' ||
    source.note.trim() !== ''
  );
}

export function toApiPostSources(sources: PostSourceInput[]): PostSourcePayload[] {
  return sources.filter(postSourceHasContent).map((source) => ({
    source_type: source.source_type,
    title: source.title.trim() || null,
    url: source.url.trim() || null,
    note: source.note.trim() || null,
  }));
}

export function fromApiPostSource(source: {
  id?: number;
  source_type: string;
  title?: string | null;
  url?: string | null;
  note?: string | null;
}): PostSourceInput {
  const sourceType = POST_SOURCE_TYPES.includes(source.source_type as PostSourceType)
    ? (source.source_type as PostSourceType)
    : 'url';

  return {
    source_type: sourceType,
    title: source.title ?? '',
    url: source.url ?? '',
    note: source.note ?? '',
  };
}
