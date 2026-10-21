<?php
/**
 * Verification worker: copies each new submission's images to the NAS and links them on mamabe_baby
 * (MamabeRecords::syncPendingToNas - failures are retried on the next run), then reads the names off the images
 * (AI vision) and marks each submission proceed / pending. A row that comes back Proceed is approved immediately -
 * mamabeApproveSubmission() assigns it a voucher with no staff action needed; a Pending row is left for a human to
 * review on registration/index.php as before. Processes ONE image at a time because the shared AI server handles a
 * single image request at a time - parallel calls only queue up and time out.
 *
 * Started by api/register.php (fire-and-forget HTTP ping from loopback, with the token), or by hand / a
 * scheduled task as a catch-up:   php <path>/worker.php
 */
ignore_user_abort(true);
set_time_limit(0);
error_reporting(0);
ini_set('memory_limit', '512M');

require_once dirname(__FILE__) . '/common/config.php';
require_once dirname(__FILE__) . '/common/lib/MamabeVerifier.php';
require_once dirname(__FILE__) . '/common/lib/MamabeRecords.php';
require_once dirname(__FILE__) . '/common/lib/MamabeApproval.php';

if (PHP_SAPI !== 'cli') {
    $peer  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $token = isset($_SERVER['HTTP_X_MAMABE_TOKEN']) ? $_SERVER['HTTP_X_MAMABE_TOKEN'] : '';
    if (!in_array($peer, array('127.0.0.1', '::1'), true) || !hash_equals(MAMABE_WORKER_TOKEN, $token)) {
        http_response_code(403);
        exit;
    }
    // Answer the caller right away, then keep working in the background.
    ob_start();
    echo 'ok';
    header('Content-Length: ' . ob_get_length());
    header('Connection: close');
    ob_end_flush();
    flush();
}

$conn   = mamabe_db();
$lockFp = fopen(MAMABE_UPLOAD_DIR . '.worker.lock', 'c');
if (!$lockFp) exit;

for (;;) {
    // Someone else is already draining the queue and will pick up anything new.
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) break;

    // Reload admin-editable check settings (Settings tab) before draining, so a change applies without a restart.
    MamabeVerifier::loadSettings($conn);

    // We hold the lock, so any row still 'checking' belongs to a worker that died - put it back.
    $conn->query("UPDATE mamabe_submission SET status = " . MamabeVerifier::STATUS_QUEUED
               . " WHERE status = " . MamabeVerifier::STATUS_CHECKING);

    // NAS first: the photo shows on the MamaBe mama page within seconds, not after the (slow) AI check.
    // Again after each check, so a submission that arrives while the queue drains doesn't wait for the whole queue.
    MamabeRecords::syncPendingToNas($conn);
    while ($row = MamabeVerifier::claimNext($conn)) {
        MamabeVerifier::process($conn, $row);

        // Proceed = the AI check passed cleanly - approve straight away, no staff click needed. approved_by is
        // NULL here (not a person) so registration/index.php can tell an auto-approval apart from a manual one.
        $res = $conn->query("SELECT status FROM mamabe_submission WHERE id = " . (int)$row['id']);
        $status = $res ? (int)$res->fetch_row()[0] : null;
        if ($status === MamabeVerifier::STATUS_PROCEED) {
            mamabeApproveSubmission($conn, $row['id'], null);
        }

        MamabeRecords::syncPendingToNas($conn);
    }

    flock($lockFp, LOCK_UN);
    // A submission may have arrived after the last claim but before the unlock (its ping saw the lock held).
    if (!MamabeVerifier::hasQueued($conn)) break;
}

$conn->close();
