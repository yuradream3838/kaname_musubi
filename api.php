<?php
/**
 * かなめ結び 読み取り専用 API (api.php)
 *
 * - 認証: HTTP ヘッダー X-Kaname-Token。サーバー側はトークンの SHA-256 のみを持ち hash_equals() で照合。
 * - 応答: 6桁MD全件を 1記録=1オブジェクト の素の JSON で返す。?id=000123 で1件だけ。
 * - 書き込みの口は無い（GET/HEAD 以外は 405）。_watching.txt は records には含めず watching に番号だけ載せる。
 * - 既存PHP群（config/md/list/new/edit/timeline/backup）には依存しない単体ファイル。
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 設定
// ---------------------------------------------------------------------------

// トークンの SHA-256（16進64文字・小文字）。トークン本体はここに書かない。
// 手元で:  printf '%s' "$TOKEN" | shasum -a 256
// 空のままなら全リクエストを 401 で拒否する（閉じた状態が既定）。
const KANAME_API_TOKEN_SHA256 = '';

// 6桁MDのあるディレクトリ。空なら下の候補を順に探す。
const KANAME_API_DATA_DIR = '';

// ---------------------------------------------------------------------------

const KANAME_API_FIELDS = [
    'series', 'episode', 'subtitle', 'author', 'url', 'start', 'date',
    'tags', 'media', 'timeline', 'status', 'duration', 'resume',
];

function api_send(int $code, array $body): void
{
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

function api_data_dir(): ?string
{
    $candidates = KANAME_API_DATA_DIR !== ''
        ? [KANAME_API_DATA_DIR]
        : [__DIR__ . '/kaname_data', dirname(__DIR__) . '/kaname_data'];
    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return rtrim($dir, '/');
        }
    }
    return null;
}

function api_token_from_request(): string
{
    if (isset($_SERVER['HTTP_X_KANAME_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_KANAME_TOKEN'];
    }
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'X-Kaname-Token') === 0) {
                return (string)$v;
            }
        }
    }
    return '';
}

function api_authorized(): bool
{
    $expected = strtolower(trim(KANAME_API_TOKEN_SHA256));
    if (!preg_match('/\A[0-9a-f]{64}\z/', $expected)) {
        return false; // ハッシュ未設定・不正形式なら常に拒否
    }
    $token = trim(api_token_from_request());
    if ($token === '') {
        return false;
    }
    return hash_equals($expected, hash('sha256', $token));
}

/** YAML 風スカラーの引用符を外す */
function api_unquote(string $v): string
{
    $v = trim($v);
    $n = strlen($v);
    if ($n >= 2) {
        $f = $v[0];
        $l = $v[$n - 1];
        if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
            $inner = substr($v, 1, -1);
            return $f === '"' ? stripcslashes($inner) : str_replace("''", "'", $inner);
        }
    }
    return $v;
}

/** "[a, b]" / "a, b" / "a、b" をリストに */
function api_split_list(string $v): array
{
    $v = trim($v);
    if ($v === '' || $v === '[]') {
        return [];
    }
    if ($v[0] === '[' && substr($v, -1) === ']') {
        $v = substr($v, 1, -1);
    }
    $out = [];
    foreach (preg_split('/\s*[,、]\s*/u', $v) ?: [] as $item) {
        $item = api_unquote($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

function api_number(?string $v)
{
    if ($v === null) {
        return null;
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/\A-?\d+\z/', $v)) {
        return (int)$v;
    }
    if (is_numeric($v)) {
        return (float)$v;
    }
    return null;
}

/** "- [mm:ss] 本文" の行群を {t, text} に分解 */
function api_parse_notes(string $block): array
{
    $notes = [];
    foreach (preg_split('/\R/u', $block) ?: [] as $line) {
        if (preg_match('/^\s*[-*]\s*\[\s*(\d{1,3}(?::\d{1,2}){1,2})\s*\]\s?(.*)$/u', $line, $m)) {
            $notes[] = ['t' => $m[1], 'text' => rtrim($m[2])];
        } elseif ($notes && trim($line) !== '' && preg_match('/^\s{2,}\S/u', $line)) {
            // インデントされた継続行は直前のメモに連結
            $notes[count($notes) - 1]['text'] .= "\n" . trim($line);
        }
    }
    return $notes;
}

/** MD 1件を front matter・時刻メモ・総評・score に分解 */
function api_parse_md(string $text): array
{
    $text = preg_replace('/\A\xEF\xBB\xBF/', '', $text) ?? $text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $front = [];
    $body = $text;
    if (preg_match('/\A---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)(.*)\z/s', $text, $m)) {
        $body = $m[2];
        $lastKey = null;
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s?(.*)$/', $line, $kv)) {
                $lastKey = $kv[1];
                $front[$lastKey] = $kv[2];
            } elseif ($lastKey !== null && preg_match('/^\s*-\s+(.*)$/', $line, $li)) {
                // YAML ブロックリスト（tags:\n  - アニメ）
                $prev = $front[$lastKey];
                $front[$lastKey] = is_array($prev) ? $prev : (trim((string)$prev) === '' ? [] : api_split_list((string)$prev));
                $front[$lastKey][] = api_unquote($li[1]);
            }
        }
    }

    // 末尾の score: N
    $score = null;
    if (preg_match('/^[ \t]*score[ \t]*:[ \t]*(\S*)[ \t]*\s*\z/mi', $body, $sm, PREG_OFFSET_CAPTURE)) {
        $score = api_number($sm[1][0]);
        $body = substr($body, 0, $sm[0][1]);
    }

    // ## 見出しでセクション分割
    $notesBlock = '';
    $review = '';
    $parts = preg_split('/^##[ \t]+(.+?)[ \t]*$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
    for ($i = 1; $i < count($parts); $i += 2) {
        $heading = trim($parts[$i]);
        $content = $parts[$i + 1] ?? '';
        if (strpos($heading, '時刻メモ') !== false) {
            $notesBlock .= $content;
        } elseif (strpos($heading, '総評') !== false) {
            $review .= $content;
        }
    }

    $rec = [];
    foreach (KANAME_API_FIELDS as $key) {
        $raw = $front[$key] ?? null;
        switch ($key) {
            case 'tags':
                $rec[$key] = is_array($raw) ? array_values(array_filter($raw, 'strlen')) : api_split_list((string)$raw);
                break;
            case 'episode':
            case 'duration':
            case 'resume':
                $rec[$key] = is_array($raw) ? null : api_number($raw);
                break;
            default:
                if (is_array($raw)) {
                    $rec[$key] = $raw;
                } else {
                    $v = $raw === null ? '' : api_unquote($raw);
                    $rec[$key] = $v === '' ? null : $v;
                }
        }
    }
    // 定義外のキーも生のまま残す（title 等の旧フィールド）
    foreach ($front as $key => $raw) {
        if (!array_key_exists($key, $rec) && !in_array($key, ['id', 'notes', 'review', 'score'], true)) {
            $rec[$key] = is_array($raw) ? $raw : (api_unquote($raw) === '' ? null : api_unquote($raw));
        }
    }

    $rec['notes'] = api_parse_notes($notesBlock);
    $review = trim($review);
    $rec['review'] = $review === '' ? null : $review;
    $rec['score'] = $score;
    return $rec;
}

function api_load_watching(string $dir): array
{
    $file = $dir . '/_watching.txt';
    if (!is_file($file)) {
        return [];
    }
    preg_match_all('/(?<!\d)\d{6}(?!\d)/', (string)file_get_contents($file), $m);
    return array_values(array_unique($m[0]));
}

function api_load_record(string $dir, string $id): ?array
{
    $path = $dir . '/' . $id . '.md';
    if (!is_file($path)) {
        return null;
    }
    $text = file_get_contents($path);
    if ($text === false) {
        return null;
    }
    return ['id' => $id] + api_parse_md($text);
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    api_send(405, ['error' => 'method_not_allowed']);
}

if (!api_authorized()) {
    api_send(401, ['error' => 'auth']);
}

$dir = api_data_dir();
if ($dir === null) {
    api_send(500, ['error' => 'data_dir']);
}

$records = [];
if (isset($_GET['id'])) {
    $id = (string)$_GET['id'];
    $rec = preg_match('/\A\d{6}\z/', $id) ? api_load_record($dir, $id) : null;
    if ($rec === null) {
        api_send(404, ['error' => 'not_found']);
    }
    $records[] = $rec;
} else {
    $files = glob($dir . '/[0-9][0-9][0-9][0-9][0-9][0-9].md') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $path) {
        $rec = api_load_record($dir, basename($path, '.md'));
        if ($rec !== null) {
            $records[] = $rec;
        }
    }
}

api_send(200, [
    'app' => 'kaname',
    'count' => count($records),
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'watching' => api_load_watching($dir),
    'records' => $records,
]);
