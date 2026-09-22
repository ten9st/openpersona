<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use PDO;

/**
 * 最小アプリのbootstrap開始前に設置する、DB関連サービスのコンテナ解決を検知する防壁。
 *
 * Illuminate\Container\Container::beforeResolving() は、対象の抽象(文字列キー・
 * クラス名のいずれも)がコンテナから解決される「直前」、すなわち実際のbinding
 * (クロージャの実行・クラスのインスタンス化)が一切実行される前に同期的に発火する
 * (vendor/laravel/framework/src/Illuminate/Container/Container.php の resolve() を参照。
 * fireBeforeResolvingCallbacks() は、$concrete の解決・build()の呼び出しより前、
 * resolve()の冒頭で呼ばれる)。
 *
 * install()は「第2引数を渡さずClosureだけを渡す」形でbeforeResolving()を呼び、
 * これによりコンテナの globalBeforeResolvingCallbacks に登録される。この配列は
 * 追記のみで、後から登録される個別のbind()呼び出し(DatabaseServiceProvider::register()
 * が 'db'・'db.factory' 等をbind/singletonする処理を含む)によって上書き・無効化される
 * ことはない。「後続Providerの登録によって防壁が上書きされない」という要件はこの
 * コンテナ機構の性質そのものに由来し、独自の仕組みを再実装していない。
 *
 * DBへは一切接続しない。検知した時点で即座に例外(DatabaseResolutionAttemptedException)
 * を投げ、実際のbinding解決・PDO生成には一切進ませない。
 *
 * 保証範囲外:
 * - $container->make()/build() を経由しない解決経路(例えばコード中で直接
 *   `new PDO(...)` する、`new \Illuminate\Database\SQLiteConnection(...)` を直接
 *   newする等)は、コンテナのbeforeResolvingを経由しないため検知できない。
 *   今回のテストコード・検証用Provider・最小アプリの構成では、そのような直接
 *   インスタンス化を行っていないことをコードレビューで確認している。
 * - コンテナに登録された「エイリアス」を経由しない、未知の抽象名でのDBクラスの
 *   解決(WATCHED_STRING_ABSTRACTS・WATCHED_CLASS_ABSTRACTSに含まれない名前)は
 *   検知対象外。
 */
class DatabaseResolutionSentinel
{
    /**
     * 検知した抽象の名前のみを記録する(接続情報・パラメータの値は一切保持しない)。
     *
     * @var array<int, string>
     */
    public static array $attemptedAbstracts = [];

    /**
     * 監視対象とみなす、コンテナの文字列キー。
     *
     * @var array<int, string>
     */
    private const WATCHED_STRING_ABSTRACTS = [
        'db',
        'db.factory',
        'db.connection',
        'db.schema',
        'db.transactions',
    ];

    /**
     * 監視対象とみなす、クラス名・インターフェース名。サブクラス・実装クラスも
     * is_a()でまとめて捕捉する(例: Illuminate\Database\ConnectionもConnectionInterface
     * を実装しているため、ConnectionInterfaceの指定だけで捕捉できる)。
     *
     * @var array<int, class-string>
     */
    private const WATCHED_CLASS_ABSTRACTS = [
        DatabaseManager::class,
        ConnectionFactory::class,
        ConnectionInterface::class,
        ConnectionResolverInterface::class,
        PDO::class,
    ];

    public static function reset(): void
    {
        self::$attemptedAbstracts = [];
    }

    public static function attempts(): int
    {
        return count(self::$attemptedAbstracts);
    }

    /**
     * $app に対し、DB関連の抽象がコンテナから解決されようとした瞬間に例外を投げる
     * グローバルなbeforeResolvingコールバックを登録する。最小アプリの
     * $app->make(Kernel::class)->bootstrap() を呼ぶ前に呼ぶ契約とする。
     */
    public static function install(Application $app): void
    {
        $app->beforeResolving(function ($abstract, $parameters, $container) {
            if (! self::isDatabaseRelated($abstract)) {
                return;
            }

            self::$attemptedAbstracts[] = (string) $abstract;

            throw new DatabaseResolutionAttemptedException(
                "DatabaseResolutionSentinel: DB関連サービス [{$abstract}] のコンテナ解決が".
                '試みられたため、実際のbinding解決・接続処理に進む前に停止しました。'
            );
        });
    }

    private static function isDatabaseRelated(mixed $abstract): bool
    {
        if (! is_string($abstract) || $abstract === '') {
            return false;
        }

        if (in_array($abstract, self::WATCHED_STRING_ABSTRACTS, true)) {
            return true;
        }

        foreach (self::WATCHED_CLASS_ABSTRACTS as $watched) {
            if ($abstract === $watched || is_a($abstract, $watched, true)) {
                return true;
            }
        }

        return false;
    }
}
