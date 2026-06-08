'use client';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
  createEmptyPostSource,
  POST_SOURCE_TYPE_LABELS,
  POST_SOURCE_TYPES,
  type PostSourceInput,
} from '@/lib/post-source';

type PostSourcesEditorProps = {
  sources: PostSourceInput[];
  onChange: (sources: PostSourceInput[]) => void;
};

export function PostSourcesEditor({ sources, onChange }: PostSourcesEditorProps) {
  const updateSource = (index: number, patch: Partial<PostSourceInput>) => {
    onChange(
      sources.map((source, i) => (i === index ? { ...source, ...patch } : source)),
    );
  };

  const removeSource = (index: number) => {
    onChange(sources.filter((_, i) => i !== index));
  };

  const addSource = () => {
    onChange([...sources, createEmptyPostSource()]);
  };

  return (
    <div className="grid gap-4">
      <div>
        <p className="text-sm font-medium text-foreground">参考文献・情報ソース</p>
        <p className="mt-1 text-xs text-muted">
          根拠となる URL・書籍・論文などを登録すると、透明性スコアの算出に反映されます。
        </p>
      </div>

      {sources.length === 0 ? (
        <p className="text-sm text-muted">まだソースは登録されていません。</p>
      ) : (
        <ul className="grid gap-4">
          {sources.map((source, index) => (
            <li
              key={index}
              className="rounded-lg border border-border bg-background/50 p-4"
            >
              <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-foreground">
                  ソース {index + 1}
                </span>
                <Button
                  type="button"
                  variant="ghost"
                  className="px-2 py-1 text-xs"
                  onClick={() => removeSource(index)}
                >
                  削除
                </Button>
              </div>

              <div className="grid gap-4">
                <Label>
                  種別
                  <Select
                    value={source.source_type}
                    onChange={(e) =>
                      updateSource(index, {
                        source_type: e.target.value as PostSourceInput['source_type'],
                      })
                    }
                  >
                    {POST_SOURCE_TYPES.map((type) => (
                      <option key={type} value={type}>
                        {POST_SOURCE_TYPE_LABELS[type]}
                      </option>
                    ))}
                  </Select>
                </Label>

                <Label>
                  タイトル
                  <Input
                    value={source.title}
                    onChange={(e) => updateSource(index, { title: e.target.value })}
                    placeholder="例: 参考記事のタイトル"
                  />
                </Label>

                <Label>
                  URL
                  <Input
                    value={source.url}
                    onChange={(e) => updateSource(index, { url: e.target.value })}
                    placeholder="https://example.com/..."
                  />
                </Label>

                <Label>
                  補足
                  <Textarea
                    rows={2}
                    value={source.note}
                    onChange={(e) => updateSource(index, { note: e.target.value })}
                    placeholder="出典の説明など"
                  />
                </Label>
              </div>
            </li>
          ))}
        </ul>
      )}

      <Button type="button" variant="secondary" onClick={addSource}>
        ソースを追加
      </Button>
    </div>
  );
}
