<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostSource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PostService
{
    private const CORRECTION_TITLE_PREFIX = '【訂正】';

    /**
     * @param  array<string, mixed>  $validated
     */
    public function store(User $user, array $validated): Post
    {
        return DB::transaction(function () use ($user, $validated) {
            $status = $validated['status'] ?? 'draft';

            $post = Post::create([
                'user_id' => $user->id,
                'category_id' => $validated['category_id'],
                'title' => $validated['title'],
                'body' => $validated['body'],
                'status' => $status,
                'published_at' => $status === 'published' ? Carbon::now() : null,
            ]);

            if (array_key_exists('sources', $validated)) {
                $this->syncSources($post, $validated['sources']);
            }

            if (array_key_exists('tag_ids', $validated)) {
                $post->tags()->sync($validated['tag_ids']);
            }

            $post->load(['sources', 'tags:id,name,slug']);

            return $post;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Post $post, array $validated): Post
    {
        return DB::transaction(function () use ($post, $validated) {
            $status = $validated['status'] ?? $post->status;
            $publishedAt = $post->published_at;

            if ($status === 'published' && $publishedAt === null) {
                $publishedAt = Carbon::now();
            } elseif ($status === 'draft') {
                $publishedAt = null;
            }

            $post->update([
                ...array_intersect_key($validated, array_flip(['category_id', 'title', 'body'])),
                'status' => $status,
                'published_at' => $publishedAt,
            ]);

            if (array_key_exists('sources', $validated)) {
                $this->syncSources($post, $validated['sources']);
            }

            return $post->fresh(['sources']);
        });
    }

    public function copy(User $user, Post $post): Post
    {
        return DB::transaction(function () use ($user, $post) {
            $post->load('sources');

            $copy = Post::create([
                'user_id' => $user->id,
                'category_id' => $post->category_id,
                'title' => $this->correctionTitle($post->title),
                'body' => $post->body,
                'status' => 'draft',
                'published_at' => null,
            ]);

            foreach ($post->sources as $source) {
                $copy->sources()->create([
                    'source_type' => $source->source_type,
                    'title' => $source->title,
                    'url' => $source->url,
                    'note' => $source->note,
                ]);
            }

            return $copy->load('sources');
        });
    }

    public function destroy(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $post->update(['status' => 'deleted']);
        });
    }

    private function correctionTitle(string $title): string
    {
        if (str_starts_with($title, self::CORRECTION_TITLE_PREFIX)) {
            return $title;
        }

        return self::CORRECTION_TITLE_PREFIX.$title;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function syncSources(Post $post, array $sources): void
    {
        $post->sources()->delete();

        foreach ($sources as $source) {
            $post->sources()->create([
                'source_type' => $source['source_type'] ?? PostSource::TYPE_URL,
                'title' => $source['title'] ?? null,
                'url' => $source['url'] ?? null,
                'note' => $source['note'] ?? null,
            ]);
        }
    }
}
