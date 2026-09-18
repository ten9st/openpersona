<?php

namespace Tests\Support;

use App\Models\TrustScore;
use App\Models\User;
use App\Services\TrustScoreService;

/**
 * PostObserver/PostSourceObserverから呼ばれるTrustScoreService::calculate()を
 * 実際にN回目まで本来の計算・DB書き込みとして実行したうえで、
 * 指定回数目の呼び出し直後にだけ例外を投げるテスト専用スパイ。
 *
 * 「本来の計算を実行してから失敗させる」ことで、スコアが実際に変化する
 * 条件を再現しつつ、意図した箇所でのみ失敗を発生させられる。
 */
class FailingTrustScoreService extends TrustScoreService
{
    /** @var array<int, int> 実際の計算後に観測したtotal_scoreの履歴 */
    public array $observedTotalScores = [];

    private int $calls = 0;

    public function __construct(
        private readonly int $failOnCall,
        private readonly \Throwable $exception,
    ) {}

    public function calculate(User $user): void
    {
        $this->calls++;

        parent::calculate($user);

        $this->observedTotalScores[] = (int) TrustScore::query()
            ->where('user_id', $user->id)
            ->value('total_score');

        if ($this->calls === $this->failOnCall) {
            throw $this->exception;
        }
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
