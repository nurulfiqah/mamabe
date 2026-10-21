<?php
/**
 * Streams a submission's stored image to Admin / Super Admin only (uploads/ is blocked from direct HTTP).
 * GET: id (required), doc=pink|baby (default pink), thumb=1 for a 200 px thumbnail instead of the full stored image.
 */
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');

$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$column = (isset($_GET['doc']) && $_GET['doc'] === 'baby') ? 'baby_file' : 'pink_file';   // fixed whitelist, never user text
$stmt   = $conn->prepare("SELECT $column FROM mamabe_submission WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($file);
$found = $stmt->fetch();
$stmt->close();

$path = ($found && $file) ? MAMABE_UPLOAD_DIR . basename($file) : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found.';
    exit;
}

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

if (isset($_GET['thumb'])) {
    $src = @imagecreatefromjpeg($path);
    if ($src) {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, 200 / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($dst, null, 80);
        exit;
    }
}

header('Content-Length: ' . filesize($path));
readfile($path);
