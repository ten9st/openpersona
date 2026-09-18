<?php

namespace App\Http\Controllers;

use App\Domain\Profile\Presenters\ProfilePresenter;
use App\Domain\Profile\QueryServices\ProfileQueryService;
use App\Domain\Profile\Services\ProfileService;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profileService,
        private ProfileQueryService $profileQueryService,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        $this->profileService->ensureInitialized($user);
        $user = $this->profileQueryService->forUser($user);

        return response()->json(ProfilePresenter::format($user));
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();

        $this->profileService->update($user, $request->validated());

        $user = $this->profileQueryService->forUser($user);

        return response()->json([
            'message' => 'プロフィールを更新しました。',
            ...ProfilePresenter::format($user),
        ]);
    }
}
