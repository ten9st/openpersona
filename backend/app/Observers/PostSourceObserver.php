<?php

namespace App\Observers;

use App\Models\PostSource;
use App\Services\TrustScoreService;

class PostSourceObserver
{
    public function __construct(
        private TrustScoreService $trustScoreService
    ) {}

    public function created(PostSource $postSource): void
    {
        $this->recalculate($postSource);
    }

    public function updated(PostSource $postSource): void
    {
        $this->recalculate($postSource);
    }

    public function deleted(PostSource $postSource): void
    {
        $this->recalculate($postSource);
    }

    private function recalculate(PostSource $postSource): void
    {
        $postSource->loadMissing('post.user');

        if ($postSource->post?->user !== null) {
            $this->trustScoreService->calculate($postSource->post->user);
        }
    }
}
