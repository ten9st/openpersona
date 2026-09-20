<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Models\Tag;

class TagService
{
    /**
     * 同名のタグがあればそれを返し、なければ新規作成して返す。
     *
     * $nameは呼び出し側で検証・trim・空文字チェック済みの値を渡す契約とし、
     * このメソッドでは正規化しない。slug生成(空slug時の代替値・衝突時の連番)は
     * 既存のTag::createFromName()に任せる。HTTPレスポンスの組み立ては行わない。
     *
     * トランザクションは使わない。書き込みはtagsへの単一INSERTのみで、
     * Tagにオブザーバーやモデルイベントはない。「確認→作成」の競合は
     * トランザクションでは防げないため、既存挙動のまま(slugの一意制約が
     * 最終防衛線で、同時実行時は一意制約違反になり得る)。
     *
     * @return array{tag: Tag, created: bool} created は今回新規作成した場合のみtrue
     */
    public function findOrCreateByName(string $name): array
    {
        $existing = Tag::findByName($name);

        if ($existing !== null) {
            return ['tag' => $existing, 'created' => false];
        }

        return ['tag' => Tag::createFromName($name), 'created' => true];
    }
}
