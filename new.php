<?php
// =============================================================
// new.php — 新規追加（門番の内側）
//   モードは2つ：
//     next   … 既存シリーズの「次の話」。元話から series/tags/media/timeline を
//              コピペ、episode を +1、総評・時刻メモは空。
//     series … 新しいシリーズ。media と timeline を選ぶだけ、他は空。
//   番号は「保存を押した瞬間」に kaname_next_id() で確定してファイル生成し、
//   そのまま edit.php へ飛ばす（開いただけでは番号を掴まない）。
// =============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/md.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

global $KANAME_FIELDS;

// --- 空の $data を作る（全フィールドは config の default か空）--------
function kaname_blank_data() {
    global $KANAME_FIELDS;
    $fields = [];
    foreach ($KANAME_FIELDS as $key => $def) {
        $fields[$key] = $def['default'] ?? '';
    }
    return ['fields' => $fields, 'timeline_notes' => '', 'review' => '', 'score' => ''];
}

// =============================================================
// 保存（確定を押したとき）→ 番号を採番してファイル生成 → edit.php へ
// =============================================================
if (($_POST['action'] ?? '') === 'create') {
    $mode = $_POST['mode'] ?? '';
    $data = kaname_blank_data();

    if ($mode === 'next') {
        // 元話（コピー元）から引き継ぐ
        $src = kaname_safe_id($_POST['src'] ?? '');
        if ($src === null) { http_response_code(400); exit('元の番号が不正です'); }
        $srcPath = kaname_path($src);
        if (!$srcPath || !is_file($srcPath)) { http_response_code(404); exit('元が見つかりません'); }
        $s = kaname_parse(file_get_contents($srcPath));

        // 引き継ぎ（固定）：シリーズ名・メディア
        $data['fields']['series'] = $s['fields']['series'];
        $data['fields']['media']  = $s['fields']['media'];

        // 編集可（フォームから来た値を使い、無ければコピー元/自動値）
        // 話数：初期値は「そのシリーズの最大 episode +1」。手で直せる。
        $maxEp = 0;
        foreach (glob(KANAME_DATA_DIR . '/*.md') as $fp) {
            $b = basename($fp, '.md');
            if (!preg_match('/^\d{6}$/', $b)) continue;
            $d = kaname_parse(file_get_contents($fp));
            if (($d['fields']['series'] ?? '') === $data['fields']['series']) {
                $ep = (int)($d['fields']['episode'] ?? 0);
                if ($ep > $maxEp) $maxEp = $ep;
            }
        }
        $epPosted = trim($_POST['f_episode'] ?? '');
        $data['fields']['episode'] = ($epPosted !== '') ? $epPosted : (string)($maxEp + 1);

        // サブタイテル：新しい話ごとに違うので初期値は空
        $data['fields']['subtitle'] = trim($_POST['f_subtitle'] ?? '');

        // タグ：チェックボックスで選び直せる。初期はコピー元。
        $picked = $_POST['f_tags'] ?? null;
        if (is_array($picked)) {
            $opts = $KANAME_FIELDS['tags']['options'] ?? [];
            $ordered = array_values(array_intersect($opts, $picked));
            $data['fields']['tags'] = implode(KANAME_TAG_SEP, $ordered);
        } else {
            $data['fields']['tags'] = $s['fields']['tags'];   // 未送信ならコピー元のまま
        }

        // 時刻記録：その場で選んだ値で確定（飛び先もこれで決まる）
        $tl = $_POST['f_timeline'] ?? '';
        $tlOpts = $KANAME_FIELDS['timeline']['options'] ?? [];
        $data['fields']['timeline'] = in_array($tl, $tlOpts, true) ? $tl : $s['fields']['timeline'];

    } elseif ($mode === 'series') {
        // 新しいシリーズ：media と timeline だけ受ける
        $media = $_POST['media'] ?? '';
        $tl    = $_POST['timeline'] ?? '';
        $mediaOpts = $KANAME_FIELDS['media']['options'] ?? [];
        $tlOpts    = $KANAME_FIELDS['timeline']['options'] ?? [];
        $data['fields']['media']    = in_array($media, $mediaOpts, true) ? $media
                                      : ($KANAME_FIELDS['media']['default'] ?? '');
        $data['fields']['timeline'] = in_array($tl, $tlOpts, true) ? $tl
                                      : ($KANAME_FIELDS['timeline']['default'] ?? '');
        // series 名は空のまま → edit.php で入力してもらう
    } else {
        http_response_code(400); exit('モードが不正です');
    }

    // 作成時に開始日へ今日の日付を入れる（start キー）
    if (array_key_exists('start', $data['fields'])) {
        $data['fields']['start'] = date('Y-m-d');
    }

    // ★保存の瞬間に採番して生成 → 編集画面へ
    $newId = kaname_next_id();
    $newPath = kaname_path($newId);
    file_put_contents($newPath, kaname_build($data));

    // 「視聴中として登録」が押されていたら、この代表番号を視聴中に加える
    if (($_POST['watch'] ?? '') === '1') {
        $set = kaname_watching_load();
        $set[$newId] = true;           // 新規番号なので確実に追加（トグルでなく加算）
        kaname_watching_save($set);
    }

    // 時刻記録ありの記録は、作った直後に時刻メモ画面へ直行
    if (($data['fields']['timeline'] ?? '') === 'あり') {
        header('Location: timeline.php?id=' . $newId);
    } else {
        header('Location: edit.php?id=' . $newId);
    }
    exit;
}

// =============================================================
// 画面表示：next（確認）と series（選択）の2種
// =============================================================
$mode = $_GET['mode'] ?? '';
$srcInfo = null;
if ($mode === 'next') {
    $src = kaname_safe_id($_GET['src'] ?? '');
    if ($src === null) { http_response_code(400); exit('元の番号が不正です'); }
    $srcPath = kaname_path($src);
    if (!$srcPath || !is_file($srcPath)) { http_response_code(404); exit('元が見つかりません'); }
    $s = kaname_parse(file_get_contents($srcPath));
    // 表示用に「次の話数」を先に計算（保存時にも再計算するので参考値）
    $maxEp = 0;
    foreach (glob(KANAME_DATA_DIR . '/*.md') as $fp) {
        $b = basename($fp, '.md');
        if (!preg_match('/^\d{6}$/', $b)) continue;
        $d = kaname_parse(file_get_contents($fp));
        if (($d['fields']['series'] ?? '') === ($s['fields']['series'] ?? '')) {
            $ep = (int)($d['fields']['episode'] ?? 0);
            if ($ep > $maxEp) $maxEp = $ep;
        }
    }
    $srcInfo = [
        'src'      => $src,
        'series'   => $s['fields']['series'],
        'tags'     => $s['fields']['tags'],
        'media'    => $s['fields']['media'],
        'timeline' => $s['fields']['timeline'],
        'nextEp'   => $maxEp + 1,
    ];
    // 現在のタグを配列に（「, 」「,」どちらの区切りでも拾う）
    $srcTags = array_filter(array_map('trim', preg_split('/[,、，]/u', $s['fields']['tags'] ?? '')));
}
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>新規追加 — かなめ結び</title>
<style>
:root{--ink:#1c1a17;--ink-soft:#6b655c;--ink-faint:#a8a196;--paper:#f6f3ec;--card:#fffdf8;--line:#e4ded2;--line-soft:#efeadf;--accent:#3a6b5f;--accent-soft:#e8f0ec;--radius:10px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Hiragino Kaku Gothic ProN","Hiragino Sans",system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.7;padding:24px 16px 80px;max-width:640px;margin:0 auto}
.masthead{display:flex;align-items:baseline;justify-content:space-between;padding-bottom:14px;border-bottom:1.5px solid var(--ink);margin-bottom:22px}
.masthead h1{font-size:20px;font-weight:600;letter-spacing:.06em}
.masthead a{font-size:13px;color:var(--accent);text-decoration:none}
.card{background:var(--card);border:0.5px solid var(--line);border-radius:12px;padding:18px 18px 22px;margin-bottom:20px}
.card h2{font-size:15px;font-weight:600;margin-bottom:6px}
.card .hint{font-size:12px;color:var(--ink-soft);margin-bottom:14px}
label.field{display:block;font-size:12px;color:var(--ink-soft);letter-spacing:.04em;margin:12px 0 5px}
select{font-family:inherit;font-size:14px;padding:9px 12px;width:100%;border:0.5px solid var(--line);border-radius:8px;background:var(--paper);color:var(--ink)}
select:focus{outline:none;border-color:var(--accent)}
.row{display:flex;gap:14px}
.row>div{flex:1}
.kv{font-size:13px;color:var(--ink-soft);margin:4px 0}
.kv b{color:var(--ink);font-weight:600}
input[type=text],input[type=number]{font-family:inherit;font-size:14px;padding:9px 12px;width:100%;border:0.5px solid var(--line);border-radius:8px;background:var(--paper);color:var(--ink)}
input:focus{outline:none;border-color:var(--accent)}
.tags{display:flex;flex-wrap:wrap;gap:7px}
.tags label{font-size:12px;color:var(--ink-soft);background:var(--paper);border:0.5px solid var(--line);border-radius:16px;padding:6px 12px;cursor:pointer;user-select:none;margin:0}
.tags input{display:none}
.tags label:has(input:checked){background:var(--accent);border-color:var(--accent);color:var(--paper)}
.fixed-note{font-size:12px;color:var(--ink-faint);margin:4px 0 2px}
.fixed-note b{color:var(--ink-soft);font-weight:600}
button{font-family:inherit;font-size:14px;font-weight:600;padding:11px 24px;border:none;border-radius:8px;background:var(--accent);color:#fff;cursor:pointer;margin-top:16px}
button:hover{opacity:.9}
.new-btns{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.watch-create{background:var(--card);color:var(--accent);border:0.5px solid var(--accent)}
.watch-create:hover{background:var(--accent-soft);opacity:1}
</style></head><body>
<div class="masthead"><h1>新規追加</h1><a href="list.php">← 一覧へ</a></div>

<?php if ($mode === 'next' && $srcInfo): ?>
  <div class="card">
    <h2>次の話を追加</h2>
    <p class="hint">シリーズ名とメディアは引き継ぎます。話数・サブタイトル・タグ・時刻記録はここで編集できます。時刻記録を「あり」にすると、作成後そのまま時刻メモ画面が開きます。</p>
    <p class="fixed-note">シリーズ：<b><?= h($srcInfo['series']) ?></b>　メディア：<b><?= h($srcInfo['media']) ?></b></p>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="mode" value="next">
      <input type="hidden" name="src" value="<?= h($srcInfo['src']) ?>">

      <div class="row">
        <div>
          <label class="field">話数</label>
          <input type="number" name="f_episode" value="<?= h($srcInfo['nextEp']) ?>">
        </div>
        <div>
          <label class="field">時刻記録</label>
          <select name="f_timeline">
            <?php foreach (($KANAME_FIELDS['timeline']['options'] ?? []) as $opt): ?>
              <option value="<?= h($opt) ?>"<?= ($opt === $srcInfo['timeline']) ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label class="field">サブタイトル</label>
      <input type="text" name="f_subtitle" value="" placeholder="この話のサブタイトル（任意）">

      <label class="field">タグ</label>
      <div class="tags">
        <?php foreach (($KANAME_FIELDS['tags']['options'] ?? []) as $opt): ?>
          <label><input type="checkbox" name="f_tags[]" value="<?= h($opt) ?>"<?= in_array($opt, $srcTags, true) ? ' checked' : '' ?>><?= h($opt) ?></label>
        <?php endforeach; ?>
      </div>

      <button type="submit">この内容で作成</button>
    </form>
  </div>

<?php else: ?>
  <div class="card">
    <h2>新しいシリーズを追加</h2>
    <p class="hint">メディアと時刻記録の有無を選ぶと、空のシリーズが1話ぶん作られ、編集画面が開きます。シリーズ名やタグは編集画面で入力してください。</p>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="mode" value="series">
      <div class="row">
        <div>
          <label class="field">メディア</label>
          <select name="media">
            <?php foreach (($KANAME_FIELDS['media']['options'] ?? []) as $opt): ?>
              <option value="<?= h($opt) ?>"<?= ($opt === ($KANAME_FIELDS['media']['default'] ?? '')) ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="field">時刻記録</label>
          <select name="timeline">
            <?php foreach (($KANAME_FIELDS['timeline']['options'] ?? []) as $opt): ?>
              <option value="<?= h($opt) ?>"<?= ($opt === ($KANAME_FIELDS['timeline']['default'] ?? '')) ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="new-btns">
        <button type="submit">作成して編集へ</button>
        <button type="submit" name="watch" value="1" class="watch-create">視聴中に登録して作成</button>
      </div>
    </form>
  </div>
<?php endif; ?>

</body></html>
