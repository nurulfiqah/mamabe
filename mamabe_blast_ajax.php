<?php
$connect = 1;
include('../common/index_adv.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '-';
    exit;
}
header('Content-Type: text/html; charset=UTF-8');

$action      = isset($_GET['action'])      ? trim($_GET['action'])      : '';
$campaign_id = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$mama_id     = isset($_GET['mama_id'])     ? (int)$_GET['mama_id']     : 0;
$baby_id     = isset($_GET['baby_id'])     ? (int)$_GET['baby_id']     : 0;
$blast_date  = (isset($_GET['blast_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['blast_date']))
               ? $_GET['blast_date'] : date('Y-m-d');
$op          = isset($_GET['u'])           ? (int)$_GET['u']           : 0;
$now         = date('Y-m-d H:i:s');

if (!$campaign_id || !$mama_id) {
    echo '-';
    exit;
}

if ($action === 'sent') {
    // INSERT or UPDATE to wa_sent=1
    $stmt = $conn->prepare("
        INSERT INTO mamabe_blast_log (campaign_id, mama_id, baby_id, blast_date, wa_sent, wa_op, wa_datetime, timestamp)
        VALUES (?, ?, ?, ?, 1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE wa_sent=1, wa_op=?, wa_datetime=?
    ");
    $stmt->bind_param("iiisissis", $campaign_id, $mama_id, $baby_id, $blast_date, $op, $now, $now, $op, $now);
    $stmt->execute();
    $stmt->close();

    echo '<span class="sent-badge">&#10003; Sent</span><br>'
       . '<span style="font-size:0.78em;color:#888">' . $now . '</span>'
       . '<br><a href="javascript:void(0);" style="font-size:0.78em;color:#c82333"'
       . ' onclick="markUnsent(' . $mama_id . ',' . $baby_id . ')">Undo</a>';

} elseif ($action === 'unsent') {
    $stmt = $conn->prepare("
        UPDATE mamabe_blast_log SET wa_sent=0, wa_op=?, wa_datetime=NULL
        WHERE campaign_id=? AND mama_id=? AND baby_id=? AND blast_date=?
    ");
    $stmt->bind_param("iiiis", $op, $campaign_id, $mama_id, $baby_id, $blast_date);
    $stmt->execute();
    $stmt->close();

    echo '<span class="unsent-badge">&#8211; Unsent</span>';
}
exit;
?>
