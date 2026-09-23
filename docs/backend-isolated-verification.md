# バックエンド隔離検証(2026-09-22)

開発用DB・開発用コンテナに一切触れない隔離環境で、バックエンドテスト(通常スイート・`tests/Integration`)を実際に実行し、成功したことの記録。再実行したい場合の手順も兼ねる。

背景・設計判断(`TestDatabaseGuard`・`DatabaseResolutionSentinel`の実装意図)は [`docs/memo.md`](memo.md) の「テスト用DB安全ガード」を参照。ここでは重複を避け、**実際に隔離環境で実行して確認した結果**だけを記録する。

## 1. 検証対象と結果

- 検証日:2026-09-22
- 検証対象コミット:`45c3c27147d587eced61abdfa3c884340e4cfea8`
- 実行環境:PHP 8.3.32 / PHPUnit 12.5.24 / DB接続はSQLite `:memory:` のみ / GD(JPEG・libpng・zlib対応)を追加した検証専用コンテナイメージ

### 最終結果(重複なしの合計)

| スイート | 件数 | アサーション | 結果 | risky | warning/skip |
|---|---:|---:|---|---:|---:|
| 通常スイート(`tests/Unit` + `tests/Feature`、`phpunit.xml`使用) | 259 | 1,183 | 成功 | 0 | 0 |
| `tests/Integration`(別実行) | 8 | 65 | 成功 | 0 | 0 |
| **合計** | **267** | **1,248** | **成功** | **0** | **0** |

risky・warning・skipは上記2回の実行で実際に出力を確認した値(PHPUnitの結果行に該当セクションが出なかったことを確認)。他の指標について推測で埋めた値はない。

### 部分実行(上記合計に含まれる。加算しない)

検証を段階的に進めた際、上記合計に**含まれる**部分集合を個別にも実行している。

- ガード単体・結合テスト32件(127アサーション、成功):内訳は `tests/Unit/Support/` 24件(通常スイートの一部)+ `tests/Integration` 8件(別実行分と同一)
- 添付ファイル関連2クラス(`PostAttachmentTest`・`PostAttachmentFailureHandlingTest`)33件(229アサーション、成功):通常スイート259件の一部

これらは同一コミット・同一隔離環境での再実行であり、上記267件・1,248アサーションとは別に加算しない。

### GD不足の解消

検証専用イメージにGD拡張を追加する前の実行では、`UploadedFile::fake()->image(...)` を使う9件が `LogicException: GD extension is not installed.` で失敗し、そのうち3件が「エラーハンドラ/例外ハンドラが除去されていない」というriskyの指摘も伴っていた。GD(JPEG・libpng・zlib対応)を追加した検証専用イメージへ切り替えた後の再実行では、この9件のエラーと3件のriskyは解消し、上記の「最終結果」どおり全件成功・risky 0件となった。

## 2. 隔離条件

- 開発用Docker Composeスタック(`infra/docker-compose.yml`の`backend`/`db`サービス)とは別の、使い捨てコンテナ。
- 起動オプション:`--rm --network none --cap-drop=ALL --security-opt=no-new-privileges`。
- ENTRYPOINTを `/usr/bin/env` に明示的に置き換え、`env -i` でコンテナのプロセス環境を空にしたうえで、必要な変数だけを設定(`-e`によるイメージ既定値への追加ではない)。
- 実際にコンテナ内で確認できた環境変数キーは、明示した20キー + シェル自身が作業ディレクトリとして自動設定する `PWD` の21個のみ(値ではなくキー名で比較し、許可リスト外の残存が無いことを確認)。
- マウント対象は、検証用コピーの `backend` ディレクトリ1つだけ。
- 元リポジトリ、ホストの `.env` 等の実環境ファイル、開発用の保存データ、Dockerソケット・開発用DBのソケットは一切持ち込んでいない。
- Artisanは経由せず、`vendor/bin/phpunit` を直接実行。
- SQLite `:memory:` に限定した`RefreshDatabase`によるスキーマ初期化・テストデータ操作は承認済みの範囲として実施。PostgreSQL・開発用DBへの接続は行っていない。

### 環境変数の許可リスト(20キー)

```
PATH TMPDIR
APP_ENV APP_DEBUG APP_KEY
DB_CONNECTION DB_DATABASE DB_URL
MAIL_MAILER QUEUE_CONNECTION SESSION_DRIVER CACHE_STORE LOG_CHANNEL FILESYSTEM_DISK
APP_CONFIG_CACHE APP_SERVICES_CACHE APP_PACKAGES_CACHE APP_ROUTES_CACHE APP_EVENTS_CACHE
COMPOSER_VENDOR_DIR
```

### TestDatabaseGuardとDatabaseResolutionSentinelの役割の違い

- **`TestDatabaseGuard`**(`backend/tests/Support/TestDatabaseGuard.php`):`backend/tests/TestCase.php` の `createApplication()` に組み込まれており、これを継承する通常のFeatureテスト・Unitテストを含め、実行されるたびに実効DB接続設定を検査する。今回の隔離環境での通常スイート実行(259件)でも、この経路で有効だった。
- **`DatabaseResolutionSentinel`**(`backend/tests/Support/DatabaseResolutionSentinel.php`):`tests/Integration/DatabaseGuardBootstrapIntegrationTest.php` が、その場限りの最小Laravelアプリをbootstrapする際にだけ設置する防壁。**通常のFeatureテスト・Unitテストには適用されていない**(適用すると`DatabaseServiceProvider`による正当な`'db'`解決まで拒否してしまうため、意図的に結合テスト専用としている)。

## 3. 再実行手順

以下はすべて2026-09-22に実際に成功した内容を基にした手順。**今回(ドキュメント作成・改訂時点)は実行していない。手順どおりに再実行して同じ結果になることは未確認のまま。** 一時ディレクトリのパスは実行環境ごとに異なるため、`$ISOLATED` をプレースホルダーとして使う。

全体を通じて `set -euo pipefail`(コマンド失敗・未定義変数参照・パイプライン内の失敗のいずれでも即座に停止)を前提とする。`$ISOLATED`・`$SHA`・`$IMG` は、それぞれ値が確定し確認が済むまで、削除・マウントを伴う操作(`rm`・`docker run -v` 等)には使わない。

### 3-0. 変数の設定と確認(削除・マウント操作より前に行う)

`set -euo pipefail` はこのブロックの冒頭から適用する。これ以降の各ブロックも、同じシェルセッションで続けて実行する前提(コピー&ペーストで区切って別々のシェルで実行する場合は、各ブロックの先頭でも`set -euo pipefail`を再度宣言すること)。

```bash
set -euo pipefail
cd /path/to/openpersona

# 検証対象コミットのSHAを明示的に設定し、実在することを確認する
SHA=45c3c27147d587eced61abdfa3c884340e4cfea8
git rev-parse --verify "${SHA}^{commit}" >/dev/null

# 検証用の一時ディレクトリを作り、空でなく実在することを確認してから使う
ISOLATED=$(mktemp -d -t openpersona-isolated-verify)
[ -n "$ISOLATED" ] && [ -d "$ISOLATED" ]
mkdir -p "$ISOLATED/backend"
```

`$IMG` は3-4でGD入りイメージのbuildが成功した後に設定する(後述)。

### 3-1. 検証用コピーの作成

```bash
set -euo pipefail   # 3-0から続けて実行しない場合は、この行から再度有効にする

git archive "$SHA" -- backend | tar -x -C "$ISOLATED"
```

`pipefail`が有効な状態でこのパイプラインを実行することで、`git archive`側が失敗した場合(不正な`$SHA`等)に、`tar`側が正常終了しても全体として停止するようにしている。`git archive`自体は、追跡ファイルをそのまま展開するだけで、シンボリックリンクや秘密情報を自動的に除外する機能は無い。次の除外確認を必ず行う。

### 3-2. 秘密情報・実データ・生成キャッシュ・外部参照リンクの除外確認(失敗時は停止する)

```bash
set -euo pipefail

# 設定例ファイルは今回の検証に不要なため、コピー側からのみ削除する(元リポジトリは変更しない)
rm -f "$ISOLATED/backend/.env.example" "$ISOLATED/backend/.env.docker.example"

# find自体の失敗(権限エラー等)と「該当なし」を区別する。
# 先に変数へ代入することで、set -e により find が失敗した時点(終了コード非0)で
# 即座に停止する(この行のエラー出力は隠さない)。停止しなければ、次に該当有無を判定する。
symlinks="$(find "$ISOLATED" -type l)"
if [ -n "$symlinks" ]; then
  echo "シンボリックリンクが見つかりました。後続には進みません。" >&2
  printf '%s\n' "$symlinks" >&2
  exit 1
fi

specials="$(find "$ISOLATED" \( -type s -o -type b -o -type c -o -type p \))"
if [ -n "$specials" ]; then
  echo "ソケット等の特殊ファイルが見つかりました。後続には進みません。" >&2
  printf '%s\n' "$specials" >&2
  exit 1
fi

dotenv_files="$(find "$ISOLATED/backend" -iname ".env*" -not -iname "*.example")"
if [ -n "$dotenv_files" ]; then
  echo ".env系ファイルが見つかりました。後続には進みません。" >&2
  printf '%s\n' "$dotenv_files" >&2
  exit 1
fi

sqlite_files="$(find "$ISOLATED/backend" -iname "*.sqlite*")"
if [ -n "$sqlite_files" ]; then
  echo "実DBファイルが見つかりました。後続には進みません。" >&2
  printf '%s\n' "$sqlite_files" >&2
  exit 1
fi

# 比較対象の一覧は、現在の作業ツリー(git ls-files)ではなく、検証対象コミット $SHA 時点の
# 追跡ファイル一覧から取得する。プロセス置換(< <(...))はset -o pipefailの対象外で、
# 一覧取得コマンド自体の失敗を素通りさせてしまう(結果が空のまま後続ループが0回で
# 「成功」したように見える)ため使わない。先に変数へ代入して一覧取得の成功
# (set -eにより失敗時はここで停止)と、一覧が空でないことを確認してからループへ渡す。
tracked_files="$(git ls-tree -r --name-only "$SHA" -- backend)"
if [ -z "$tracked_files" ]; then
  echo "検証対象コミットから追跡ファイル一覧を取得できませんでした。後続には進みません。" >&2
  exit 1
fi

# 差分がある場合・コミットからの読み取りに失敗した場合は即座に停止し、
# それ以降のファイルは確認しない。here-string(<<<)はループ本体を
# サブシェル化しないため、ループ内のexitがこのシェルをそのまま終了させる。
while IFS= read -r f; do
  case "$f" in backend/.env.example|backend/.env.docker.example) continue ;; esac
  rel="${f#backend/}"
  target="$ISOLATED/backend/$rel"
  if [ ! -f "$target" ]; then
    echo "コピー側にファイルがありません。後続には進みません: $rel" >&2
    exit 1
  fi
  if ! git show "$SHA:$f" | diff -q - "$target" >/dev/null; then
    echo "差分、またはコミットからの読み取りに失敗しました。後続には進みません: $rel" >&2
    exit 1
  fi
done <<< "$tracked_files"

echo "追跡ファイルの内容確認: 完了(差分なし)"
```

必要な空ディレクトリ(`storage/app/private`・`storage/app/public`・`storage/framework/cache/data`・`storage/framework/sessions`・`storage/framework/testing`・`storage/framework/views`・`storage/logs`)を作成する。`bootstrap/cache/`・`database/`直下は空のまま(生成物を持ち込まない)。

### 3-3. vendorのコピーと実行PHPとの互換性確認

```bash
set -euo pipefail

rsync -a --exclude='.git' /path/to/openpersona/backend/vendor/ "$ISOLATED/backend/vendor/"

# vendorコピー後のリンク・特殊ファイル検査も、表示するだけで終わらせず、
# 検出時・検査自体の失敗時ともに停止する。
vendor_symlinks="$(find "$ISOLATED/backend/vendor" -type l)"
if [ -n "$vendor_symlinks" ]; then
  echo "vendor配下にシンボリックリンクが見つかりました。後続には進みません。" >&2
  printf '%s\n' "$vendor_symlinks" >&2
  exit 1
fi

vendor_specials="$(find "$ISOLATED/backend/vendor" \( -type s -o -type b -o -type c -o -type p \))"
if [ -n "$vendor_specials" ]; then
  echo "vendor配下にソケット等の特殊ファイルが見つかりました。後続には進みません。" >&2
  printf '%s\n' "$vendor_specials" >&2
  exit 1
fi

# 実行PHPとの互換性は vendor/composer/platform_check.php(Composerが生成する実行時チェック)
# の内容を確認する。今回は「PHP >= 8.3.0」のみが要件で、コンテナのPHP 8.3.32はこれを満たした。
cat "$ISOLATED/backend/vendor/composer/platform_check.php"
```

この確認は、生成済みチェックの内容と今回実行した範囲での適合にとどまり、全依存関係・全コードパスの互換性を包括的に保証するものではない。

### 3-4. GD入り検証用イメージの準備

前提:確認済みのベースイメージIDが**ローカルに既に存在すること**、および専用タグが**未使用、または既に同じベースイメージIDを指していること**を、上書き操作より前に確認する。それ以外(専用タグが別のイメージに使われている)であれば上書きせず停止する。

```bash
set -e

BASE_ID=sha256:f298fadcd9be65013b0562450b92fdfcc12fcd0b23074b7dc4e546f8a986417d
docker image inspect "$BASE_ID" >/dev/null

BASE_TAG=openpersona-verify-base:f298fadc
EXISTING_ID="$(docker image inspect "$BASE_TAG" --format '{{.Id}}' 2>/dev/null || true)"
if [ -n "$EXISTING_ID" ] && [ "$EXISTING_ID" != "$BASE_ID" ]; then
  echo "タグ $BASE_TAG は別のイメージを指しています。上書きせず停止します。" >&2
  exit 1
fi
if [ -z "$EXISTING_ID" ]; then
  docker image tag "$BASE_ID" "$BASE_TAG"
fi
```

専用ディレクトリに、実際に成功したDockerfileを作る(GDはJPEG対応だけでなくlibpng・zlibも必須。詳細は4-2)。

```bash
mkdir -p "$ISOLATED/gd-build"
cat > "$ISOLATED/gd-build/Dockerfile.verify-gd" <<'EOF'
FROM openpersona-verify-base:f298fadc

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev \
        libpng-dev \
        zlib1g-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd
EOF
```

実際に成功したbuildコマンド(ベースイメージ取得の抑止についての注意は4-1参照):

```bash
docker build --no-cache --pull=false \
  -f "$ISOLATED/gd-build/Dockerfile.verify-gd" \
  -t openpersona-isolated-verify-gd:base-f298fadc \
  "$ISOLATED/gd-build"

# 後続の実行で使う $IMG を、実際に作られたイメージのIDから設定する(タグ名だけに頼らない)
IMG="$(docker image inspect openpersona-isolated-verify-gd:base-f298fadc --format '{{.Id}}')"
[ -n "$IMG" ]
```

### 3-5. 隔離コンテナでの実行順序

各段階とも、次の起動テンプレートを使う(`$IMG` は3-4で設定したGD入りイメージのID、末尾のコマンドだけ段階ごとに変える)。

```bash
docker run --rm \
  --network none \
  --cap-drop=ALL \
  --security-opt=no-new-privileges \
  -v "$ISOLATED/backend:/app" \
  -w /app \
  --entrypoint /usr/bin/env \
  "$IMG" \
  -i \
  PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
  TMPDIR=/tmp \
  APP_ENV=testing \
  APP_DEBUG=false \
  "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
  DB_CONNECTION=sqlite \
  "DB_DATABASE=:memory:" \
  DB_URL= \
  MAIL_MAILER=array \
  QUEUE_CONNECTION=sync \
  SESSION_DRIVER=array \
  CACHE_STORE=array \
  LOG_CHANNEL=null \
  FILESYSTEM_DISK=local \
  APP_CONFIG_CACHE=/app/bootstrap/cache/config.php \
  APP_SERVICES_CACHE=/app/bootstrap/cache/services.php \
  APP_PACKAGES_CACHE=/app/bootstrap/cache/packages.php \
  APP_ROUTES_CACHE=/app/bootstrap/cache/routes-v7.php \
  APP_EVENTS_CACHE=/app/bootstrap/cache/events.php \
  COMPOSER_VENDOR_DIR=/app/vendor \
  <段階ごとのコマンド>
```

`APP_KEY` は明らかなダミー値(全ゼロバイトのbase64)で、実認証情報ではない。

1. **GD・JPEG対応の確認**:`php -m | grep gd` と、`imagecreatetruecolor()`→`imagejpeg()` を実際に呼び出して生成データがJPEG開始マーカー(`FFD8`)で始まることを確認する。
2. **ガード単体・結合テスト**:
   ```
   php vendor/bin/phpunit --no-configuration --bootstrap=vendor/autoload.php \
     tests/Unit/Support/ tests/Integration/DatabaseGuardBootstrapIntegrationTest.php
   ```
3. **添付ファイル関連2クラス**:
   ```
   php vendor/bin/phpunit tests/Feature/PostAttachmentTest.php tests/Feature/PostAttachmentFailureHandlingTest.php
   ```
4. **通常スイート全体**(`phpunit.xml`を使用):
   ```
   php vendor/bin/phpunit
   ```
5. **`tests/Integration` を別実行**(**`phpunit.xml`の`<testsuites>`は`tests/Unit`・`tests/Feature`のみで`tests/Integration`を含まないため、通常スイートとは別に実行する必要がある**。4が成功した場合のみ実施):
   ```
   php vendor/bin/phpunit --no-configuration --bootstrap=vendor/autoload.php \
     tests/Integration/DatabaseGuardBootstrapIntegrationTest.php
   ```

**停止条件:** いずれかの段階でエラー・failure・risky・予期しないwarningが出たら、後続の段階には進まず結果を報告する。テスト・実装・設定の修正、スキップ、ガードの無効化は行わない。

## 4. イメージと依存関係

**以下はいずれも今回の検証環境だけに存在するローカル成果物であり、他の環境で取得できる公開イメージではない。** 再現するには4-1・4-2の手順で作り直す必要がある。

| 役割 | タグ | ID |
|---|---|---|
| ベース(既存の`infra-backend:latest`と同一) | `openpersona-verify-base:f298fadc` | `sha256:f298fadcd9be65013b0562450b92fdfcc12fcd0b23074b7dc4e546f8a986417d` |
| GD入り検証専用イメージ | `openpersona-isolated-verify-gd:base-f298fadc` | `sha256:a1cb6e38f6585d0abff278457fe99e986507b76aa7f9b597b54ad9239e906e4e` |

### 4-1. `FROM sha256:<ID>` が失敗し、専用ローカルタグで解決した経緯

当初 `FROM sha256:<ローカルイメージID>` を含むDockerfileをbuildしたところ、使用したBuildKitビルダー(`docker` driver)はこれをローカルイメージ解決に回さず、存在しないDocker Hub上のリポジトリとしてpullしようとして失敗した(`pull access denied`。実際のダウンロードは発生していない)。

対処として、確認済みのベースイメージへ、レジストリ上の同名リポジトリと衝突しないという保証はできないが、ローカル専用の用途として付けたタグ(`openpersona-verify-base:f298fadc`)を付け、Dockerfileの`FROM`をそのタグに変更してbuildし直したところ成功した。付与前に、このタグが未使用であること(既に別のイメージに使われていないこと)を確認している(手順は3-4参照)。

```bash
docker image tag sha256:f298fadcd9be65013b0562450b92fdfcc12fcd0b23074b7dc4e546f8a986417d \
  openpersona-verify-base:f298fadc
```

buildは `--pull=false` を指定して実行した。**`--pull=false` は「ベースイメージの取得を禁止する設定」ではない。** ローカルに存在するイメージを優先して使うことを指示するだけで、ベースイメージがローカルに無い場合に取得が発生しないという保証や、レジストリ通信を全面的に禁止する保証はない。今回はbuildの前に、ベースイメージがローカルに存在することを別途確認したうえで使った(手順は3-4参照)。なお、Dockerfile内の`RUN apt-get ...`が行うパッケージ取得は、この指定とは無関係に別途ネットワークを使用する(4-2参照)。

### 4-2. GDの依存関係とパッケージ

PHPソース(`ext/gd/config.m4`)を確認したところ、GDはJPEGの使用有無に関わらず、**zlibとlibpngも必須**とする実装だった。このため、JPEG対応だけでなく次の3パッケージを直接指定した。

- `libjpeg62-turbo-dev`
- `libpng-dev`
- `zlib1g-dev`

`apt-get install`実行時、上記3パッケージの依存関係として `libjpeg62-turbo`・`libpng16-16t64` の2件が追加され、**新規インストールは合計5件**だった。**既存パッケージのアップグレード・削除は0件**(`apt-get install -s` によるシミュレーションと、実際のbuildログの両方で確認)。FreeType・WebP等、テストで使わない機能は追加していない。

## 5. 残る確認事項と後片付け

### 未実施

- PostgreSQLでの実行確認
- ブラウザ・Cookie認証を伴う確認
- E2E
- `main`ブランチへの取り込み判断

今回の成功は、**上記の検証対象コミットと隔離環境での結果**であり、開発用コンテナ内で自由にバックエンドテストを実行してよいという保証ではない(開発用コンテナは現在もPostgreSQLに接続された状態で稼働しており、今回の隔離条件とは異なる)。

### 保持中の成果物

- 検証用コピー(一時ディレクトリ。パスは実行環境依存のため上記手順では`$ISOLATED`と表記)
- 追加したベース用タグ `openpersona-verify-base:f298fadc`(1つ。既存の`infra-backend:latest`と同じイメージID`sha256:f298fadc...`を参照するタグであり、新たなイメージ本体を作ったものではない)
- 新規に作られたGD入りイメージ `openpersona-isolated-verify-gd:base-f298fadc`(1つ)

いずれもレビューのため削除せず保持している。

### 後片付けの方針

後片付けは、今回作成した対象(上記の検証用コピー、追加したベース用タグ1つ、新規GD入りイメージ1つ)を個別に確認したうえで行う。ベース用タグは既存イメージ`infra-backend:latest`と同じIDを参照しているため、**イメージ本体をID指定で削除しない**(削除するならタグ参照の解除にとどめ、ID自体の削除は既存イメージにも影響するため行わない)。既存イメージ(`infra-backend:latest`等)・開発用コンテナ・開発用ボリュームを削除するコマンドや、`docker system prune`等の広範なpruneは対象外とし、本書にも記載しない。
