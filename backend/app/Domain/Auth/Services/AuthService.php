<?php

namespace App\Domain\Auth\Services;

use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * ユーザーを登録し、Profileと公開設定の既定値を作成して返す。
     *
     * $validatedは呼び出し側で入力検証・整形(trim等)済みの値を渡す契約とし、
     * このメソッドでは検証や正規化をしない。HTTPレスポンスは作成しない。
     *
     * 作成順序は User → (UserObserver::created経由でTrustScore) → Profile →
     * 公開設定。この順序は返却するUserのシリアライズ結果(Observer内で読み込まれた
     * リレーションを含む)に影響するため変えない。返却するUserに対してfresh()や
     * 追加のリレーション読み込みもしないこと。
     *
     * 全体を1つのDB::transaction()で囲むため、途中で失敗した場合はUser・
     * TrustScore・Profile・公開設定のすべてがロールバックされ、部分的なデータは
     * 残らない。UserObserver内のTrustScore書き込みも同一接続・同一トランザクション
     * に含まれる。ロールバック後の例外はそのまま呼び出し元へ伝播する。
     */
    public function register(array $validated): User
    {
        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'last_name' => $validated['last_name'],
                'first_name' => $validated['first_name'],
                'birthdate' => $validated['birthdate'],
            ]);

            Profile::create(['user_id' => $user->id]);

            foreach (ProfileVisibility::defaultMap() as $fieldName => $isPublic) {
                ProfileVisibility::create([
                    'user_id' => $user->id,
                    'field_name' => $fieldName,
                    'is_public' => $isPublic,
                ]);
            }

            return $user;
        });
    }
}
