<?php
/**
 * Shared "approve one person" logic - assigns the oldest unassigned voucher of the matching type from the master
 * voucher stock (mamabe_voucher, built via mamabe/voucher/) and stamps it on mamabe_submission/mamabe_baby/
 * mamabe_voucher, then best-effort emails the mama. Used by two callers:
 *   - ajax_approve.php: a staff member clicks Approve on registration/index.php.
 *   - worker.php: MamabeVerifier::process() reads "Proceed" from the AI check, so the row is approved automatically
 *     with no staff action - $actorStaffId is NULL in that case (approved_by stays NULL = system/AI, not a person).
 * Idempotent: approving an already-approved row just returns its existing voucher instead of assigning a new one.
 *
 * A mama who registered in person at an outlet already walked away with a PHYSICAL voucher there - she must never
 * also get a digital code. That's recorded as a receipt number (mamabe_mama.receipt_no for her own/"pink" side,
 * mamabe_baby.baby_receipt_no for that specific baby's side); when one is present, approval still goes through
 * (approved_at/approved_by get stamped so the row stops showing as needing a decision) but voucher_id is left NULL
 * - no stock is touched. She still gets a confirmation email (sendMamabePhysicalApprovalEmail), just one that
 * doesn't claim a voucher code since she already has one. registration/index.php shows this state as
 * "Approved (physical voucher)" instead of a code.
 */
require_once(dirname(__FILE__) . '/Mailer.php');

const MAMABE_VOUCHER_TYPE_MAMA = 1;   // matches mamabe/voucher/index.php's MAMABE_TYPE_MAMA
const MAMABE_VOUCHER_TYPE_BABY = 2;   // matches mamabe/voucher/index.php's MAMABE_TYPE_BABY

/** Self-migrating columns/indexes this flow needs on mamabe_submission/mamabe_baby - safe to call every time. */
function mamabeEnsureApprovalSchema(mysqli $conn) {
    $_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'voucher_id'");
    if ($_c && $_c->num_rows === 0) {
        $conn->query("ALTER TABLE mamabe_submission
            ADD COLUMN approved_at DATETIME NULL,
            ADD COLUMN approved_by INT NULL,
            ADD COLUMN voucher_id INT NULL COMMENT 'mamabe_voucher.id assigned when this person was approved'");
    }
    $_c = $conn->query("SHOW INDEX FROM mamabe_submission WHERE Key_name = 'idx_voucher_unique'");
    if ($_c && $_c->num_rows === 0) {
        $conn->query("ALTER TABLE mamabe_submission ADD UNIQUE KEY idx_voucher_unique (voucher_id)");
    }
    // Same guard directly on mamabe_baby - the actual assignment record - as defense in depth beyond the
    // mamabe_submission constraint above (nullable unique keys: MySQL allows any number of NULLs, only enforces
    // uniqueness once a real voucher id is set).
    $_c = $conn->query("SHOW INDEX FROM mamabe_baby WHERE Key_name = 'idx_mama_voucher_unique'");
    if ($_c && $_c->num_rows === 0) {
        $conn->query("ALTER TABLE mamabe_baby ADD UNIQUE KEY idx_mama_voucher_unique (mama_voucher_id)");
    }
    $_c = $conn->query("SHOW INDEX FROM mamabe_baby WHERE Key_name = 'idx_baby_voucher_unique'");
    if ($_c && $_c->num_rows === 0) {
        $conn->query("ALTER TABLE mamabe_baby ADD UNIQUE KEY idx_baby_voucher_unique (baby_voucher_id)");
    }
}

/**
 * Approve mamabe_submission.id $id - assigns a voucher and emails the mama. $actorStaffId is the approving staff's
 * id_user, or NULL for an automatic AI-driven approval.
 * Returns array('ok' => bool, 'voucher_code' => string|null, 'already' => bool, 'error' => string|null).
 * voucher_code is null on an 'ok' result too when she already has a physical voucher (see the file docblock) - not
 * just on failure.
 */
function mamabeApproveSubmission(mysqli $conn, $id, $actorStaffId) {
    mamabeEnsureApprovalSchema($conn);

    $stmt = $conn->prepare("SELECT register_type, mama_id, baby_id, voucher_id, approved_at FROM mamabe_submission WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($registerType, $mamaId, $babyId, $existingVoucherId, $existingApprovedAt);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        return array('ok' => false, 'voucher_code' => null, 'already' => false, 'error' => 'Submission not found.');
    }

    if ($existingVoucherId !== null) {
        $stmt = $conn->prepare("SELECT voucher_code FROM mamabe_voucher WHERE id = ?");
        $stmt->bind_param('i', $existingVoucherId);
        $stmt->execute();
        $stmt->bind_result($code);
        $stmt->fetch();
        $stmt->close();
        return array('ok' => true, 'voucher_code' => $code, 'already' => true, 'error' => null);
    }

    if ($babyId === null) {
        return array('ok' => false, 'voucher_code' => null, 'already' => false,
                      'error' => 'This submission has no linked MamaBe record to assign a voucher to.');
    }

    $voucherType = $registerType === 'pink' ? MAMABE_VOUCHER_TYPE_MAMA : MAMABE_VOUCHER_TYPE_BABY;
    $babyColumn  = $registerType === 'pink' ? 'mama_voucher_id' : 'baby_voucher_id';

    // She already has a PHYSICAL voucher from registering at an outlet - never also hand out a digital code.
    // Mama side: mamabe_mama.receipt_no. Baby side: mamabe_baby.baby_receipt_no (that specific pregnancy/baby's
    // own receipt - a sibling baby row's receipt doesn't count, only this one's).
    $hasPhysicalVoucher = false;
    if ($registerType === 'pink' && $mamaId !== null) {
        $stmt = $conn->prepare("SELECT receipt_no FROM mamabe_mama WHERE id = ?");
        $stmt->bind_param('i', $mamaId);
        $stmt->execute();
        $stmt->bind_result($receiptNo);
        $stmt->fetch();
        $stmt->close();
        $hasPhysicalVoucher = !empty($receiptNo);
    } elseif ($registerType === 'baby') {
        $stmt = $conn->prepare("SELECT baby_receipt_no FROM mamabe_baby WHERE id = ?");
        $stmt->bind_param('i', $babyId);
        $stmt->execute();
        $stmt->bind_result($babyReceiptNo);
        $stmt->fetch();
        $stmt->close();
        $hasPhysicalVoucher = !empty($babyReceiptNo);
    }
    if ($hasPhysicalVoucher) {
        if ($existingApprovedAt === null) {
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE mamabe_submission SET approved_at = ?, approved_by = ? WHERE id = ?");
            $stmt->bind_param('sii', $now, $actorStaffId, $id);
            $stmt->execute();
            $stmt->close();

            // Best-effort - same as the normal approval email, just without a voucher code to show. Only fires
            // once (guarded by $existingApprovedAt above), not on every re-check of an already-approved row.
            if ($mamaId !== null) {
                $stmt = $conn->prepare("SELECT mama_name, email FROM mamabe_mama WHERE id = ?");
                $stmt->bind_param('i', $mamaId);
                $stmt->execute();
                $stmt->bind_result($mamaName, $mamaEmail);
                $stmt->fetch();
                $stmt->close();
                if (!empty($mamaEmail)) {
                    sendMamabePhysicalApprovalEmail($conn, $mamaEmail, $mamaName, $id);
                }
            }
        }
        return array('ok' => true, 'voucher_code' => null, 'already' => false, 'error' => null);
    }

    $conn->begin_transaction();
    try {
        // Oldest unassigned voucher of the matching type - recycle = 0 is the single source of truth for "still in
        // stock". FOR UPDATE so two approvals at the same moment (two staff, or staff + the AI worker) can't both
        // grab the same voucher. The NOT EXISTS guards skip a voucher that is still recycle = 0 but already linked to
        // a baby/submission (e.g. recycle reset by hand) - it would otherwise hit idx_mama_voucher_unique and block
        // every approval behind it.
        $stmt = $conn->prepare(
            "SELECT v.id, v.voucher_code FROM mamabe_voucher v
              WHERE v.type = ? AND v.recycle = 0
                AND NOT EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_voucher_id = v.id OR b.baby_voucher_id = v.id)
                AND NOT EXISTS (SELECT 1 FROM mamabe_submission s WHERE s.voucher_id = v.id)
              ORDER BY v.id LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('i', $voucherType);
        $stmt->execute();
        $stmt->bind_result($voucherId, $voucherCode);
        $gotVoucher = $stmt->fetch();
        $stmt->close();

        if (!$gotVoucher) {
            $conn->rollback();
            $label = $voucherType === MAMABE_VOUCHER_TYPE_MAMA ? 'Mama' : 'Baby';
            return array('ok' => false, 'voucher_code' => null, 'already' => false,
                          'error' => "No unassigned $label vouchers left in stock.");
        }

        // Mark it taken - recycle = 1 means "already assigned", not a soft-delete here.
        $stmt = $conn->prepare("UPDATE mamabe_voucher SET recycle = 1 WHERE id = ?");
        $stmt->bind_param('i', $voucherId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_voucher recycle stamp: ' . $stmt->error);
        $stmt->close();

        $stmt = $conn->prepare("UPDATE mamabe_baby SET $babyColumn = ? WHERE id = ?");
        $stmt->bind_param('ii', $voucherId, $babyId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_baby voucher stamp: ' . $stmt->error);
        $stmt->close();

        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE mamabe_submission SET approved_at = ?, approved_by = ?, voucher_id = ? WHERE id = ?");
        $stmt->bind_param('siii', $now, $actorStaffId, $voucherId, $id);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_submission approve: ' . $stmt->error);
        $stmt->close();

        $conn->commit();

        // Best-effort notification - a mail failure (or no email on file) must never undo the approval, which
        // already committed above. Only mamas with an email on file (staff-added, not from the public form) get one.
        if ($mamaId !== null) {
            $stmt = $conn->prepare("SELECT mama_name, email FROM mamabe_mama WHERE id = ?");
            $stmt->bind_param('i', $mamaId);
            $stmt->execute();
            $stmt->bind_result($mamaName, $mamaEmail);
            $stmt->fetch();
            $stmt->close();
            if (!empty($mamaEmail)) {
                sendMamabeApprovalEmail($conn, $mamaEmail, $mamaName, $voucherCode, $id);
            }
        }

        return array('ok' => true, 'voucher_code' => $voucherCode, 'already' => false, 'error' => null);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('mamabeApproveSubmission failed (submission id=' . (int)$id . ', actor=' . var_export($actorStaffId, true) . '): ' . $e->getMessage());
        // TEMPORARY while diagnosing a production-only failure: show the real reason in the popup itself, instead
        // of only the generic message, so it doesn't depend on finding the right server log. Revert this once fixed.
        return array('ok' => false, 'voucher_code' => null, 'already' => false, 'error' => 'Could not approve: ' . $e->getMessage());
    }
}
