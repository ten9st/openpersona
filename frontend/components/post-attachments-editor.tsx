'use client';

import { useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  ATTACHMENT_ACCEPT,
  createPendingAttachment,
  isAllowedAttachmentFile,
  revokePendingAttachmentPreview,
  type PendingAttachment,
} from '@/lib/post-attachment';

type PostAttachmentsEditorProps = {
  attachments: PendingAttachment[];
  onChange: (attachments: PendingAttachment[]) => void;
};

export function PostAttachmentsEditor({
  attachments,
  onChange,
}: PostAttachmentsEditorProps) {
  const inputRef = useRef<HTMLInputElement>(null);

  const addFiles = (fileList: FileList | null) => {
    if (!fileList) {
      return;
    }

    const next = [...attachments];

    Array.from(fileList).forEach((file) => {
      if (!isAllowedAttachmentFile(file)) {
        return;
      }

      next.push(createPendingAttachment(file));
    });

    onChange(next);
  };

  const removeAttachment = (index: number) => {
    const target = attachments[index];

    if (target) {
      revokePendingAttachmentPreview(target);
    }

    onChange(attachments.filter((_, i) => i !== index));
  };

  return (
    <div className="grid gap-4">
      <div>
        <p className="text-sm font-medium text-foreground">添付ファイル</p>
        <p className="mt-1 text-xs text-muted">
          画像（jpg / png / gif / webp・最大10MB）または PDF（最大20MB）を複数選択できます。
        </p>
      </div>

      <input
        ref={inputRef}
        type="file"
        accept={ATTACHMENT_ACCEPT}
        multiple
        className="hidden"
        onChange={(e) => {
          addFiles(e.target.files);
          e.target.value = '';
        }}
      />

      <Button
        type="button"
        variant="secondary"
        onClick={() => inputRef.current?.click()}
      >
        ファイルを選択
      </Button>

      {attachments.length > 0 && (
        <ul className="grid gap-3">
          {attachments.map((attachment, index) => (
            <li
              key={`${attachment.file.name}-${index}`}
              className="rounded-lg border border-border bg-background/50 p-4"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  {attachment.previewUrl ? (
                    <img
                      src={attachment.previewUrl}
                      alt={attachment.file.name}
                      className="max-h-40 rounded-md border border-border object-contain"
                    />
                  ) : (
                    <p className="text-sm font-medium text-foreground">
                      {attachment.file.name}
                    </p>
                  )}
                  <p className="mt-2 text-xs text-muted">
                    {attachment.file.name}（
                    {Math.max(1, Math.round(attachment.file.size / 1024))} KB）
                  </p>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  className="px-2 py-1 text-xs"
                  onClick={() => removeAttachment(index)}
                >
                  削除
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
