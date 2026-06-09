'use client';

import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  createTag,
  searchTags,
  type PostTag,
} from '@/lib/post-tag';

type PostTagsEditorProps = {
  tags: PostTag[];
  onChange: (tags: PostTag[]) => void;
};

export function PostTagsEditor({ tags, onChange }: PostTagsEditorProps) {
  const [input, setInput] = useState('');
  const [suggestions, setSuggestions] = useState<PostTag[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [message, setMessage] = useState('');
  const [showSuggestions, setShowSuggestions] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const selectedIds = new Set(tags.map((tag) => tag.id));

  useEffect(() => {
    const keyword = input.trim();

    if (!keyword) {
      setSuggestions([]);
      return;
    }

    const timer = window.setTimeout(async () => {
      setIsSearching(true);
      setMessage('');

      try {
        const results = await searchTags(keyword);
        setSuggestions(results.filter((tag) => !selectedIds.has(tag.id)));
      } catch (error) {
        setMessage(
          error instanceof Error ? error.message : 'タグの取得に失敗しました。',
        );
      } finally {
        setIsSearching(false);
      }
    }, 250);

    return () => window.clearTimeout(timer);
  }, [input, tags]);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        containerRef.current &&
        !containerRef.current.contains(event.target as Node)
      ) {
        setShowSuggestions(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);

    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const addTag = (tag: PostTag) => {
    if (selectedIds.has(tag.id)) {
      return;
    }

    onChange([...tags, tag]);
    setInput('');
    setSuggestions([]);
    setShowSuggestions(false);
    setMessage('');
  };

  const removeTag = (tagId: number) => {
    onChange(tags.filter((tag) => tag.id !== tagId));
  };

  const handleCreateOrSelect = async () => {
    const name = input.trim();

    if (!name) {
      return;
    }

    const exactMatch = suggestions.find((tag) => tag.name === name);

    if (exactMatch) {
      addTag(exactMatch);
      return;
    }

    const alreadySelected = tags.find((tag) => tag.name === name);

    if (alreadySelected) {
      setInput('');
      return;
    }

    setMessage('');
    setIsSearching(true);

    try {
      const tag = await createTag(name);
      addTag(tag);
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : 'タグの作成に失敗しました。',
      );
    } finally {
      setIsSearching(false);
    }
  };

  const handleKeyDown = async (
    event: React.KeyboardEvent<HTMLInputElement>,
  ) => {
    if (event.key !== 'Enter') {
      return;
    }

    event.preventDefault();
    await handleCreateOrSelect();
  };

  return (
    <div ref={containerRef} className="grid gap-3">
      <div>
        <p className="text-sm font-medium text-foreground">タグ</p>
        <p className="mt-1 text-xs text-muted">
          入力すると既存タグをサジェストします。Enter で新規作成または選択できます。
        </p>
      </div>

      {tags.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {tags.map((tag) => (
            <span
              key={tag.id}
              className="inline-flex items-center gap-1 rounded-full border border-border bg-accent px-2.5 py-1 text-xs font-medium text-foreground"
            >
              #{tag.name}
              <button
                type="button"
                className="rounded-full px-1 text-muted hover:text-destructive"
                aria-label={`${tag.name} を削除`}
                onClick={() => removeTag(tag.id)}
              >
                ×
              </button>
            </span>
          ))}
        </div>
      )}

      <Label className="relative">
        タグを追加
        <Input
          value={input}
          placeholder="例: エネルギー政策"
          onChange={(e) => {
            setInput(e.target.value);
            setShowSuggestions(true);
          }}
          onFocus={() => setShowSuggestions(true)}
          onKeyDown={handleKeyDown}
        />

        {showSuggestions && input.trim() && (
          <div className="absolute z-10 mt-1 w-full rounded-lg border border-border bg-card shadow-md">
            {isSearching ? (
              <p className="px-3 py-2 text-sm text-muted">検索中...</p>
            ) : suggestions.length > 0 ? (
              <ul>
                {suggestions.map((tag) => (
                  <li key={tag.id}>
                    <button
                      type="button"
                      className="w-full px-3 py-2 text-left text-sm hover:bg-accent"
                      onClick={() => addTag(tag)}
                    >
                      #{tag.name}
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="px-3 py-2 text-sm text-muted">
                Enter で「{input.trim()}」を新規タグとして追加
              </p>
            )}
          </div>
        )}
      </Label>

      <div>
        <Button
          type="button"
          variant="secondary"
          className="text-xs"
          disabled={!input.trim() || isSearching}
          onClick={handleCreateOrSelect}
        >
          タグを追加
        </Button>
      </div>

      {message && <p className="text-xs text-destructive">{message}</p>}
    </div>
  );
}
