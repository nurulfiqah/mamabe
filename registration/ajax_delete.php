<?php
/**
 * Delete one whole registration EVENT (every mamabe_submission row sharing its batch - mother + baby/babies of a
 * 'both' registration, or the single row of a standalone one). Admin (staff.mamab = 1) only - see mamabe_can_delete().
 *   - Refused while any row of it has a voucher assigned (a given-out code must stay traceable) or is mid-AI-check
 *     (worker.php would still write its result/log for it).
 *   - Hard-deletes the mamabe_submission rows (+ their mamabe_ai_log rows) and the locally stored images.
 *   - Same rule as ajax_reject.php for MamaBe records: soft-deletes (recycle = 1) only a mamabe_baby / mamabe_mama row
 *     this registration itself CREATED (baby_is_new / mama_is_new = 1) - never an existing customer's record it only
 *     matched. Extra guards since a whole event goes: a record another (remaining) submission also points at is left
 *     alone, and the mama is only hidden once she has no other active baby rows left.
 * POST: id (any mamabe_submission.id in the event), csrf_token. Response: {"ok":true} | {"ok":false,"error":"..."}
 */
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed.'));
    exit;
}
MamabeCsrf::verify();

if (!mamabe_can_delete(isset($mamab_autho) ? $mamab_autho : 0)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Only Admin can delete registrations.'));
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

$stmt = $conn->prepare("SELECT COALESCE(batch_id, id) FROM mamabe_submission WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($bkey);
$found = $stmt->fetch();
$stmt->close();
if (!$found) {
    echo json_encode(array('ok' => false, 'error' => 'Registration not found (it may already be deleted).'));
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT id, mama_id, mama_is_new, baby_id, baby_is_new, voucher_id, status, pink_file, baby_file
                              FROM mamabe_submission WHERE COALESCE(batch_id, id) = ? FOR UPDATE");
    $stmt->bind_param('i', $bkey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $ids = $files = $newBabies = $newMamas = array();
    foreach ($rows as $r) {
        if ($r['voucher_id'] !== null) {
            $conn->rollback();
            echo json_encode(array('ok' => false, 'error' => 'This registration already has a voucher assigned - it cannot be deleted.'));
            exit;
        }
        if ((int)$r['status'] === MamabeVerifier::STATUS_CHECKING) {
            $conn->rollback();
            echo json_encode(array('ok' => false, 'error' => 'The AI is checking this registration right now - try again in a minute.'));
            exit;
        }
        $ids[] = (int)$r['id'];
        foreach (array('pink_file', 'baby_file') as $f) if (!empty($r[$f])) $files[] = basename($r[$f]);
        if ((int)$r['baby_is_new'] === 1 && $r['baby_id'] !== null) $newBabies[(int)$r['baby_id']] = true;
        if ((int)$r['mama_is_new'] === 1 && $r['mama_id'] !== null) $newMamas[(int)$r['mama_id']] = true;
    }
    $idList = implode(',', $ids);   // ints only

    // Records another submission (outside this event) also points at are shared - leave them.
    $referencedElsewhere = function ($col, $val) use ($conn, $idList) {
        $stmt = $conn->prepare("SELECT 1 FROM mamabe_submission WHERE $col = ? AND id NOT IN ($idList) LIMIT 1");
        $stmt->bind_param('i', $val);
        $stmt->execute();
        $hit = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $hit;
    };

    foreach (array_keys($newBabies) as $babyId) {
        if ($referencedElsewhere('baby_id', $babyId)) continue;
        $stmt = $conn->prepare("UPDATE mamabe_baby SET recycle = 1 WHERE id = ?");
        $stmt->bind_param('i', $babyId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_baby hide: ' . $stmt->error);
        $stmt->close();
    }

    foreach (array_keys($newMamas) as $mamaId) {
        if ($referencedElsewhere('mama_id', $mamaId)) continue;
        // Staff may have added babies to her by hand since - she stays visible while any active baby row remains.
        $stmt = $conn->prepare("SELECT 1 FROM mamabe_baby WHERE mama_id = ? AND recycle = 0 LIMIT 1");
        $stmt->bind_param('i', $mamaId);
        $stmt->execute();
        $hasBabies = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($hasBabies) continue;
        $stmt = $conn->prepare("UPDATE mamabe_mama SET recycle = 1 WHERE id = ?");
        $stmt->bind_param('i', $mamaId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_mama hide: ' . $stmt->error);
        $stmt->close();
    }

    // mamabe_ai_log has ON DELETE CASCADE in the shipped schema; deleted explicitly too in case a server lacks the FK.
    $conn->query("DELETE FROM mamabe_ai_log WHERE submission_id IN ($idList)");
    if (!$conn->query("DELETE FROM mamabe_submission WHERE id IN ($idList)")) {
        throw new RuntimeException('mamabe_submission delete: ' . $conn->error);
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('mamabe delete failed (submission id=' . $id . ', actor=' . (int)$id_user . '): ' . $e->getMessage());
    echo json_encode(array('ok' => false, 'error' => 'Could not delete. Please try again.'));
    exit;
}

// Local image copies only - the NAS copy belongs to the (now hidden) MamaBe record and is left as-is.
foreach (array_unique($files) as $f) {
    $stmt = $conn->prepare("SELECT 1 FROM mamabe_submission WHERE pink_file = ? OR baby_file = ? LIMIT 1");
    $stmt->bind_param('ss', $f, $f);
    $stmt->execute();
    $stillUsed = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$stillUsed && is_file(MAMABE_UPLOAD_DIR . $f)) @unlink(MAMABE_UPLOAD_DIR . $f);
}

error_log('mamabe delete: registration ' . $bkey . ' (submission ids ' . $idList . ') deleted by staff ' . (int)$id_user);
echo json_encode(array('ok' => true));
