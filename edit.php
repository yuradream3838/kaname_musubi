<?php
// =============================================================
// edit.php — 個別編集（門番の内側）
//   ・開くとき: MDを分解して各欄に配る
//   ・確定するとき: 各欄を集めて config の型どおりに組み立て直す
//   フォームが構造を握るので、頭の項目や区切りは壊れようがない。
// =============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/md.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- id の関所（6桁数字だけ通す）-------------------------------
$id = kaname_safe_id($_GET['id'] ?? ($_POST['id'] ?? ''));
if ($id === null) { http_response_code(400); exit('不正な番号です'); }
$path = kaname_path($id);
if (!$path || !is_file($path)) { http_response_code(404); exit('見つかりません'); }

global $KANAME_FIELDS, $KANAME_TAGS, $KANAME_SCORES;
$saved = false;

// --- 保存／記録を終える（どちらも今の入力内容を保存する）---------
if (($_POST['action'] ?? '') === 'save') {
    $isFinish = (($_POST['do'] ?? '') === 'finish');   // 「終える」ボタンか

    // 既存の中身を読み直す（hidden フィールドと時刻メモの保持に使う）
    $current = kaname_parse(file_get_contents($path));

    $data = ['fields' => [], 'review' => '', 'score' => ''];
    foreach ($KANAME_FIELDS as $key => $def) {
        if ($def['type'] === 'hidden') {
            // 編集欄に出さない内部値（中断位置・総再生時間）は既存値をそのまま保持
            $data['fields'][$key] = $current['fields'][$key] ?? '';
        } elseif ($def['type'] === 'checkboxes') {
            // 選ばれたタグを config の順に並べて区切り結合
            $picked = $_POST['f_' . $key] ?? [];
            if (!is_array($picked)) $picked = [];
            $ordered = array_values(array_intersect($def['options'], $picked));
            $data['fields'][$key] = implode(KANAME_TAG_SEP, $ordered);
        } else {
            $data['fields'][$key] = trim($_POST['f_' . $key] ?? '');
        }
    }
    $data['review'] = str_replace("\r\n", "\n", $_POST['review'] ?? '');
    $data['score']  = trim($_POST['score'] ?? '');
    // 時刻メモは編集画面に出すが編集はしない。既存の中身を保持する
    $data['timeline_notes'] = $current['timeline_notes'] ?? '';

    // 「終える」なら、今の入力に加えて status=終了・終了日=今日 を上書き
    if ($isFinish) {
        if (array_key_exists('status', $data['fields'])) {
            $data['fields']['status'] = '終了';
        }
        if (array_key_exists('date', $data['fields'])) {
            $data['fields']['date'] = date('Y-m-d');   // 今日
        }
        $finished = true;
    }

    file_put_contents($path, kaname_build($data));
    $saved = true;
}

// --- 読み込み（分解して各欄に配る）-----------------------------
$data = kaname_parse(file_get_contents($path));
$f = $data['fields'];
// 現在のタグを配列に（「, 」「,」どちらの区切りでも拾う）
$curTags = array_filter(array_map('trim', preg_split('/[,、，]/u', $f['tags'] ?? '')));
// 時刻メモを表示用に配列化（"- [mm:ss] 本文" → [時刻, 本文]）
$tlEntries = [];
foreach (preg_split('/\n/', $data['timeline_notes'] ?? '') as $line) {
    if (preg_match('/^-\s*\[([\d:]+)\]\s?(.*)$/u', $line, $m)) {
        $tlEntries[] = ['time' => $m[1], 'text' => $m[2]];
    }
}
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>編集 <?= h($id) ?> — かなめ結び</title>
<style>
:root{--ink:#1c1a17;--ink-soft:#6b655c;--ink-faint:#a8a196;--paper:#f6f3ec;--card:#fffdf8;--line:#e4ded2;--line-soft:#efeadf;--accent:#3a6b5f;--accent-soft:#e8f0ec;--star:#c8912e;--radius:10px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Hiragino Kaku Gothic ProN","Hiragino Sans",system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.7;padding:24px 16px 80px;max-width:760px;margin:0 auto}
.masthead{display:flex;align-items:baseline;justify-content:space-between;padding-bottom:14px;border-bottom:1.5px solid var(--ink);margin-bottom:22px}
.masthead h1{font-size:20px;font-weight:600;letter-spacing:.06em}
.masthead a{font-size:13px;color:var(--accent);text-decoration:none}
.saved{background:var(--accent-soft);color:var(--accent);font-size:13px;padding:8px 13px;border-radius:8px;margin-bottom:16px}
label.field{display:block;font-size:12px;color:var(--ink-soft);letter-spacing:.04em;margin:16px 0 5px}
input[type=text],input[type=number],select,textarea{font-family:inherit;font-size:14px;padding:9px 12px;width:100%;border:0.5px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
textarea{min-height:180px;line-height:1.8;resize:vertical}
.row{display:flex;gap:14px}
.row>div{flex:1}
.tags{display:flex;flex-wrap:wrap;gap:7px}
.tags label{font-size:12px;color:var(--ink-soft);background:var(--card);border:0.5px solid var(--line);border-radius:16px;padding:6px 12px;cursor:pointer;user-select:none}
.tags input{display:none}
.tags input:checked + span{color:var(--paper)}
.tags label:has(input:checked){background:var(--accent);border-color:var(--accent);color:var(--paper)}
.score-select{max-width:120px}
.bar{margin-top:26px;display:flex;gap:12px;align-items:center}
button{font-family:inherit;font-size:14px;font-weight:600;padding:11px 24px;border:none;border-radius:8px;background:var(--accent);color:#fff;cursor:pointer}
button:hover{opacity:.9}
.tl-box{background:var(--paper);border:0.5px solid var(--line);border-radius:8px;padding:4px 0;margin-top:5px}
.tl-row{display:flex;gap:12px;padding:8px 14px;border-bottom:0.5px solid var(--line-soft);font-size:14px}
.tl-row:last-child{border-bottom:none}
.tl-time{color:var(--accent);font-weight:600;font-variant-numeric:tabular-nums;min-width:52px}
.tl-text{color:var(--ink);white-space:pre-wrap;word-break:break-word}
.tl-empty{font-size:13px;color:var(--ink-faint);padding:10px 14px}
.tl-edit-link{display:inline-block;margin-top:8px;font-size:13px;color:var(--accent);text-decoration:none}
.tl-edit-link:hover{text-decoration:underline}
.finish-bar{margin-top:14px;padding-top:16px;border-top:0.5px solid var(--line)}
.finish-btn{background:var(--card);color:var(--ink-soft);border:0.5px solid var(--line)}
.finish-btn:hover{background:var(--paper);opacity:1;color:var(--ink)}
.finish-note{font-size:12px;color:var(--ink-faint);margin-top:6px}
</style></head><body>
<div class="masthead"><h1>番号 <?= h($id) ?></h1><a href="list.php">← 一覧へ</a></div>
<?php if ($saved): ?><div class="saved">保存しました。</div><?php endif; ?>

<form method="post">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= h($id) ?>">

  <?php foreach ($KANAME_FIELDS as $key => $def):
        if ($def['type'] === 'hidden') continue;   // 内部値は編集欄に出さない
        $val = $f[$key] ?? ''; ?>

    <?php if ($def['type'] === 'checkboxes'): ?>
      <label class="field"><?= h($def['label']) ?></label>
      <div class="tags">
        <?php foreach ($def['options'] as $opt): ?>
          <label><input type="checkbox" name="f_<?= h($key) ?>[]" value="<?= h($opt) ?>"<?= in_array($opt, $curTags, true) ? ' checked' : '' ?>><span><?= h($opt) ?></span></label>
        <?php endforeach; ?>
      </div>

    <?php elseif ($def['type'] === 'select'): ?>
      <label class="field"><?= h($def['label']) ?></label>
      <select name="f_<?= h($key) ?>">
        <?php foreach ($def['options'] as $opt): ?>
          <option value="<?= h($opt) ?>" <?= (string)$opt === (string)$val ? 'selected' : '' ?>>
            <?= $opt === '' ? '（なし）' : h($opt) ?></option>
        <?php endforeach; ?>
      </select>

    <?php elseif ($def['type'] === 'number'): ?>
      <label class="field"><?= h($def['label']) ?></label>
      <input type="number" name="f_<?= h($key) ?>" value="<?= h($val) ?>">

    <?php elseif ($def['type'] === 'date'): ?>
      <label class="field"><?= h($def['label']) ?></label>
      <?php if ($val === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)): ?>
        <input type="date" name="f_<?= h($key) ?>" value="<?= h($val) ?>">
      <?php else: /* 旧形式(例 2026/08/01)は値を失わないよう文字入力で温存 */ ?>
        <input type="text" name="f_<?= h($key) ?>" value="<?= h($val) ?>" placeholder="YYYY-MM-DD">
      <?php endif; ?>

    <?php else: ?>
      <label class="field"><?= h($def['label']) ?></label>
      <input type="text" name="f_<?= h($key) ?>" value="<?= h($val) ?>">
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if (!empty($tlEntries)): ?>
    <label class="field">時刻別の感想</label>
    <div class="tl-box">
      <?php foreach ($tlEntries as $e): ?>
        <div class="tl-row"><span class="tl-time"><?= h($e['time']) ?></span><span class="tl-text"><?= h($e['text']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <a class="tl-edit-link" href="timeline.php?id=<?= h($id) ?>">時刻メモを直す（本文の修正・削除）</a>
  <?php elseif (($f['timeline'] ?? '') === 'あり'): ?>
    <label class="field">時刻別の感想</label>
    <div class="tl-empty">まだ記録がありません。<a href="timeline.php?id=<?= h($id) ?>">時刻メモ画面で刻む</a></div>
  <?php endif; ?>

  <label class="field">総評</label>
  <textarea name="review"><?= h($data['review']) ?></textarea>

  <label class="field">score</label>
  <select name="score" class="score-select">
    <?php foreach ($KANAME_SCORES as $s): ?>
      <option value="<?= h($s) ?>" <?= (string)$s === (string)$data['score'] ? 'selected' : '' ?>><?= h($s) ?></option>
    <?php endforeach; ?>
  </select>

  <div class="bar"><button type="submit" name="do" value="save">確定して保存</button></div>

  <div class="finish-bar">
  <?php if (($f['status'] ?? '') === '終了'): ?>
    <p class="finish-note">この記録は「終了」です<?= (($f['date'] ?? '') !== '') ? '（終了日 ' . h($f['date']) . '）' : '' ?>。もう一度終えると、今の入力内容で終了日を今日に更新します。</p>
    <button type="submit" name="do" value="finish" class="finish-btn"
      onclick="return confirm('今の入力内容で保存し、終了日を今日に更新します。よろしいですか？');">今の内容で終了を更新</button>
  <?php else: ?>
    <button type="submit" name="do" value="finish" class="finish-btn"
      onclick="return confirm('今の入力内容で保存し、進捗を終了・終了日を今日にします。よろしいですか？');">記録を終える（今の内容を保存・終了日を今日に）</button>
    <p class="finish-note">押すと、いま編集中の内容がそのまま保存され、進捗が「終了」・終了日に今日が入ります。保存を押していなくても入力は失われません。</p>
  <?php endif; ?>
  </div>
</form>
</body></html>
