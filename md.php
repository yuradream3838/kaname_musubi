<?php
// =============================================================
// md.php — 分解係(parse)と組立係(build)
//   両方とも config.php の $KANAME_FIELDS だけを見る。
//   parse したものを build すると元に戻る、が満たすべき約束。
// =============================================================
require_once __DIR__ . '/config.php';

// --- id を「6桁の数字だけ」に絞る（門の外を読まれないための関所）---
// list.php / edit.php が ?id=... を受け取ったら必ずこれを通す。
function kaname_safe_id($raw) {
    if (preg_match('/^\d{6}$/', (string)$raw)) {
        return $raw;
    }
    return null; // 6桁数字でなければ拒否
}

function kaname_path($id) {
    $safe = kaname_safe_id($id);
    if ($safe === null) return null;
    return KANAME_DATA_DIR . '/' . $safe . '.md';
}

// --- 分解：MD文字列 → 連想配列 ---------------------------------
// 返り値: ['fields'=>[...], 'timeline_notes'=>"...", 'review'=>"...", 'score'=>"N"]
function kaname_parse($text) {
    global $KANAME_FIELDS;

    $fields = [];
    foreach ($KANAME_FIELDS as $key => $def) {
        $fields[$key] = $def['default'] ?? '';
    }
    $review = '';
    $score  = '';

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $mode  = 'front';       // front → timeline → review の順で流れる
    $timelineLines = [];
    $reviewLines   = [];

    foreach ($lines as $line) {
        if ($mode === 'front') {
            // "## 時刻メモ" に当たったら時刻メモモードへ
            if (trim($line) === KANAME_TIMELINE_HEADING) {
                $mode = 'timeline';
                continue;
            }
            // "## 総評" に当たったら本文モードへ
            if (trim($line) === KANAME_REVIEW_HEADING) {
                $mode = 'review';
                continue;
            }
            // "key: 値" を拾う
            if (preg_match('/^([a-zA-Z_]+):\s?(.*)$/', $line, $m)) {
                $k = $m[1];
                $v = $m[2];
                if ($k === KANAME_SCORE_KEY) {
                    $score = $v;                 // score は末尾だが front で拾えたら拾う
                } elseif (array_key_exists($k, $fields)) {
                    $fields[$k] = $v;
                }
            }
            // それ以外の front 内の行は無視（空行など）
        } elseif ($mode === 'timeline') {
            // 時刻メモの途中で "## 総評" が来たら本文モードへ
            if (trim($line) === KANAME_REVIEW_HEADING) {
                $mode = 'review';
                continue;
            }
            $timelineLines[] = $line;
        } else { // review モード
            // 末尾の "score: N" は総評本文に含めず score へ
            if (preg_match('/^' . preg_quote(KANAME_SCORE_KEY, '/') . ':\s?(.*)$/', $line, $m)) {
                $score = $m[1];
                continue;
            }
            $reviewLines[] = $line;
        }
    }

    // 前後の空行を落とす
    $timelineNotes = trim(implode("\n", $timelineLines));
    $review        = trim(implode("\n", $reviewLines));

    return [
        'fields'         => $fields,
        'timeline_notes' => $timelineNotes,
        'review'         => $review,
        'score'          => $score,
    ];
}

// --- 組立：連想配列 → MD文字列 ---------------------------------
// $data は kaname_parse と同じ形。順序は $KANAME_FIELDS に従う。
function kaname_build($data) {
    global $KANAME_FIELDS;

    $out = [];
    // 頭の構造データ（定義順）
    foreach ($KANAME_FIELDS as $key => $def) {
        $val = isset($data['fields'][$key]) ? $data['fields'][$key] : '';
        $out[] = $key . ': ' . $val;
    }

    // 空行 → 時刻メモ（中身がある時だけ）
    $tn = isset($data['timeline_notes']) ? trim($data['timeline_notes']) : '';
    if ($tn !== '') {
        $out[] = '';
        $out[] = KANAME_TIMELINE_HEADING;
        $out[] = $tn;
    }

    // 空行 → 総評見出し → 本文
    $out[] = '';
    $out[] = KANAME_REVIEW_HEADING;
    $out[] = isset($data['review']) ? $data['review'] : '';

    // 空行 → 末尾 score
    $out[] = '';
    $out[] = KANAME_SCORE_KEY . ': ' . (isset($data['score']) ? $data['score'] : '');

    return implode("\n", $out) . "\n";
}

// =============================================================
// 追いかけ中シリーズの状態ファイル
//   中身は「視聴中シリーズの代表1話の6桁番号」を1行1つ並べただけ。
//   載っていれば視聴中、載っていなければそれ以外（同じ処理）。
// =============================================================
define('KANAME_WATCHING_FILE', KANAME_DATA_DIR . '/_watching.txt');

// 視聴中の番号の集合を読む → ['000106' => true, ...]
function kaname_watching_load() {
    $set = [];
    if (is_file(KANAME_WATCHING_FILE)) {
        foreach (file(KANAME_WATCHING_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $id = kaname_safe_id(trim($line));   // 6桁数字だけ通す関所
            if ($id !== null) $set[$id] = true;
        }
    }
    return $set;
}

// 集合をファイルに書き戻す（キーが番号）
function kaname_watching_save($set) {
    $ids = array_keys($set);
    sort($ids);
    file_put_contents(KANAME_WATCHING_FILE, implode("\n", $ids) . ($ids ? "\n" : ''));
}

// 1件トグル：載っていれば外す、なければ足す
function kaname_watching_toggle($id) {
    $id = kaname_safe_id($id);
    if ($id === null) return;
    $set = kaname_watching_load();
    if (isset($set[$id])) unset($set[$id]); else $set[$id] = true;
    kaname_watching_save($set);
}

// =============================================================
// 自動採番：6桁MDだけを見て「最大番号+1」を返す
//   ・_watching.txt など6桁でないファイルは対象外（list.phpと同じ関所）
//   ・確定番号のファイルが既にあれば、空くまで進める（重複を原理的に排除）
//   ・保存の瞬間に呼ぶ（開いた時点では確定しない＝飛び/掴みを防ぐ）
// =============================================================
function kaname_next_id() {
    $max = -1;
    foreach (glob(KANAME_DATA_DIR . '/*.md') as $fpath) {
        $base = basename($fpath, '.md');
        if (!preg_match('/^\d{6}$/', $base)) continue;
        $n = (int)$base;
        if ($n > $max) $max = $n;
    }
    $next = $max + 1;
    // 念のため：その番号が埋まっていたら空くまで進める
    while (is_file(KANAME_DATA_DIR . '/' . sprintf('%06d', $next) . '.md')) {
        $next++;
    }
    return sprintf('%06d', $next);
}
