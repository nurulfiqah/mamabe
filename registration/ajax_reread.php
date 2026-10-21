<?php
/**
 * "Ask AI to re-read" button on the admin list (admin only): puts one checked submission row (Proceed or Pending) back
 * in the AI queue and pings the worker, which reads the document again (up to MamabeVerifier::MAX_READS times) and
 * replaces the result. Nothing else changes - the MamaBe records and the NAS copy stay as they are.
 * POST: id (mamabe_submission.id), csrf_token. Response JSON: {"ok":true} | {"ok":false,"error":"..."}
 */
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');
require_once(dirname(__FILE__) . '/common/worker_kick.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed.'));
    exit;
}
MamabeCsrf::verify();

$id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$queued  = MamabeVerifier::STATUS_QUEUED;
$proceed = MamabeVerifier::STATUS_PROCEED;
$pending = MamabeVerifier::STATUS_PENDING;
// Only a finished row: one that is queued / being checked will get its result anyway.
$stmt = $conn->prepare("UPDATE mamabe_submission SET status = ?, check_note = NULL WHERE id = ? AND status IN (?, ?)");
$stmt->bind_param('iiii', $queued, $id, $proceed, $pending);
$stmt->execute();
$changed = $stmt->affected_rows === 1;
$stmt->close();

if (!$changed) {
    echo json_encode(array('ok' => false, 'error' => 'This row is already waiting for the AI, or no longer exists.'));
    exit;
}
mamabe_kick_worker();
echo json_encode(array('ok' => true));
