<?php
// =============================================================
// list.php — 全件一覧（門番の内側）
//   視聴中シリーズを上、それ以外を下。トグルで切り替え。
// =============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/md.php';

// --- トグル：視聴中の切り替え（6桁の関所を通してから）----------
if (($_POST['action'] ?? '') === 'toggle_watch') {
    kaname_watching_toggle($_POST['id'] ?? '');
    header('Location: list.php');   // PRG：再読込での二重送信を防ぐ
    exit;
}

$watching = kaname_watching_load();   // ['000106'=>true, ...]

$files = glob(KANAME_DATA_DIR . '/*.md');
$rows = [];
foreach ($files as $fpath) {
    $base = basename($fpath, '.md');
    if (!preg_match('/^\d{6}$/', $base)) continue;
    $d = kaname_parse(file_get_contents($fpath));
    $rows[] = [
        'id'       => $base,
        'series'   => $d['fields']['series'],
        'episode'  => (int)$d['fields']['episode'],
        'subtitle' => $d['fields']['subtitle'],
        'media'    => $d['fields']['media'],
        'tags'     => $d['fields']['tags'],
        'timeline' => $d['fields']['timeline'],
        'status'   => $d['fields']['status'] ?? '',
        'score'    => (int)$d['score'],
    ];
}

// 視聴中の番号 → その番号のMDが属するシリーズ名の集合
$watchingSeries = [];
foreach ($rows as $r) {
    if (isset($watching[$r['id']])) $watchingSeries[$r['series']] = true;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$json = json_encode([
    'rows' => $rows,
    'watchingSeries' => array_keys($watchingSeries),
    'tagOptions'   => $KANAME_TAGS,
    'mediaOptions' => $KANAME_FIELDS['media']['options'] ?? [],
], JSON_UNESCAPED_UNICODE);
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>かなめ結び — 編集一覧</title>
<style>
:root{--ink:#1c1a17;--ink-soft:#6b655c;--ink-faint:#a8a196;--paper:#f6f3ec;--card:#fffdf8;--line:#e4ded2;--line-soft:#efeadf;--accent:#3a6b5f;--accent-soft:#e8f0ec;--star:#c8912e;--book:#7a5a3a;--book-soft:#f0e9df;--radius:10px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Hiragino Kaku Gothic ProN","Hiragino Sans",system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.7;padding:24px 16px 80px;max-width:860px;margin:0 auto}
.masthead{display:flex;align-items:baseline;justify-content:space-between;padding-bottom:14px;border-bottom:1.5px solid var(--ink);margin-bottom:22px}
.masthead h1{font-size:20px;font-weight:600;letter-spacing:.06em}
.masthead a{font-size:13px;color:var(--accent);text-decoration:none}
.bar{display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap}
.bar .search{min-width:180px}
.search{flex:1;font-family:inherit;font-size:14px;padding:9px 13px;border:0.5px solid var(--line);border-radius:20px;background:var(--card);color:var(--ink)}
.search:focus{outline:none;border-color:var(--accent)}
.new-series{font-family:inherit;font-size:13px;font-weight:600;padding:9px 15px;border-radius:20px;background:var(--accent);color:#fff;text-decoration:none;white-space:nowrap}
.new-series:hover{opacity:.9}
.backup-btn{font-family:inherit;font-size:13px;font-weight:600;padding:9px 15px;border-radius:20px;background:var(--card);color:var(--ink-soft);border:0.5px solid var(--line);text-decoration:none;white-space:nowrap}
.backup-btn:hover{background:var(--paper);color:var(--ink)}
.add-ep{display:block;text-align:center;padding:10px 16px;font-size:13px;font-weight:600;color:var(--accent);text-decoration:none;border-bottom:0.5px solid var(--line-soft);background:var(--accent-soft)}
.add-ep:hover{background:var(--paper)}
.count{font-size:12px;color:var(--ink-faint);margin:0 2px 14px}
.filter{display:flex;gap:8px;margin:0 2px 16px;align-items:center}
.filter.wrap{flex-wrap:wrap}
.filter-label{font-size:11px;color:var(--ink-faint);letter-spacing:.06em;font-weight:600;margin-right:4px}
.chip.sm{font-size:11px;padding:5px 11px}
.flat-row{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:0.5px solid var(--line-soft);text-decoration:none;color:inherit}
.flat-row:hover{background:var(--paper)}
.flat-id{font-variant-numeric:tabular-nums;font-size:12px;color:var(--ink-faint);min-width:52px}
.flat-body{flex:1;min-width:0}
.flat-title{font-size:14px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.flat-meta{font-size:11px;color:var(--ink-soft);margin-top:2px}
.flat-stars{font-size:12px;color:var(--star);white-space:nowrap}
.chip{font-family:inherit;font-size:12px;font-weight:600;padding:6px 14px;border-radius:16px;background:var(--card);color:var(--ink-soft);border:0.5px solid var(--line);cursor:pointer}
.chip:hover{border-color:var(--accent);color:var(--accent)}
.chip.on{background:var(--accent);border-color:var(--accent);color:#fff}
.section-label{font-size:12px;color:var(--ink-faint);letter-spacing:.08em;font-weight:600;margin:22px 2px 12px;padding-bottom:8px;border-bottom:0.5px solid var(--line)}
.series-block{margin-bottom:10px;background:var(--card);border:0.5px solid var(--line);border-radius:12px;overflow:hidden}
.series-block.watching{border-color:var(--accent)}
.series-head{display:flex;align-items:center;gap:10px;padding:13px 16px;cursor:pointer;user-select:none}
.series-head:hover{background:var(--paper)}
.chevron{color:var(--ink-faint);font-size:12px;transition:transform .15s;width:12px}
.series-block.open .chevron{transform:rotate(90deg)}
.series-name{flex:1;font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.series-meta{font-size:12px;color:var(--ink-faint)}
.series-badge{font-size:10px;font-weight:600;padding:2px 7px;border-radius:5px}
.b-eizo{background:var(--accent-soft);color:var(--accent)}
.b-book{background:var(--book-soft);color:var(--book)}
.watch-btn{font-family:inherit;font-size:11px;font-weight:600;padding:5px 11px;border-radius:16px;border:0.5px solid var(--line);background:var(--card);color:var(--ink-soft);cursor:pointer;white-space:nowrap}
.watch-btn.on{background:var(--accent);border-color:var(--accent);color:#fff}
.eps{display:none;border-top:0.5px solid var(--line-soft)}
.series-block.open .eps{display:block}
.ep{display:flex;align-items:center;gap:12px;padding:10px 16px 10px 40px;border-bottom:0.5px solid var(--line-soft);text-decoration:none;color:inherit}
.ep:last-child{border-bottom:none}
.ep:hover{background:var(--paper)}
.ep-num{font-size:12px;font-weight:600;color:var(--accent);min-width:38px;font-variant-numeric:tabular-nums}
.ep-id{font-size:11px;color:var(--ink-faint);font-variant-numeric:tabular-nums;min-width:52px}
.ep-body{flex:1;min-width:0}
.ep-sub{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ep-stars{color:var(--star);font-size:12px}
.tl{font-size:9px;padding:1px 5px;border-radius:4px;background:var(--accent);color:#fff}
.empty{text-align:center;color:var(--ink-faint);font-size:13px;padding:24px}
</style></head><body>
<div class="masthead"><h1>かなめ結び<span style="font-size:12px;color:var(--ink-soft);font-weight:400"> 編集</span></h1><a href="?logout=1">ログアウト</a></div>
<div class="bar"><input class="search" id="search" placeholder="シリーズ名・サブタイトルで検索"><a class="new-series" href="new.php">＋新しいシリーズ</a><a class="backup-btn" href="backup.php" target="_blank" rel="noopener">⤓ 全件バックアップ</a></div>
<div class="count" id="count"></div>
<div class="filter" id="filter">
  <button class="chip on" data-f="all">すべて</button>
  <button class="chip" data-f="active">進行中</button>
  <button class="chip" data-f="done">終了</button>
</div>
<div class="filter" id="sortBar">
  <span class="filter-label">並び</span>
  <button class="chip on" data-s="series">シリーズ</button>
  <button class="chip" data-s="number">番号順</button>
</div>
<div class="filter wrap" id="mediaBar">
  <span class="filter-label">メディア</span>
</div>
<div class="filter wrap" id="tagBar">
  <span class="filter-label">タグ</span>
</div>
<div id="watchingArea"></div>
<div class="section-label" id="watchingLabel" style="display:none">視聴中</div>
<div class="section-label" id="restLabel" style="display:none">その他</div>
<div id="restArea"></div>

<!-- トグル送信用の隠しフォーム -->
<form id="toggleForm" method="post" style="display:none">
  <input type="hidden" name="action" value="toggle_watch">
  <input type="hidden" name="id" id="toggleId">
</form>

<script>
const PAYLOAD = <?= $json ?>;
const DATA = PAYLOAD.rows;
const WATCHING = new Set(PAYLOAD.watchingSeries);
let curSearch = "";
let curFilter = "all";   // all / active（進行中＝終了以外）/ done（終了）
let curSort = "series";  // series（シリーズまとめ）/ number（番号順フラット）
let selMedia = new Set(); // 選択中のメディア（空＝すべて）
let selTags  = new Set(); // 選択中のタグ（空＝すべて）
const TAG_OPTIONS   = PAYLOAD.tagOptions   || [];
const MEDIA_OPTIONS = PAYLOAD.mediaOptions || [];
function stars(n){return "\u2605".repeat(n)+"\u2606".repeat(5-n);}
function esc(s){return (s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));}

function passStatus(r){
  if(curFilter==="all") return true;
  if(curFilter==="done") return r.status==="終了";
  return r.status!=="終了";   // active＝終了していない（進行中・空を含む）
}
function rowTags(r){
  return (r.tags||"").split(/[,、，]/).map(s=>s.trim()).filter(Boolean);
}
function passMedia(r){
  if(selMedia.size===0) return true;       // 未選択＝すべて
  return selMedia.has(r.media);
}
function passTags(r){
  if(selTags.size===0) return true;        // 未選択＝すべて
  const ts=rowTags(r);
  for(const t of ts){ if(selTags.has(t)) return true; }  // いずれか一致（OR）
  return false;
}
function passSearch(r){
  if(!curSearch) return true;
  return (r.series+r.subtitle).toLowerCase().indexOf(curSearch.toLowerCase())>=0;
}
function passAll(r){ return passStatus(r)&&passMedia(r)&&passTags(r)&&passSearch(r); }

function group(){
  const f = DATA.filter(passAll);
  const s={};
  f.forEach(r=>{(s[r.series]=s[r.series]||[]).push(r);});
  const watching=[], rest=[];
  Object.entries(s).forEach(pair=>{
    (WATCHING.has(pair[0]) ? watching : rest).push(pair);
  });
  return {watching, rest, total:f.length};
}

function seriesBlock(name, eps){
  eps.sort((a,b)=>b.episode-a.episode);   // 最新話が上（降順）
  const media=eps[0].media, bc = media==="書籍"?"b-book":"b-eizo";
  const isW = WATCHING.has(name);
  // 視聴中トグルの代表番号は並び順に依らず安定させる（最小番号）
  const rep = eps.reduce((m,e)=> e.id<m ? e.id : m, eps[0].id);
  // 「次の話」のコピー元は最新話（降順ソート済みなので先頭）
  const latest = eps[0].id;
  const div=document.createElement("div");
  div.className="series-block"+(isW?" watching":"");
  let h='<div class="series-head">'
    + '<span class="chevron">\u25B6</span>'
    + '<span class="series-name">'+esc(name)+'</span>'
    + '<span class="series-badge '+bc+'">'+esc(media)+'</span>'
    + '<span class="series-meta">'+eps.length+'話</span>'
    + '<button type="button" class="watch-btn'+(isW?" on":"")+'" data-rep="'+rep+'">'
    +   (isW?"視聴中":"追う")+'</button>'
    + '</div><div class="eps">';
  // 「次の話を追加」は最上部に置く。コピー元は最新話。
  var isTL = eps.some(function(e){ return e.timeline==="あり"; });
  var addLabel = isTL ? "＋次の話を記録（時刻メモ）" : "＋次の話を追加";
  h+='<a class="add-ep" href="new.php?mode=next&src='+latest+'">'+addLabel+'</a>';
  eps.forEach(e=>{
    h+='<a class="ep" href="edit.php?id='+e.id+'">'
      + '<span class="ep-id">'+e.id+'</span>'
      + '<span class="ep-num">'+e.episode+'</span>'
      + '<div class="ep-body"><div class="ep-sub">'
      +   (e.subtitle?esc(e.subtitle):'<span style="color:#c8c2b6">（サブタイトルなし）</span>')
      +   (e.timeline==="あり"?' <span class="tl">時刻</span>':'')
      + '</div></div>'
      + '<span class="ep-stars">'+stars(e.score)+'</span></a>';
  });
  h+='</div>';
  div.innerHTML=h;
  // 見出しクリックで開閉、ただしトグルボタンは別動作
  div.querySelector(".series-head").onclick=(ev)=>{
    if(ev.target.closest(".watch-btn")) return;
    div.classList.toggle("open");
  };
  div.querySelector(".watch-btn").onclick=()=>{
    document.getElementById("toggleId").value = rep;
    document.getElementById("toggleForm").submit();
  };
  return div;
}

// 番号順フラット表示の1行
function flatRow(r){
  const a=document.createElement("a");
  a.className="flat-row"; a.href="edit.php?id="+r.id;
  const title = (r.series||"（無題）") + (r.subtitle?(" ・ "+r.subtitle):"");
  a.innerHTML='<span class="flat-id">'+r.id+'</span>'
    + '<div class="flat-body">'
    +   '<div class="flat-title">'+esc(title)+'</div>'
    +   '<div class="flat-meta">第'+r.episode+'話 ・ '+esc(r.media||"")
    +     (r.tags?(" ・ "+esc(r.tags)):"")
    +     (r.status?(" ・ "+esc(r.status)):"")+'</div>'
    + '</div>'
    + '<span class="flat-stars">'+stars(r.score)+'</span>';
  return a;
}

function render(){
  const wa=document.getElementById("watchingArea"); wa.innerHTML="";
  const ra=document.getElementById("restArea");     ra.innerHTML="";

  if(curSort==="number"){
    // シリーズまとめをやめ、全話を6桁番号順にフラット表示
    const f = DATA.filter(passAll).slice().sort((a,b)=>a.id.localeCompare(b.id));
    document.getElementById("count").textContent = f.length+"件（番号順）";
    document.getElementById("watchingLabel").style.display="none";
    document.getElementById("restLabel").style.display="none";
    if(f.length===0){ ra.innerHTML='<div class="empty">該当なし</div>'; return; }
    f.forEach(r=>ra.appendChild(flatRow(r)));
    return;
  }

  const g=group();
  document.getElementById("count").textContent =
    g.total+"件 ・ 視聴中"+g.watching.length+" ／ その他"+g.rest.length;
  document.getElementById("watchingLabel").style.display = g.watching.length?"block":"none";
  document.getElementById("restLabel").style.display     = g.watching.length?"block":"none";
  // シリーズブロックは代表番号（最小id）順に並べる
  const byNum = arr => arr.slice().sort((A,B)=>{
    const a=A[1].reduce((m,e)=>e.id<m?e.id:m,A[1][0].id);
    const b=B[1].reduce((m,e)=>e.id<m?e.id:m,B[1][0].id);
    return a.localeCompare(b);
  });
  byNum(g.watching).forEach(([n,eps])=>wa.appendChild(seriesBlock(n,eps)));
  byNum(g.rest).forEach(([n,eps])=>ra.appendChild(seriesBlock(n,eps)));
  if(g.total===0) ra.innerHTML='<div class="empty">該当なし</div>';
}

// メディア／タグのチップを生成
function buildChip(label, on, onClick){
  const b=document.createElement("button");
  b.className="chip sm"+(on?" on":""); b.textContent=label; b.onclick=onClick;
  return b;
}
function populateFilters(){
  const mb=document.getElementById("mediaBar");
  MEDIA_OPTIONS.forEach(m=>{
    mb.appendChild(buildChip(m, false, function(){
      if(selMedia.has(m)) selMedia.delete(m); else selMedia.add(m);
      this.classList.toggle("on"); render();
    }));
  });
  const tb=document.getElementById("tagBar");
  TAG_OPTIONS.forEach(t=>{
    tb.appendChild(buildChip(t, false, function(){
      if(selTags.has(t)) selTags.delete(t); else selTags.add(t);
      this.classList.toggle("on"); render();
    }));
  });
}
populateFilters();
document.getElementById("search").oninput=e=>{curSearch=e.target.value;render();};
document.querySelectorAll("#filter .chip").forEach(ch=>{
  ch.onclick=()=>{
    curFilter=ch.dataset.f;
    document.querySelectorAll("#filter .chip").forEach(c=>c.classList.toggle("on",c===ch));
    render();
  };
});
document.querySelectorAll("#sortBar .chip").forEach(ch=>{
  ch.onclick=()=>{
    curSort=ch.dataset.s;
    document.querySelectorAll("#sortBar .chip").forEach(c=>c.classList.toggle("on",c===ch));
    render();
  };
});
render();
</script>
</body></html>
