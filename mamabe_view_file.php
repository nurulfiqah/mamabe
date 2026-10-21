<?php
ob_start();
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');
require_once(__DIR__ . '/nas_config.php');
ob_clean();

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not available.');
}

$path = isset($_GET['path']) ? trim($_GET['path']) : '';

// Only ever allow streaming files that live under this module's own NAS
// folder — never let this endpoint be used to browse the wider NAS.
if ($path === '' || strpos($path, CORP_NAS_FOLDER) !== 0) {
    http_response_code(404);
    die('File not found.');
}

$nas = corpNasConnect();
$content = $nas->download($path);
if ($content === false) {
    http_response_code(404);
    die('File not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'jpg' || $ext === 'jpeg') { $mime = 'image/jpeg'; }
elseif ($ext === 'png') { $mime = 'image/png'; }
elseif ($ext === 'pdf') { $mime = 'application/pdf'; }

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Content-Length: ' . strlen($content));
echo $content;
