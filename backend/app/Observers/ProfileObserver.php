<?php

namespace App\Observers;

use App\Models\Profile;
use App\Services\TrustScoreService;

class ProfileObserver
{
    public function __construct(
        private TrustScoreService $trustScoreService
    ) {}

    public function updated(Profile $profile): void
    {
        $profile->loadMissing('user');

        if ($profile->user !== null) {
            $this->trustScoreService->calculate($profile->user);
        }
    }
}
