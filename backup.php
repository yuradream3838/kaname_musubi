<?php
// =============================================================
// backup.php — 全件バックアップ（門番の内側）
//   kaname_data 内の6桁MD（000000.md 〜）だけを1つにまとめて落とす。
//   _watching.txt など6桁でないファイルは含めない。
//   ・ZipArchive があれば .zip
//   ・無ければ PharData で .tar.gz にフォールバック
//   手元PCへの二重化用。サーバー1箇所だけに置かないための保険。
// =============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/md.php';

// --- 対象ファイルを集める（6桁MDだけ・list.phpと同じ関所）------
$targets = [];
foreach (glob(KANAME_DATA_DIR . '/*.md') as $fpath) {
    $base = basename($fpath, '.md');
    if (!preg_match('/^\d{6}$/', $base)) continue;   // _watching.txt 等は除外
    if (!is_file($fpath)) continue;
    $targets[$base . '.md'] = $fpath;   // アーカイブ内名 => 実パス
}
ksort($targets);

if (empty($targets)) {
    http_response_code(404);
    exit('バックアップ対象のファイルがありません。');
}

$stamp = date('Ymd_His');

// --- 1) ZipArchive があれば zip -------------------------------
if (class_exists('ZipArchive')) {
    $tmp = tempnam(sys_get_temp_dir(), 'kaname_zip_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
        foreach ($targets as $arcname => $realpath) {
            $zip->addFile($realpath, $arcname);
        }
        $zip->close();

        $fname = "kaname_backup_{$stamp}.zip";
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    // open に失敗したら tar.gz へ落ちる
    @unlink($tmp);
}

// --- 2) フォールバック：PharData で tar.gz --------------------
if (class_exists('PharData')) {
    // PharData はユニークな作業パスが必要（既存だと例外）
    $work = sys_get_temp_dir() . '/kaname_bk_' . $stamp . '_' . getmypid();
    $tarPath = $work . '.tar';
    @unlink($tarPath);
    @unlink($tarPath . '.gz');
    try {
        $phar = new PharData($tarPath);
        foreach ($targets as $arcname => $realpath) {
            $phar->addFile($realpath, $arcname);
        }
        $phar->compress(Phar::GZ);      // → $tarPath.gz を生成
        unset($phar);                   // ハンドルを離す（.tar 削除のため）
        @unlink($tarPath);              // 非圧縮の .tar は捨てる

        $gz = $tarPath . '.gz';
        if (is_file($gz)) {
            $fname = "kaname_backup_{$stamp}.tar.gz";
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . filesize($gz));
            header('Cache-Control: no-store');
            readfile($gz);
            @unlink($gz);
            exit;
        }
    } catch (Exception $e) {
        @unlink($tarPath);
        @unlink($tarPath . '.gz');
    }
}

// --- どちらも使えない場合 -------------------------------------
http_response_code(500);
exit('この環境では zip / tar.gz の生成ができませんでした。サーバーの設定を確認してください。');
