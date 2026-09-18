<?php

namespace Tests\Support;

use RuntimeException;

/**
 * トランザクションのロールバック回帰テストでのみ使用する専用例外。
 * 無関係な例外を誤って「意図した失敗」として扱わないよう、
 * catch側でこのクラスだけを狙い撃ちできるようにする。
 */
class TrustScoreRecalculationFailedForTest extends RuntimeException {}
