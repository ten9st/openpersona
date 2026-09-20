<?php

namespace App\Domain\Post\QueryServices;

use App\Domain\Post\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

class TagQueryService
{
    /**
     * タグをname昇順で最大20件、id・name・slugのみ取得する。
     *
     * $searchが空(null・空文字・"0")の場合は絞り込まない。"0"で絞り込まない
     * のは既存のempty()判定を維持しているため。指定時はnameの部分一致
     * (LIKE)で絞り込み、%・_のエスケープや大文字小文字・照合順序はDB任せで
     * 既存のまま。書き込みは行わない。
     *
     * @return Collection<int, Tag>
     */
    public function search(?string $search): Collection
    {
        $query = Tag::query()->orderBy('name');

        if (! empty($search)) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return $query
            ->limit(20)
            ->get(['id', 'name', 'slug']);
    }
}
