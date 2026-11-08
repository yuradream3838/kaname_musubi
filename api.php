<?php
// =============================================================
// api.php — 読み取り専用 JSON エンドポイント（他AI連携の第一層）
//   ・認証: HTTP ヘッダー X-Kaname-Token。サーバー側はトークンの
//     SHA-256 だけを持ち、hash_equals() で照合（auth.php は使わない）。
//   ・全件: 6桁MDを1記録=1オブジェクトで返す。?id=000123 で1件だけ。
//   ・書き込みの口は作らない（GET/HEAD 以外は 405）。
//   ・分解は md.php の kaname_parse() をそのまま使う。
// =============================================================
require_once __DIR__ . '/md.php';

// トークンは64桁の小文字16進（openssl rand -hex 32）。その SHA-256（16進64文字）をここに置く。
// トークン本体はここにもどこにも書かない。
//   手元で:  printf '%s' "$TOKEN" | shasum -a 256
// 空のままなら全リクエストを 401 で拒否する（閉じた状態が既定）。
const KANAME_API_TOKEN_SHA256 = '';

function api_send($code, $body) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

function api_token_from_request() {
    if (isset($_SERVER['HTTP_X_KANAME_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_KANAME_TOKEN'];
    }
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'X-Kaname-Token') === 0) return (string)$v;
        }
    }
    return '';
}

function api_authorized() {
    $expected = strtolower(trim(KANAME_API_TOKEN_SHA256));
    if (!preg_match('/\A[0-9a-f]{64}\z/', $expected)) return false; // 未設定なら常に拒否
    // トークンは64桁の小文字16進のみ受け付ける（それ以外は照合せず拒否）
    $token = trim(api_token_from_request());
    if (!preg_match('/\A[0-9a-f]{64}\z/', $token)) return false;
    return hash_equals($expected, hash('sha256', $token));
}

// '' → null、数字なら int
function api_int_or_null($v) {
    $v = trim((string)$v);
    return preg_match('/\A-?\d+\z/', $v) ? (int)$v : null;
}

// "- [mm:ss] 本文" の行群 → [{t, text}, ...]（timeline.php と同じ形）
function api_notes($block) {
    $notes = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$block) as $line) {
        if (preg_match('/^\s*-\s*\[\s*(\d{1,3}(?::\d{1,2}){1,2})\s*\]\s?(.*)$/u', $line, $m)) {
            $notes[] = ['t' => $m[1], 'text' => rtrim($m[2])];
        }
    }
    return $notes;
}

// kaname_parse() の結果を API の1記録に整える
function api_record($id, $d) {
    global $KANAME_FIELDS;
    $rec = ['id' => $id];
    foreach ($KANAME_FIELDS as $key => $def) {
        $v = trim((string)($d['fields'][$key] ?? ''));
        if ($key === 'tags') {
            // edit.php と同じ区切り（, 、 ，）で分ける
            $rec[$key] = array_values(array_filter(array_map('trim', preg_split('/[,、，]/u', $v))));
        } elseif ($def['type'] === 'number' || $def['type'] === 'hidden') {
            $rec[$key] = api_int_or_null($v);
        } else {
            $rec[$key] = $v === '' ? null : $v;
        }
    }
    $rec['notes']  = api_notes($d['timeline_notes'] ?? '');
    $review        = trim((string)($d['review'] ?? ''));
    $rec['review'] = $review === '' ? null : $review;
    $rec['score']  = api_int_or_null($d['score'] ?? '');
    return $rec;
}

function api_load($id) {
    $path = kaname_path($id);   // 6桁の関所を通す
    if ($path === null || !is_file($path)) return null;
    $text = file_get_contents($path);
    if ($text === false) return null;
    $text = preg_replace('/\A\xEF\xBB\xBF/', '', $text); // BOM 除去
    return api_record($id, kaname_parse($text));
}

// --- main ----------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    api_send(405, ['error' => 'method_not_allowed']);
}
if (!api_authorized()) {
    api_send(401, ['error' => 'auth']);
}

$records = [];
if (isset($_GET['id'])) {
    $rec = api_load((string)$_GET['id']);
    if ($rec === null) api_send(404, ['error' => 'not_found']);
    $records[] = $rec;
} else {
    $ids = [];
    foreach (glob(KANAME_DATA_DIR . '/*.md') ?: [] as $fpath) {
        $base = basename($fpath, '.md');
        if (preg_match('/^\d{6}$/', $base)) $ids[] = $base;   // _watching.txt 等は対象外
    }
    sort($ids, SORT_STRING);
    foreach ($ids as $id) {
        $rec = api_load($id);
        if ($rec !== null) $records[] = $rec;
    }
}

api_send(200, [
    'app'         => 'kaname',
    'count'       => count($records),
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'watching'    => array_map('strval', array_keys(kaname_watching_load())),
    'records'     => $records,
]);
