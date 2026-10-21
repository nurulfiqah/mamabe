<?php
/**
 * Ping worker.php without waiting for it (used by api/register.php after a submission and by ajax_reread.php).
 * Fire-and-forget: we drop the connection after a moment (Apache may hold it open until the worker finishes) and the
 * worker carries on regardless, because it runs with ignore_user_abort(true) - so this timeout only bounds how long
 * the caller waits. If the ping fails the row simply stays queued for the next submission or a scheduled worker run.
 */
function mamabe_kick_worker() {
    $ch = curl_init(MAMABE_WORKER_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST             => true,
        CURLOPT_POSTFIELDS       => '',
        CURLOPT_HTTPHEADER       => array('X-Mamabe-Token: ' . MAMABE_WORKER_TOKEN),
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
        CURLOPT_TIMEOUT_MS       => 400,
        CURLOPT_NOSIGNAL         => 1,
    ));
    curl_exec($ch);
}
