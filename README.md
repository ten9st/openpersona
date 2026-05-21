# OpenPersona

## 概要
OpenPersonaは、個人が自身の情報を選択的に公開し、
その透明性によって情報の信頼性を高めることを目的としたプラットフォームです。

従来の匿名型SNSとは異なり、「誰が発信しているか」を重視し、
投稿内容と発信者の情報を結びつけることで、
信頼できる情報基盤の構築を目指します。

---

## コンセプト

- 匿名ではなく「責任のある発信」
- 公開情報と信頼性の紐付け
- 信頼性の可視化
- 長文・論理的な情報共有

---

## 解決したい課題

現在のSNSには以下の課題があります。

- 匿名性により情報の信頼性が低い
- 誰が発信しているか不明確
- 評価が「いいね」などの単純指標に依存している

---

## 解決手段

OpenPersonaでは以下を組み合わせて解決します。

- ユーザーの公開プロフィール情報
- 投稿内容
- 情報ソース（参考文献など）
- 履歴（過去の発言・経歴）

これらを統合し、
「情報の信頼性」を判断可能にする仕組みを提供します。

---

## システムの特徴

- 公開情報と投稿を紐付けるSNS構造
- 投稿の信頼性をユーザー情報に基づいて評価
- 履歴情報を含めた一貫性の可視化
- 情報ソース付き投稿

---

## 想定ユースケース

- 政治・政策議論
- 専門知識の共有
- 社会問題の議論
- 研究・考察の発信

---

## 技術スタック

- Frontend: Next.js
- Backend: Laravel
- Database: PostgreSQL
- Infrastructure: Docker
- IDE: Cursor

--- 

## ディレクトリ構成
openpersona
┣ frontend/ # Next.js
┣ backend/ # Laravel
┣ infra/ # Docker設定
┣ docs/ # 設計メモ

---

## 開発環境の起動方法

```bash
cd infra
docer compose up -d
```

---

## ポート
- frontend: http://localhost:3000
- backend: http://localhost:8000
- db: localhost:5432

---

## DB更新の方針
- 認証まわり：Eloquentのまま
- 一覧取得：Query BuilderでもOK
- 大量更新：Query Builder / 生SQL
- 特殊な重い処理：生SQL