<?php

namespace Tests\Support;

use RuntimeException;

/**
 * トランザクションのロールバック回帰テストで、モデルイベント経由で
 * 途中の書き込みを意図的に失敗させるための専用例外。
 * 無関係な例外を誤って「意図した失敗」として扱わないよう、
 * catch側でこのクラスだけを狙い撃ちできるようにする。
 */
class TransactionSeamFailureForTest extends RuntimeException {}
