<?php
// =============================================================
// config.php — かなめ結び 構造定義の一箇所
//   パーサー(parse)もビルダー(build)もここだけを見る。
//   MDの型を変えたいときは、原則このファイルだけ直す。
// =============================================================

// --- 保管場所 ---------------------------------------------------
// 門番の奥（.htaccess で直接アクセス拒否したフォルダ）。
// 環境に合わせて実パスに直すこと。
define('KANAME_DATA_DIR', __DIR__ . '/kaname_data');

// --- 選択肢の定義 ----------------------------------------------
// タグ候補（ホーム画面と同じ並び。複数選択）
$KANAME_TAGS = [
    'アニメ', 'Vtuver', '自己啓発', '生物学', 'デザイン', '映画',
    'SCP', '小説本', 'マーケティング', '健康', '物理学', '社会学',
    'AI', '歴史', '音楽', 'グルメ', 'プログラミング',
];
// 進捗（status）候補
$KANAME_STATUS = ['', '進行中', '終了'];
// スコア候補（0〜5）
$KANAME_SCORES = ['0', '1', '2', '3', '4', '5'];

// --- 頭の構造データ（front matter）の項目定義 -------------------
// key      … MDの「key: 値」の左側。分解も組立もこの綴りで揃える
// label    … 編集画面に出す見出し
// type     … 入力欄の種類（text / number / select / checkboxes）
// options  … select / checkboxes のときの候補
// default  … 新規や欠損時の初期値
//
// ★ここに並べた順で front matter を書き出す（順序も構造の一部）
$KANAME_FIELDS = [
    'series'   => ['label' => 'シリーズ名',   'type' => 'text'],
    'episode'  => ['label' => '話数',         'type' => 'number', 'default' => '1'],
    'subtitle' => ['label' => 'サブタイトル', 'type' => 'text'],
    'author'   => ['label' => '著者・監督',   'type' => 'text'],
    'url'      => ['label' => 'URL',          'type' => 'text'],
    'start'    => ['label' => '開始日',       'type' => 'date'],
    'date'     => ['label' => '終了日',       'type' => 'date'],
    'tags'     => ['label' => 'タグ',         'type' => 'checkboxes', 'options' => $KANAME_TAGS],
    'media'    => ['label' => 'メディア',     'type' => 'select',
                   'options' => ['映像', '書籍'], 'default' => '映像'],
    'timeline' => ['label' => '時刻記録',     'type' => 'select',
                   'options' => ['あり', 'なし'], 'default' => 'なし'],
    'status'   => ['label' => '進捗',         'type' => 'select', 'options' => $KANAME_STATUS],
    // 時刻メモ専用の内部値（timeline.php が管理。edit.php では編集欄を出さない）
    'duration' => ['label' => '総再生時間',   'type' => 'hidden'],   // 秒
    'resume'   => ['label' => '中断位置',     'type' => 'hidden'],   // 秒（ここまで見た）
];

// --- 本文ブロックの定義 ----------------------------------------
define('KANAME_TIMELINE_HEADING', '## 時刻メモ');  // 時刻メモの見出し
define('KANAME_REVIEW_HEADING', '## 総評');  // 総評の見出し
define('KANAME_SCORE_KEY',      'score');    // 末尾 "score: N" の左側
define('KANAME_TAG_SEP',        ', ');       // tags を書き出すときの区切り
