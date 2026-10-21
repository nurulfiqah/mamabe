<?php
/**
 * ODB API - "How do you know us?" options for the public MamaBe form, called server-to-server by the form's proxy
 * (education server, api/sources.php). Reached through /api/mamabeSources.php; this folder itself is blocked from HTTP.
 *
 * POST: username, password - api_user credentials (same user and rules as register.php)
 * Response JSON: {"status":"success","sources":[{"value":"outlet","label":"Outlet"}, ...]}
 *                |  {"status":"error","message":"..."}   (active only, sorted by sort_order then label)
 * The list is MamabeRecords::sources($conn) - register.php only accepts a value from it. Admin-editable on
 * registration/settings.php (add / rename / hide / reorder) without touching code.
 */
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(__FILE__) . '/../common/config.php';
require_once dirname(__FILE__) . '/../common/api_auth.php';
require_once dirname(__FILE__) . '/../common/lib/MamabeRecords.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'message' => 'Method not allowed.'));
    exit;
}

$conn = mamabe_db();
if (!mamabe_api_user_ok($conn, isset($_POST['username']) ? (string)$_POST['username'] : '', isset($_POST['password']) ? (string)$_POST['password'] : '')) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Invalid User'));
    exit;
}

$list = array();
foreach (MamabeRecords::sources($conn) as $value => $label) {
    $list[] = array('value' => $value, 'label' => $label);
}
$conn->close();
echo json_encode(array('status' => 'success', 'sources' => $list), JSON_UNESCAPED_UNICODE);
