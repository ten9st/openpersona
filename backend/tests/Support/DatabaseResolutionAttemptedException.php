<?php

namespace Tests\Support;

use RuntimeException;

/**
 * DatabaseResolutionSentinelが、DB関連サービスのコンテナ解決を検知した際に投げる専用例外。
 *
 * TestDatabaseGuardが投げる例外(接続設定を検査した結果の「意図した拒否」)とは別物であり、
 * 結合テストのアサーションで両者を取り違えないよう、意図的に別クラスにしている。
 */
class DatabaseResolutionAttemptedException extends RuntimeException {}
