<?php
/**
 * Approve one person (mother or baby) on a submission - staff-triggered endpoint; the actual assign-a-voucher logic
 * lives in common/lib/MamabeApproval.php's mamabeApproveSubmission() so it can be shared with worker.php's automatic
 * approval when the AI check itself says Proceed (no staff action needed there).
 * POST: id (mamabe_submission.id), csrf_token. Response: {"ok":true,"voucher_code":"..."} | {"ok":false,"error":"..."}
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
$result = mamabeApproveSubmission($conn, $id, (int)$id_user);
echo json_encode($result);
