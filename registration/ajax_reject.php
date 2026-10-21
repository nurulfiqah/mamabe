<?php
/**
 * Reject one person (mother or baby) on a submission: marks it rejected so it stops showing in the admin list as
 * something still needing a decision, and - ONLY for data this submission itself created, never an existing
 * customer's pre-existing record - hides it from the MamaBe Mama List / mama detail page:
 *   - a 'pink' (mother) row where mama_is_new = 1: soft-deletes the mamabe_mama row it created (recycle = 1).
 *   - either row where baby_is_new = 1 (this submission created the mamabe_baby row rather than matching an
 *     already-existing pregnancy and filling empty fields on it): soft-deletes that mamabe_baby row (recycle = 1).
 * A mama/baby this submission only matched (mama_is_new / baby_is_new = 0) is left completely untouched - she's a
 * real customer, and rejecting one bogus/duplicate web submission must never hide her real record.
 * Cannot reject something already approved (has a voucher_id) - approve first, reject first; not both.
 * Also emails the mama (mamabe_mama.email, when she has one on file - the public form requires one, staff-added
 * mamas may not have one) telling her to visit the nearest outlet to complete registration in person. Mail is
 * best-effort (common/lib/Mailer.php) and never blocks the reject itself.
 * Idempotent. POST: id (mamabe_submission.id), csrf_token. Response: {"ok":true} | {"ok":false,"error":"..."}
 */
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');
require_once(dirname(__FILE__) . '/common/lib/Mailer.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed.'));
    exit;
}
MamabeCsrf::verify();

// Self-migrating columns - same checks as api/register.php (baby_is_new) and ajax_approve.php (voucher_id), kept
// here too so this endpoint is safe to call standalone, plus this file's own rejected_at / rejected_by.
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'baby_is_new'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission ADD COLUMN baby_is_new TINYINT(1) NOT NULL DEFAULT 0 AFTER mama_is_new");
}
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'voucher_id'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission
        ADD COLUMN approved_at DATETIME NULL,
        ADD COLUMN approved_by INT NULL,
        ADD COLUMN voucher_id INT NULL COMMENT 'mamabe_voucher.id assigned when this person was approved'");
}
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'rejected_at'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission ADD COLUMN rejected_at DATETIME NULL, ADD COLUMN rejected_by INT NULL");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

$stmt = $conn->prepare("SELECT register_type, mama_id, mama_is_new, baby_id, baby_is_new, voucher_id, rejected_at FROM mamabe_submission WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($registerType, $mamaId, $mamaIsNew, $babyId, $babyIsNew, $voucherId, $existingRejectedAt);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    echo json_encode(array('ok' => false, 'error' => 'Submission not found.'));
    exit;
}

if ($existingRejectedAt !== null) {
    echo json_encode(array('ok' => true, 'already' => true));
    exit;
}

if ($voucherId !== null) {
    echo json_encode(array('ok' => false, 'error' => 'This was already approved and given a voucher - it cannot be rejected.'));
    exit;
}

$conn->begin_transaction();
try {
    $now = date('Y-m-d H:i:s');
    $by  = (int)$id_user;
    $stmt = $conn->prepare("UPDATE mamabe_submission SET rejected_at = ?, rejected_by = ? WHERE id = ?");
    $stmt->bind_param('sii', $now, $by, $id);
    if (!$stmt->execute()) throw new RuntimeException('mamabe_submission reject: ' . $stmt->error);
    $stmt->close();

    // Only ever hide data this submission itself created - never an existing customer's record.
    if ((int)$babyIsNew === 1 && $babyId !== null) {
        $stmt = $conn->prepare("UPDATE mamabe_baby SET recycle = 1 WHERE id = ?");
        $stmt->bind_param('i', $babyId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_baby reject: ' . $stmt->error);
        $stmt->close();
    }
    if ($registerType === 'pink' && (int)$mamaIsNew === 1 && $mamaId !== null) {
        $stmt = $conn->prepare("UPDATE mamabe_mama SET recycle = 1 WHERE id = ?");
        $stmt->bind_param('i', $mamaId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_mama reject: ' . $stmt->error);
        $stmt->close();
    }

    $conn->commit();

    // Best-effort notification - a mail failure (or no email on file) must never undo the reject, which already
    // committed above. Only mamas with an email on file (staff-added, not from the public form) actually get one.
    if ($mamaId !== null) {
        $stmt = $conn->prepare("SELECT mama_name, email FROM mamabe_mama WHERE id = ?");
        $stmt->bind_param('i', $mamaId);
        $stmt->execute();
        $stmt->bind_result($mamaName, $mamaEmail);
        $stmt->fetch();
        $stmt->close();
        if (!empty($mamaEmail)) {
            sendMamabeRejectionEmail($conn, $mamaEmail, $mamaName, $id);
        }
    }

    echo json_encode(array('ok' => true, 'already' => false));
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(array('ok' => false, 'error' => 'Could not reject. Please try again.'));
}
