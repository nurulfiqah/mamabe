<?php
/**
 * ODB API - outlets a mama can pick to redeem at on the public MamaBe form, called server-to-server by the form's
 * proxy (education server, api/outlets.php). Reached through /api/mamabeOutlets.php; this folder itself is blocked
 * from HTTP.
 *
 * POST: username, password - api_user credentials (same user and rules as register.php); type (optional) -
 *   'avida' returns MamabeRecords::avidaOutlets() instead of the normal list, for the "Which Avida outlet?" picker
 *   shown when "Confinement center" is chosen for "How do you know us?" - anything else/omitted is the normal list.
 * Response JSON (normal):  {"status":"success","outlets":[{"id":3,"name":"Alpro Pharmacy Ampangan","state":"Negeri Sembilan"}, ...]}
 * Response JSON (avida):   {"status":"success","outlets":[{"id":12,"name":"Avida Confinement Care Centre"}, ...]}   (no "state")
 *                |  {"status":"error","message":"..."}   (sorted by state then name / by name)
 * register.php only accepts an outlet_id from whichever of the two lists matches the submitted source.
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
if ((isset($_POST['type']) ? (string)$_POST['type'] : '') === 'avida') {
    foreach (MamabeRecords::avidaOutlets($conn) as $id => $name) {
        $list[] = array('id' => $id, 'name' => $name);
    }
} else {
    foreach (MamabeRecords::outlets($conn) as $id => $o) {
        $list[] = array('id' => $id, 'name' => $o['name'], 'state' => $o['state']);
    }
}
$conn->close();
echo json_encode(array('status' => 'success', 'outlets' => $list), JSON_UNESCAPED_UNICODE);
