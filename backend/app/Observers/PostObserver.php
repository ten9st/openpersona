<?php

namespace App\Observers;

use App\Models\Post;
use App\Services\TrustScoreService;

class PostObserver
{
    public function __construct(
        private TrustScoreService $trustScoreService
    ) {}

    public function created(Post $post): void
    {
        $this->recalculate($post);
    }

    public function deleted(Post $post): void
    {
        $this->recalculate($post);
    }

    private function recalculate(Post $post): void
    {
        $post->loadMissing('user');

        if ($post->user !== null) {
            $this->trustScoreService->calculate($post->user);
        }
    }
}
