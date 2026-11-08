# かなめ結び — Claude Code 用メモ

読書・映像鑑賞の記録を積み、思考の深掘りに使う自分用 Web アプリ。二階建て。

- **一階（記録の器）**: ConoHa WING（nuts024.com）上の PHP 群。1記録 = 1 Markdown ファイル（6桁連番 `000000.md`〜）。
- **二階（翻訳対話）**: `toi.html`（ブラウザ完結・backup の zip を読んで Claude API と対話）、`SYSTEM.md`（対話の質の芯）、`digest.py`（Claude Code 用 digest 出力）。

## ファイル

| ファイル | 役割 |
|---|---|
| config.php | フィールド定義（`$KANAME_FIELDS`）・タグ・進捗・見出し定数。MD の型を変えるときは原則ここだけ |
| md.php | `kaname_parse()` / `kaname_build()`（往復で元に戻る）、`kaname_safe_id()`、`kaname_path()`、`kaname_next_id()`、`kaname_watching_*()` |
| list.php / new.php / edit.php / timeline.php / backup.php | 一覧・新規・編集・時刻メモ・一括DL。すべて `auth.php`（門番・session 認証）を通る |
| api.php | 読み取り専用 JSON（他AI連携用）。`X-Kaname-Token` ヘッダーをSHA-256 照合。auth.php は使わない |
| auth.php | **リポジトリに無い**（パスワードハッシュを持つためサーバーにだけ置く。.gitignore 済み） |

## MD の形式（`---` 区切りは無い）

```
series: シリーズ名
episode: 1
subtitle:
author:
url:
start: YYYY-MM-DD
date: YYYY-MM-DD
tags: アニメ, 映画
media: 映像
timeline: あり
status: 終了
duration: 1420
resume: 0

## 時刻メモ        ← 中身がある時だけ
- [mm:ss] 本文

## 総評
本文

score: 4
```

- front matter の順序は `$KANAME_FIELDS` の順。tags の区切りは `, `（読むときは `,、，` を許容）。
- `kaname_data/_watching.txt` は追いかけ中シリーズの代表話の6桁番号を1行1つ。6桁 MD 以外は一覧・採番・API の対象外。
- 番号は保存の瞬間に確定（`kaname_next_id()`）。

## 決まりごと

- 記録本体（`kaname_data/`）は公開領域内だが `.htaccess` の `Require all denied` で直接アクセス拒否。リポジトリにも入れない。
- id を受け取ったら必ず `kaname_safe_id()` / `kaname_path()` を通す。
- トークンやパスワードの値は、コード・ドキュメント・チャットに書かない。置くのはハッシュだけ。
- md.php の parse と digest.py の `parse_md` と toi.html の解析は同じ分解をする約束。形式を変えたら三つとも揃える。
- PHP は ConoHa WING 上で動く前提（共有ホスティング・外部ライブラリなし）。

## api.php

- `GET /api.php` 全件、`GET /api.php?id=000123` 1件（無ければ 404 `{"error":"not_found"}`）。
- 認証失敗は 401 `{"error":"auth"}`。`KANAME_API_TOKEN_SHA256` が空の間は常に 401。
- 返す形: `{app, count, generatedAt, watching[], records[{id, series, episode, subtitle, author, url, start, date, tags[], media, timeline, status, duration, resume, notes[{t,text}], review, score}]}`。空の値は null（tags/notes は空配列）。
- CORS なし（Muse はサーバー間通信）。

## 動作確認（ローカル）

PHP 8 があれば `php -S 127.0.0.1:8000` を、config.php・md.php・api.php と、ダミーの `kaname_data/` を置いたディレクトリで起動して curl で叩く。デプロイは手作業（ConoHa へアップロード）。
