import { type PostAttachment } from '@/lib/post-attachment';

type PostAttachmentsListProps = {
  attachments: PostAttachment[];
};

export function PostAttachmentsList({ attachments }: PostAttachmentsListProps) {
  if (attachments.length === 0) {
    return null;
  }

  return (
    <section className="mt-8">
      <h2 className="mb-4 text-lg font-semibold text-foreground">
        添付ファイル ({attachments.length})
      </h2>
      <ul className="grid gap-4">
        {attachments.map((attachment) => (
          <li
            key={attachment.id}
            className="rounded-lg border border-border bg-card p-4"
          >
            {attachment.file_type === 'image' ? (
              <a
                href={attachment.url}
                target="_blank"
                rel="noopener noreferrer"
                className="block"
              >
                <img
                  src={attachment.url}
                  alt={attachment.file_name}
                  className="max-h-96 w-full rounded-md border border-border object-contain"
                />
              </a>
            ) : (
              <a
                href={attachment.url}
                target="_blank"
                rel="noopener noreferrer"
                download={attachment.file_name}
                className="text-sm font-medium text-primary hover:underline"
              >
                {attachment.file_name}
              </a>
            )}
            <p className="mt-2 text-xs text-muted">
              {attachment.file_name}
              {attachment.file_size > 0 &&
                ` · ${Math.max(1, Math.round(attachment.file_size / 1024))} KB`}
            </p>
          </li>
        ))}
      </ul>
    </section>
  );
}
