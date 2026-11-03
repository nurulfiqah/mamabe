<?php
/**
 * Reject one person (mother or baby) on a submission - staff click Reject on registration/index.php. The actual
 * logic (mark rejected, hide only data this submission created, email her) lives in common/lib/MamabeApproval.php's
 * mamabeRejectSubmission() so it can be shared with the automatic staff rejection in worker.php.
 * Idempotent. POST: id (mamabe_submission.id), csrf_token. Response: {"ok":true} | {"ok":false,"error":"..."}
 */
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeApproval.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed.'));
    exit;
}
MamabeCsrf::verify();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// A staff member's registration gets the staff version of the rejection email, same as the automatic reject.
$isStaff = false;
$stmt = $conn->prepare("SELECT ic, phone FROM mamabe_submission WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($subIc, $subPhone);
if ($stmt->fetch()) {
    $stmt->close();
    $isStaff = mamabeIsStaff($conn, $subIc, $subPhone);
} else {
    $stmt->close();
}

$result = mamabeRejectSubmission($conn, $id, (int)$id_user, $isStaff);
if ($result['ok']) {
    echo json_encode(array('ok' => true, 'already' => $result['already']));
} else {
    echo json_encode(array('ok' => false, 'error' => $result['error']));
}
