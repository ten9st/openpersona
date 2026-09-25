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
  const [isCreating, setIsCreating] = useState(false);
  const [searchMessage, setSearchMessage] = useState('');
  const [createMessage, setCreateMessage] = useState('');
  const [showSuggestions, setShowSuggestions] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const selectedIds = new Set(tags.map((tag) => tag.id));

  useEffect(() => {
    const keyword = input.trim();

    if (!keyword) {
      return;
    }

    // 入力・選択済みタグの変更やアンマウントで古くなった検索の結果は反映しない。
    // (通信自体は中断しない)
    let ignore = false;

    const timer = window.setTimeout(async () => {
      setIsSearching(true);
      setSearchMessage('');

      try {
        const results = await searchTags(keyword);

        if (ignore) {
          return;
        }

        setSuggestions(
          results.filter(
            (tag) => !tags.some((selected) => selected.id === tag.id),
          ),
        );
      } catch (error) {
        if (ignore) {
          return;
        }

        setSearchMessage(
          error instanceof Error ? error.message : 'タグの取得に失敗しました。',
        );
      } finally {
        // 古い検索の完了で、後続の検索中表示を解除しない。
        if (!ignore) {
          setIsSearching(false);
        }
      }
    }, 250);

    return () => {
      ignore = true;
      window.clearTimeout(timer);
    };
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
    setIsSearching(false);
    setShowSuggestions(false);
    setSearchMessage('');
    setCreateMessage('');
  };

  const removeTag = (tagId: number) => {
    onChange(tags.filter((tag) => tag.id !== tagId));
  };

  const handleCreateOrSelect = async () => {
    const name = input.trim();

    if (!name || isCreating) {
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
      setSuggestions([]);
      setIsSearching(false);
      return;
    }

    setCreateMessage('');
    setIsCreating(true);

    try {
      const tag = await createTag(name);
      addTag(tag);
    } catch (error) {
      setCreateMessage(
        error instanceof Error ? error.message : 'タグの作成に失敗しました。',
      );
    } finally {
      setIsCreating(false);
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

            if (!e.target.value.trim()) {
              setSuggestions([]);
              setIsSearching(false);
            }
          }}
          onFocus={() => setShowSuggestions(true)}
          onKeyDown={handleKeyDown}
        />

        {showSuggestions && input.trim() && (
          <div className="absolute z-10 mt-1 w-full rounded-lg border border-border bg-card shadow-md">
            {isCreating ? (
              <p className="px-3 py-2 text-sm text-muted">タグを作成中...</p>
            ) : isSearching ? (
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
          disabled={!input.trim() || isSearching || isCreating}
          onClick={handleCreateOrSelect}
        >
          タグを追加
        </Button>
      </div>

      {/* 利用者が実行した作成のエラーを先に、検索のエラーをその後に表示する */}
      {createMessage && (
        <p className="text-xs text-destructive">{createMessage}</p>
      )}
      {searchMessage && (
        <p className="text-xs text-destructive">{searchMessage}</p>
      )}
    </div>
  );
}
