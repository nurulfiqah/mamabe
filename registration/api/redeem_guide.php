<?php
/**
 * ODB API - the "How to redeem your voucher" guide image shown on the public MamaBe form's success screen, called
 * server-to-server by the form's proxy (education server, api/redeem-guide.php). Reached through
 * /api/mamabeRedeemGuide.php; this folder itself is blocked from HTTP.
 *
 * POST: username, password - api_user credentials (same user and rules as register.php / outlets.php).
 * Response: the image bytes with the right Content-Type, or HTTP 404 if no image is configured
 * (settings.php's "Redeem Voucher Guide Image"). The proxy caches it, so this is only hit occasionally.
 */
error_reporting(0);

require_once dirname(__FILE__) . '/../common/config.php';
require_once dirname(__FILE__) . '/../common/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$conn = mamabe_db();
if (!mamabe_api_user_ok($conn, isset($_POST['username']) ? (string)$_POST['username'] : '', isset($_POST['password']) ? (string)$_POST['password'] : '')) {
    http_response_code(401);
    exit;
}

$ext = '';
$res = $conn->query("SELECT setting_value FROM mamabe_setting WHERE setting_key = 'redeem_guide_ext' LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) $ext = (string)$row['setting_value'];
$conn->close();

$mimes = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif');
$path  = dirname(__FILE__) . '/../public_assets/redeem_guide.' . $ext;

if ($ext === '' || !isset($mimes[$ext]) || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=3600');
readfile($path);
