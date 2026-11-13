<?php
// =============================================================
// timeline.php — 時刻メモの記録（門番の内側・サーバー保存版）
//   ?id=NNNNNN を受け取り、その番号のMDを開く。
//   タイマーで刻んだ反応を "- [mm:ss] 本文" として ## 時刻メモ に、
//   総評を ## 総評、星を末尾 score に入れて保存する。
//   front matter（シリーズ名・タグ・media・timeline 等）は触らず丸ごと保持。
//
//   保存は自動：入力のたびに fetch で自分自身へ POST(action=save_timeline)。成功で {ok:true}。
//   一時停止したとき・画面を離れるときも、その時刻を中断位置として保存する。
// =============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/md.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- id の関所 -------------------------------------------------
$id = kaname_safe_id($_GET['id'] ?? ($_POST['id'] ?? ''));
if ($id === null) { http_response_code(400); exit('不正な番号です'); }
$path = kaname_path($id);
if (!$path || !is_file($path)) { http_response_code(404); exit('見つかりません'); }

// =============================================================
// 保存（fetch POST）：front matter は元のまま、時刻メモ/総評/score を差し替え
// =============================================================
if (($_POST['action'] ?? '') === 'save_timeline') {
    header('Content-Type: application/json; charset=utf-8');

    // 元のMDを読み、front matter（fields）はそのまま使う
    $data = kaname_parse(file_get_contents($path));

    // 時刻メモ：クライアントから来た "- [mm:ss] 本文" の行群をそのまま入れる
    $memo = $_POST['timeline_notes'] ?? '';
    $data['timeline_notes'] = str_replace("\r\n", "\n", trim($memo));

    // 総評・score
    $data['review'] = str_replace("\r\n", "\n", trim($_POST['review'] ?? ''));
    $score = trim($_POST['score'] ?? '');
    $data['score'] = ($score === '') ? $data['score'] : $score;

    // timeline: あり を確定（時刻メモを持つ記録なので）
    if (isset($data['fields']['timeline'])) {
        $data['fields']['timeline'] = 'あり';
    }

    // 中断位置（ここまで見た）と総再生時間（秒）を保存。空なら触らない。
    if (array_key_exists('resume', $data['fields']) && isset($_POST['resume']) && $_POST['resume'] !== '') {
        $data['fields']['resume'] = (string)(int)$_POST['resume'];
    }
    if (array_key_exists('duration', $data['fields']) && isset($_POST['duration']) && $_POST['duration'] !== '') {
        $data['fields']['duration'] = (string)(int)$_POST['duration'];
    }

    $ok = file_put_contents($path, kaname_build($data));
    echo json_encode(['ok' => $ok !== false], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// 画面表示：既存の front matter と、既存の時刻メモ／総評／score を配る
// =============================================================
$data = kaname_parse(file_get_contents($path));
$f = $data['fields'];

// 作品名の見出し（series + episode + subtitle を組み立て）
$titleParts = [];
if (($f['series'] ?? '') !== '')   $titleParts[] = $f['series'];
if (($f['episode'] ?? '') !== '')  $titleParts[] = '第' . $f['episode'] . '話';
if (($f['subtitle'] ?? '') !== '') $titleParts[] = $f['subtitle'];
$workTitle = implode(' ', $titleParts);

// 既存の時刻メモを JS 用に配列化：各行 "- [mm:ss] 本文" → {t, text}
$existingEntries = [];
foreach (preg_split('/\n/', $data['timeline_notes']) as $line) {
    if (preg_match('/^-\s*\[(\d+):(\d{2})(?::(\d{2}))?\]\s?(.*)$/u', $line, $m)) {
        if (isset($m[3]) && $m[3] !== '') {
            $t = ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)$m[3];
        } else {
            $t = ((int)$m[1]) * 60 + (int)$m[2];
        }
        $existingEntries[] = ['t' => $t, 'text' => $m[4]];
    }
}

$boot = json_encode([
    'id'       => $id,
    'title'    => $workTitle,
    'tags'     => $f['tags'] ?? '',
    'entries'  => $existingEntries,
    'review'   => $data['review'],
    'score'    => (int)$data['score'],
    'resume'   => ($f['resume']   ?? '') === '' ? 0 : (int)$f['resume'],   // 中断位置（秒）
    'duration' => ($f['duration'] ?? '') === '' ? 0 : (int)$f['duration'], // 総再生時間（秒）
], JSON_UNESCAPED_UNICODE);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>記録 <?= h($id) ?> — 観ながら、まとめずに</title>
<style>
  :root{
    --ink:#1c1a17;--ink-soft:#6b655c;--ink-faint:#a8a196;
    --paper:#f6f3ec;--card:#fffdf8;--line:#e4ded2;--line-soft:#efeadf;
    --accent:#3a6b5f;--accent-soft:#e8f0ec;--star:#c8912e;--radius:10px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{-webkit-text-size-adjust:100%}
  body{font-family:"Hiragino Kaku Gothic ProN","Hiragino Sans",system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.7;padding:24px 16px 80px;max-width:760px;margin:0 auto}
  .masthead{display:flex;align-items:baseline;justify-content:space-between;padding-bottom:14px;border-bottom:1.5px solid var(--ink);margin-bottom:22px}
  .masthead h1{font-size:19px;font-weight:600;letter-spacing:.04em}
  .masthead a{font-size:13px;color:var(--accent);text-decoration:none}
  .work{background:var(--card);border:0.5px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:18px}
  .work-label{font-size:11px;color:var(--ink-faint);letter-spacing:.08em;margin-bottom:3px}
  .work-title{font-size:17px;font-weight:600;color:var(--ink);padding:2px 0}
  .work-tags{font-size:13px;color:var(--ink-soft);margin-top:8px}
  .timer-bar{display:flex;align-items:center;gap:14px;background:var(--ink);color:#f6f3ec;border-radius:var(--radius);padding:12px 16px;margin-bottom:14px}
  .clock{font-variant-numeric:tabular-nums;font-size:26px;font-weight:600;letter-spacing:.02em;min-width:78px}
  .clock.paused{color:var(--ink-faint)}
  .timer-bar.stopped{background:#7a3428}
  .timer-bar.stopped .clock{color:#f6d9d0}
  .stop-flag{display:none;font-size:12px;font-weight:600;letter-spacing:.08em;color:#f6d9d0;background:rgba(246,217,208,.16);padding:4px 10px;border-radius:6px;animation:blink 1.1s ease-in-out infinite}
  .timer-bar.stopped .stop-flag{display:inline-block}
  @keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
  body.stopped{background:#f0e5e1}
  .timer-btns{display:flex;gap:8px;margin-left:auto}
  .tbtn{font-family:inherit;font-size:13px;color:#f6f3ec;background:transparent;border:0.5px solid rgba(246,243,236,.35);border-radius:7px;padding:7px 14px;cursor:pointer;transition:background .12s}
  .tbtn:hover{background:rgba(246,243,236,.12)}
  .tbtn.primary{background:var(--accent);border-color:var(--accent)}
  .tbtn.primary:hover{background:#2f574d}
  .dur-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:-4px 2px 10px}
  .dur-label{font-size:12px;color:var(--ink-soft);font-weight:600}
  .dur-input{font-family:inherit;font-size:14px;padding:7px 11px;border:0.5px solid var(--line);border-radius:7px;background:var(--card);color:var(--ink);width:120px;text-align:center}
  .dur-input:focus{outline:none;border-color:var(--accent)}
  .dur-readout{font-size:13px;color:var(--accent);font-variant-numeric:tabular-nums}
  .dur-readout.over{color:#b4532e}
  .timer-hint{font-size:11px;color:var(--ink-faint);margin:-6px 2px 16px;line-height:1.7}
  .jot-wrap{position:relative;margin-bottom:10px}
  .jot{width:100%;font-family:inherit;font-size:16px;line-height:1.6;color:var(--ink);background:var(--card);border:0.5px solid var(--line);border-radius:var(--radius);padding:13px 15px;resize:none;min-height:52px}
  .jot:focus{outline:none;border-color:var(--accent)}
  .jot::placeholder{color:var(--ink-faint)}
  .jot-meta{display:flex;justify-content:space-between;align-items:center;margin:6px 4px 0;font-size:12px;color:var(--ink-faint)}
  .jot-time-badge{font-variant-numeric:tabular-nums;color:var(--accent);font-weight:600;background:var(--accent-soft);padding:2px 8px;border-radius:6px}
  .timeline{margin-top:26px}
  .timeline-head{display:flex;align-items:baseline;gap:10px;margin-bottom:12px;padding-bottom:8px;border-bottom:0.5px solid var(--line)}
  .timeline-head h2{font-size:14px;font-weight:600;letter-spacing:.04em}
  .timeline-head .count{font-size:12px;color:var(--ink-faint)}
  .entry{display:flex;gap:14px;padding:11px 2px;border-bottom:0.5px solid var(--line-soft);animation:fadein .25s ease}
  @keyframes fadein{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
  .entry-time{font-variant-numeric:tabular-nums;font-size:13px;font-weight:600;color:var(--accent);min-width:52px;padding-top:2px;cursor:pointer}
  .entry-time:hover{text-decoration:underline}
  .entry-body{flex:1;font-size:15px;color:var(--ink);white-space:pre-wrap;word-break:break-word;cursor:text;border-radius:5px}
  .entry-body:hover{background:var(--accent-soft)}
  .entry-edit{flex:1;font-family:inherit;font-size:15px;line-height:1.5;color:var(--ink);background:var(--card);border:0.5px solid var(--accent);border-radius:6px;padding:6px 9px;resize:none;overflow:hidden}
  .entry-edit:focus{outline:none}
  .entry-del{color:var(--ink-faint);cursor:pointer;font-size:15px;opacity:0;transition:opacity .12s;padding:0 4px}
  .entry:hover .entry-del{opacity:1}
  .entry-del:hover{color:#a04a3a}
  .empty{text-align:center;color:var(--ink-faint);font-size:14px;padding:34px 20px;border:1px dashed var(--line);border-radius:var(--radius)}
  .stars{display:flex;gap:2px}
  .star{font-size:22px;color:var(--line);cursor:pointer;transition:color .12s,transform .12s;user-select:none;line-height:1}
  .star:hover{transform:scale(1.15)}
  .star.on{color:var(--star)}
  .footer-actions{display:flex;gap:10px;align-items:center;margin-top:24px;padding-top:18px;border-top:0.5px solid var(--line)}
  .save-state{font-size:13px;color:var(--ink-soft)}
  .save-state.ok{color:var(--accent)}
  .save-state.err{color:#a04a3a}
  @media (max-width:520px){body{padding:18px 12px 70px}.clock{font-size:22px;min-width:64px}}
</style>
</head>
<body>
  <div class="masthead">
    <h1>記録 <span style="font-size:12px;color:var(--ink-soft);font-weight:400">番号 <?= h($id) ?></span></h1>
    <a href="list.php">← 一覧へ</a>
  </div>

  <div class="work">
    <div class="work-label">作品</div>
    <div class="work-title" id="workTitle"></div>
    <div class="work-tags" id="workTags"></div>
  </div>

  <div class="timer-bar" id="timerBar">
    <span class="clock paused" id="clock">00:00</span>
    <span class="stop-flag">■ 停止中</span>
    <div class="timer-btns">
      <button class="tbtn primary" id="playBtn">再生と同時に開始</button>
      <button class="tbtn" id="resetBtn">戻す</button>
    </div>
  </div>
  <div class="dur-bar">
    <label class="dur-label">総再生時間</label>
    <input type="text" id="durInput" class="dur-input" placeholder="例 2:30:00 / 45:00">
    <span class="dur-readout" id="durReadout"></span>
  </div>
  <div class="timer-hint">再生と同時に <b>Tabキー</b> で開始。広告が来たら <b>Tab</b> で止める・戻す（本編内の経過時間だけを刻みます）。総再生時間を入れると「残り」も分かります。入力は自動で保存され、一時停止したときや画面を離れたときの時刻を中断位置として覚えて、次に開くとそこから再開します。</div>

  <div class="jot-wrap">
    <textarea class="jot" id="jot" rows="1" placeholder="思ったことをそのまま。まとめなくていい。Enterで刻む（Shift+Enterで改行）"></textarea>
  </div>
  <div class="jot-meta">
    <span class="jot-time-badge" id="jotTime">00:00</span>
    <span>状況は書かなくていい — 時刻が場面を覚えている</span>
  </div>

  <div class="timeline">
    <div class="timeline-head"><h2>刻んだ反応</h2><span class="count" id="count">0件</span></div>
    <div id="entries"><div class="empty">再生を開始して、最初のひとことを刻もう。</div></div>
  </div>

  <div style="margin-top:26px">
    <div class="timeline-head"><h2>総評</h2><span class="count">観終わって、まとめる</span></div>
    <textarea id="summary" placeholder="作品全体をどう受け取ったか。ここは自由に。"
      style="width:100%;font-family:inherit;font-size:16px;line-height:1.7;color:var(--ink);background:var(--card);border:0.5px solid var(--line);border-radius:var(--radius);padding:13px 15px;resize:vertical;min-height:120px"></textarea>
  </div>

  <div style="margin-top:22px">
    <div class="work-label">評価</div>
    <div class="stars" id="stars">
      <span class="star" data-v="1">★</span><span class="star" data-v="2">★</span><span class="star" data-v="3">★</span><span class="star" data-v="4">★</span><span class="star" data-v="5">★</span>
    </div>
  </div>

  <div class="footer-actions">
    <span class="save-state" id="saveState">入力すると自動で保存されます</span>
  </div>

<script>
(function(){
  var BOOT = <?= $boot ?>;
  var elapsed = BOOT.resume || 0;    // 中断位置から再開
  var duration = BOOT.duration || 0; // 総再生時間（秒・0なら未設定）
  var running=false, tickId=null, started=false;
  var entries = BOOT.entries.slice();
  var starV = BOOT.score || 0;

  var clock=document.getElementById('clock');
  var jotTime=document.getElementById('jotTime');
  var durInput=document.getElementById('durInput');
  var durReadout=document.getElementById('durReadout');

  // 作品ヘッダ
  document.getElementById('workTitle').textContent = BOOT.title || '（無題）';
  document.getElementById('workTags').textContent = BOOT.tags ? ('タグ： '+BOOT.tags) : '';
  document.getElementById('summary').value = BOOT.review || '';

  function fmt(s){
    s=Math.max(0, Math.floor(s));
    var h=Math.floor(s/3600), m=Math.floor((s%3600)/60), ss=s%60;
    return (h>0? h+':' :'') + String(m).padStart(2,'0') +':'+ String(ss).padStart(2,'0');
  }
  // "2:30:00" / "45:00" / "90" を秒に
  function parseDur(str){
    str=(str||'').trim(); if(str==='') return 0;
    if(/^\d+$/.test(str)) return parseInt(str,10);           // 秒だけ
    var p=str.split(':').map(function(x){return parseInt(x,10);});
    if(p.some(isNaN)) return 0;
    if(p.length===3) return p[0]*3600+p[1]*60+p[2];
    if(p.length===2) return p[0]*60+p[1];
    return 0;
  }
  function render(){
    clock.textContent=fmt(elapsed);
    jotTime.textContent=fmt(elapsed);
    // 総再生時間があれば「残り／全体」を出す
    if(duration>0){
      var remain=duration-elapsed;
      durReadout.textContent='残り '+fmt(remain<0?0:remain)+' ／ 全体 '+fmt(duration);
      durReadout.classList.toggle('over', elapsed>duration);
    } else {
      durReadout.textContent='';
      durReadout.classList.remove('over');
    }
  }
  // 総再生時間の初期表示と入力反映
  if(duration>0) durInput.value=fmt(duration);
  durInput.addEventListener('input',function(){ duration=parseDur(durInput.value); render(); touch(); });
  render();

  var playBtn=document.getElementById('playBtn');
  var timerBar=document.getElementById('timerBar');
  function start(){
    running=true; started=true;
    clock.classList.remove('paused'); timerBar.classList.remove('stopped');
    document.body.classList.remove('stopped'); playBtn.textContent='一時停止';
    tickId=setInterval(function(){ elapsed++; render(); },1000);
  }
  function pause(){
    running=false; clock.classList.add('paused'); timerBar.classList.add('stopped');
    document.body.classList.add('stopped'); playBtn.textContent='再開'; clearInterval(tickId);
    if(elapsed!==savedResume) touch();   // 止めた時刻を中断位置として保存
  }
  playBtn.addEventListener('click',function(){ running?pause():start(); });
  document.getElementById('resetBtn').addEventListener('click',function(){
    pause(); elapsed=0; render(); started=false;
    timerBar.classList.remove('stopped'); document.body.classList.remove('stopped');
    playBtn.textContent='再生と同時に開始';
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Tab'){ e.preventDefault(); if(!started){ start(); } else { running?pause():start(); } }
  });

  // 星
  var starEls=document.querySelectorAll('#stars .star');
  function paintStars(){ starEls.forEach(function(s){ s.classList.toggle('on', +s.dataset.v<=starV); }); }
  starEls.forEach(function(st){
    st.addEventListener('click',function(){ starV=+st.dataset.v; paintStars(); touch(); });
  });
  paintStars();

  // 殴り書き
  var jot=document.getElementById('jot');
  jot.addEventListener('input',function(){ jot.style.height='auto'; jot.style.height=jot.scrollHeight+'px'; });
  jot.addEventListener('keydown',function(e){
    if(e.key==='Enter' && !e.shiftKey){
      e.preventDefault();
      var txt=jot.value.trim(); if(!txt) return;
      entries.push({t:elapsed, text:txt});
      jot.value=''; jot.style.height='auto'; draw(); touch();
    }
  });

  function draw(){
    var wrap=document.getElementById('entries');
    document.getElementById('count').textContent=entries.length+'件';
    if(entries.length===0){ wrap.innerHTML='<div class="empty">再生を開始して、最初のひとことを刻もう。</div>'; return; }
    wrap.innerHTML='';
    entries.forEach(function(en,i){
      var row=document.createElement('div'); row.className='entry';
      var tm=document.createElement('div'); tm.className='entry-time'; tm.textContent=fmt(en.t);
      tm.title='この場面に戻す'; tm.addEventListener('click',function(){ elapsed=en.t; render(); });
      var bd=document.createElement('div'); bd.className='entry-body'; bd.textContent=en.text;
      bd.title='タップして本文を直す';
      // タップで本文を編集（打ち間違いの修正）
      bd.addEventListener('click',function(){ startEditEntry(i, bd); });
      var del=document.createElement('div'); del.className='entry-del'; del.textContent='×';
      del.addEventListener('click',function(){ entries.splice(i,1); draw(); touch(); });
      row.appendChild(tm); row.appendChild(bd); row.appendChild(del);
      wrap.appendChild(row);
    });
  }
  // 本文をその場でテキスト編集にする
  function startEditEntry(i, bd){
    var ta=document.createElement('textarea');
    ta.className='entry-edit'; ta.value=entries[i].text; ta.rows=1;
    bd.replaceWith(ta);
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
    ta.style.height='auto'; ta.style.height=ta.scrollHeight+'px';
    ta.addEventListener('input',function(){ ta.style.height='auto'; ta.style.height=ta.scrollHeight+'px'; });
    var done=false;
    var commit=function(){
      if(done) return; done=true;
      var v=ta.value.trim();
      if(v==='') { entries.splice(i,1); touch(); }     // 空にしたら削除
      else if(v!==entries[i].text) { entries[i].text=v; touch(); }
      draw();
    };
    // Enterで確定（Shift+Enterで改行）、フォーカスが外れても確定
    ta.addEventListener('keydown',function(e){
      if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); commit(); }
      else if(e.key==='Escape'){ done=true; draw(); }   // 取り消し
    });
    ta.addEventListener('blur',commit);
  }
  draw();

  // 時刻メモを "- [mm:ss] 本文" の行群に組み立てる
  function buildMemo(){
    return entries.map(function(en){ return '- ['+fmt(en.t)+'] '+en.text; }).join('\n');
  }

  // 自動保存：入力が止まって0.8秒で fetch POST。送信は1本ずつ順番に
  var saveState=document.getElementById('saveState');
  var saveUrl=location.pathname+'?id='+encodeURIComponent(BOOT.id);
  var saveTimer=null, inflight=false, again=false, dirty=false, retried=false;
  var savedResume=BOOT.resume||0;
  function show(cls,txt){ saveState.className='save-state'+(cls?' '+cls:''); saveState.textContent=txt; }
  function saveBody(){
    var body=new URLSearchParams();
    body.set('action','save_timeline');
    body.set('id', BOOT.id);
    body.set('timeline_notes', buildMemo());
    body.set('review', document.getElementById('summary').value);
    body.set('score', String(starV));
    body.set('resume', String(elapsed));       // 保存した瞬間のタイマー値＝中断位置
    body.set('duration', String(duration||0)); // 総再生時間
    return body;
  }
  function touch(){
    dirty=true; clearTimeout(saveTimer); saveTimer=setTimeout(save,800);
    show('','未保存の変更…');
  }
  function save(keep){
    clearTimeout(saveTimer); saveTimer=null;
    if(inflight){ again=true; return; }
    if(!dirty) return;
    dirty=false; inflight=true; show('','保存中…');
    var body=saveBody();
    fetch(saveUrl, {
      method:'POST', credentials:'same-origin', keepalive:!!keep,
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body: body.toString()
    }).then(function(r){ return r.json(); })
      .then(function(j){
        if(!(j && j.ok)) throw new Error('ng');
        retried=false; savedResume=+body.get('resume');
        var d=new Date();
        show('ok','保存しました '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0'));
      })
      .catch(function(){
        dirty=true;
        show('err','保存できませんでした（ログインが切れていないか確認してください）');
        if(!retried){ retried=true; setTimeout(function(){ if(dirty&&!saveTimer) save(); },5000); }
      })
      .then(function(){ inflight=false; if(again){ again=false; save(); } });
  }
  // 画面を離れるとき：未保存の変更か、進んだタイマー（中断位置）を送り切る
  function flush(){
    clearTimeout(saveTimer); saveTimer=null;
    if(started && elapsed!==savedResume) dirty=true;
    if(!dirty) return;
    if(inflight){ var b=saveBody(); if(navigator.sendBeacon(saveUrl, b)){ dirty=false; savedResume=+b.get('resume'); } }
    else save(true);
  }
  document.getElementById('summary').addEventListener('input',touch);
  document.addEventListener('visibilitychange',function(){ if(document.visibilityState==='hidden') flush(); });
  window.addEventListener('pagehide',flush);

  jot.focus();
})();
</script>
</body>
</html>
