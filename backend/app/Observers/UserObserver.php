<?php

namespace App\Observers;

use App\Models\User;
use App\Services\TrustScoreService;

class UserObserver
{
    public function __construct(
        private TrustScoreService $trustScoreService
    ) {}

    public function created(User $user): void
    {
        $this->trustScoreService->calculate($user);
    }
}
