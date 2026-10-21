<?php
require_once('../lock_adv.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

/*
SQL – run once:

CREATE TABLE mamabe_blast_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    mama_id INT UNSIGNED NOT NULL,
    baby_id INT UNSIGNED NOT NULL DEFAULT 0,
    blast_date DATE NOT NULL,
    wa_sent INT(1) NOT NULL DEFAULT 0,
    wa_op INT(10) NOT NULL DEFAULT 0,
    wa_datetime DATETIME,
    timestamp DATETIME NOT NULL,
    UNIQUE KEY unique_blast (campaign_id, mama_id, baby_id, blast_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$campaign_id) {
    header('Location: mamabe_campaign_list.php');
    exit;
}

$blast_date = date('Y-m-d'); // always today – used for new log entries and export

// Load campaign
$stmt = $conn->prepare("SELECT * FROM mamabe_campaign WHERE id=? AND recycle=0 LIMIT 1");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$campaign = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$campaign) {
    echo '<p style="padding:20px">Campaign not found.</p>';
    $connect = 0;
    include('../common/index_adv.php');
    exit;
}

// Load WA messages for this campaign
$stmt = $conn->prepare("SELECT wa_bi, wa_bm, wa_cn FROM mamabe_wa WHERE campaign_id=? LIMIT 1");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$wa = $stmt->get_result()->fetch_assoc();
$stmt->close();

$date_type = (int)$campaign['date_type'];
$duration  = (int)$campaign['duration'];

// Build query – show ALL mamas eligible up to and including today
// (includes past dates that were never blasted)
if ($date_type == 1) {
    // Day N or more FROM First Menstrual (LMP)
    // Also ensure mama registered before reaching day N (so she doesn't receive past campaigns)
    $sql = "
        SELECT m.id AS mama_id, m.mama_name, m.phone, m.language,
               b.id AS baby_id, b.lmp AS ref_date, b.edd, b.baby_name, b.is_delivered,
               m.outlet_id, IFNULL(o.comp_name, '– No Outlet –') AS outlet_name
        FROM mamabe_mama m
        JOIN mamabe_baby b ON b.mama_id = m.id
        LEFT JOIN outlet o ON o.id = m.outlet_id
        WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered != 2
          AND b.lmp IS NOT NULL AND b.lmp > '1000-01-01'
          AND DATEDIFF(CURDATE(), b.lmp) >= ?
          AND DATEDIFF(DATE(m.timestamp), b.lmp) <= ?
        ORDER BY b.lmp DESC
    ";
} elseif ($date_type == 2) {
    // Day N or more FROM Birth Date
    // Also ensure mama registered before reaching day N post-birth
    $sql = "
        SELECT m.id AS mama_id, m.mama_name, m.phone, m.language,
               b.id AS baby_id, b.dob AS ref_date, b.baby_name, b.is_delivered,
               '' AS edd, m.outlet_id, IFNULL(o.comp_name, '– No Outlet –') AS outlet_name
        FROM mamabe_mama m
        JOIN mamabe_baby b ON b.mama_id = m.id
        LEFT JOIN outlet o ON o.id = m.outlet_id
        WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered=1
          AND b.dob IS NOT NULL AND b.dob > '1000-01-01'
          AND DATEDIFF(CURDATE(), b.dob) >= ?
          AND DATEDIFF(DATE(m.timestamp), b.dob) <= ?
        ORDER BY b.dob DESC
    ";
} else {
    // Day N or less BEFORE EDD (includes overdue)
    // Also ensure mama registered while EDD was still >= N days away
    $sql = "
        SELECT m.id AS mama_id, m.mama_name, m.phone, m.language,
               b.id AS baby_id, b.edd AS ref_date, b.lmp, b.baby_name, b.is_delivered,
               m.outlet_id, IFNULL(o.comp_name, '– No Outlet –') AS outlet_name
        FROM mamabe_mama m
        JOIN mamabe_baby b ON b.mama_id = m.id
        LEFT JOIN outlet o ON o.id = m.outlet_id
        WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered != 2
          AND b.edd IS NOT NULL AND b.edd > '1000-01-01'
          AND DATEDIFF(b.edd, CURDATE()) <= ?
          AND DATEDIFF(b.edd, DATE(m.timestamp)) >= ?
        ORDER BY b.edd DESC
    ";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $duration, $duration);
$stmt->execute();
$contacts_res = $stmt->get_result();
$contacts = array();
while ($row = $contacts_res->fetch_assoc()) {
    $contacts[] = $row;
}
$stmt->close();

// Load blast log for this campaign – all dates, keep the latest sent record per mama+baby
$blast_log = array();
// wa_op = operator of the latest sent row (ONLY_FULL_GROUP_BY-safe, instead of picking an arbitrary row's wa_op)
$bl = $conn->query("
    SELECT x.mama_id, x.baby_id, x.wa_sent, x.wa_op, x.wa_datetime,
           s.nama_staff AS op_name
    FROM (
        SELECT bl.mama_id, bl.baby_id, MAX(bl.wa_sent) AS wa_sent,
               SUBSTRING_INDEX(GROUP_CONCAT(bl.wa_op ORDER BY bl.wa_sent DESC, bl.wa_datetime DESC), ',', 1) AS wa_op,
               MAX(bl.wa_datetime) AS wa_datetime
        FROM mamabe_blast_log bl
        WHERE bl.campaign_id = $campaign_id
        GROUP BY bl.mama_id, bl.baby_id
    ) x
    LEFT JOIN staff s ON s.id = x.wa_op
");
if ($bl) {
    while ($b = $bl->fetch_assoc()) {
        $blast_log[$b['mama_id'] . '_' . $b['baby_id']] = $b;
    }
}

$total  = count($contacts);
$sent   = 0;
foreach ($contacts as $c) {
    $key = $c['mama_id'] . '_' . $c['baby_id'];
    if (isset($blast_log[$key]) && $blast_log[$key]['wa_sent'] == 1) {
        $sent++;
    }
}
$unsent = $total - $sent;

// Build outlet summary — grouped by outlet, today's exported contacts only
$outlet_groups = array();
foreach ($contacts as $c) {
    $key     = $c['mama_id'] . '_' . $c['baby_id'];
    $log_row = isset($blast_log[$key]) ? $blast_log[$key] : null;
    if (!$log_row || $log_row['wa_sent'] != 1) { continue; }
    // Only include contacts exported today
    if (!$log_row['wa_datetime'] || substr($log_row['wa_datetime'], 0, 10) !== $blast_date) { continue; }
    $oname = isset($c['outlet_name']) ? $c['outlet_name'] : '– No Outlet –';
    if (!isset($outlet_groups[$oname])) {
        $outlet_groups[$oname] = array(
            'outlet'    => $oname,
            'qty'       => 0,
            'timestamp' => $log_row['wa_datetime'],
            'op_name'   => isset($log_row['op_name']) ? $log_row['op_name'] : '',
            'contacts'  => array(),
        );
    }
    $outlet_groups[$oname]['qty']++;
    if ($log_row['wa_datetime'] > $outlet_groups[$oname]['timestamp']) {
        $outlet_groups[$oname]['timestamp'] = $log_row['wa_datetime'];
    }
    $outlet_groups[$oname]['contacts'][] = array(
        'mama_id' => (int)$c['mama_id'],
        'baby_id' => (int)$c['baby_id'],
    );
}
ksort($outlet_groups);

$date_type_label = array(1 => 'First Menstrual', 2 => 'Birth Date', 3 => 'EDD');
$date_dir_label  = array(1 => 'FROM', 2 => 'FROM', 3 => 'BEFORE');

function mamabe_get_wa($wa, $lang) {
    if (!$wa) { return ''; }
    if ($lang == 1) { return isset($wa['wa_bi']) ? $wa['wa_bi'] : ''; }
    if ($lang == 2) { return isset($wa['wa_bm']) ? $wa['wa_bm'] : ''; }
    if ($lang == 3) { return isset($wa['wa_cn']) ? $wa['wa_cn'] : ''; }
    // Tamil fallback → BM
    return isset($wa['wa_bm']) ? $wa['wa_bm'] : '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Campaign Blast</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.counter-box { display:block; background:#f6f6f6; padding:50px 30px; text-align:center; }
.counter-box p { margin:5px 0 0; padding:0; color:#fff; font-size:18px; font-weight:500; }
.counter { display:block; font-size:60px; font-weight:700; color:#fff; line-height:1.1; margin:10px 0; }
.counter-box.colored  { background:#1aad06; }
.counter-box.colored2 { background:#dd4b39; }
.counter-box.colored3 { background:#3b5998; }
.alpro-table td  { vertical-align:middle; padding:5px 7px; }
.wa-btn { display:inline-block; padding:3px 9px; border-radius:3px; color:#128C7E; font-size:0.82em;
          font-weight:bold; cursor:pointer; text-decoration:none; margin:1px; border:none; background:transparent; }
.sent-badge   { color:#128C7E; font-weight:bold; font-size:0.85em; }
.unsent-badge { color:#aaa; font-size:0.85em; }
.campaign-info { background:#f4f8fc; border:1px solid #d0dfe8; border-radius:5px;
                 padding:10px 16px; margin-bottom:10px; font-size:0.92em; }
.campaign-info b { color:#2a6496; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../common/img/wa.png"> MamaBe – Campaign Blast</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<fieldset>

  <div class="campaign-info">
    <b><?php echo htmlspecialchars($campaign['event'] ?? ''); ?></b>
    &nbsp;|&nbsp;
    Day <b><?php echo (int)$campaign['duration']; ?></b>
    <?php echo isset($date_dir_label[$date_type]) ? $date_dir_label[$date_type] : ''; ?>
    <?php echo isset($date_type_label[$date_type]) ? $date_type_label[$date_type] : ''; ?>
    &nbsp;|&nbsp;
    <?php echo nl2br(htmlspecialchars($campaign['instruction'] ?? '')); ?>
  </div>

  <a class="alpro-btn alpro-btn-grey" href="mamabe_campaign_list.php">Back</a>

  <table width="100%" border="0" cellspacing="2" cellpadding="0" style="margin-top:10px;">
    <tr>
      <td width="33%"><div class="counter-box colored">
        <span class="counter"><?php echo $sent; ?></span>
        <p>Sent</p>
        <p>(<?php echo ($total > 0) ? number_format($sent/$total*100,1) : '0.0'; ?>%)</p>
      </div></td>
      <td width="33%"><div class="counter-box colored2">
        <span class="counter"><?php echo $unsent; ?></span>
        <p>Unsent</p>
        <p>(<?php echo ($total > 0) ? number_format($unsent/$total*100,1) : '0.0'; ?>%)</p>
      </div></td>
      <td width="33%"><div class="counter-box colored3">
        <span class="counter"><?php echo $total; ?></span>
        <p>Total</p>
        <p>(100%)</p>
      </div></td>
    </tr>
  </table>

  <?php if ($total > 0 && $wa): ?>
  <form method="post" action="mamabe_blast_export.php" target="_blank">
    <input type="hidden" name="campaign_id" value="<?php echo $campaign_id; ?>">
    <input type="hidden" name="blast_date" value="<?php echo htmlspecialchars($blast_date); ?>">
    <input type="hidden" name="op" value="<?php echo isset($id_user) ? (int)$id_user : 0; ?>">
    Sending interval= <input type="number" name="send_min" min="5" value="8" style="width:50px"> - <input type="number" name="send_max" min="10" value="20" style="width:50px"><br>
    Picture interval= <input type="number" name="pic_min" min="1" value="2" style="width:50px"> - <input type="number" name="pic_max" min="1" value="5" style="width:50px"><br>
    max message per session = <input type="number" name="max_session" min="1" max="500" value="100" style="width:60px"><br>
    <br>
    <input type="submit" value="Export data.js">
  </form>
  <?php endif; ?>

</fieldset>

<?php if (!empty($outlet_groups)): ?>
<fieldset>
  <legend><b>Summary by Outlet – <?php echo htmlspecialchars($blast_date); ?></b></legend>
  <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
      <th width="30">#</th>
      <th>Outlet</th>
      <th width="60" style="text-align:center">Qty</th>
      <th width="140" style="text-align:center">Timestamp</th>
      <th>Exported By</th>
      <th width="60" style="text-align:center">Action</th>
    </tr>
    <?php $oi = 1; foreach ($outlet_groups as $og): ?>
    <tr id="orow_<?php echo md5($og['outlet']); ?>">
      <td class="alpro-center"><?php echo $oi++; ?></td>
      <td><?php echo htmlspecialchars($og['outlet']); ?></td>
      <td class="alpro-center"><b><?php echo $og['qty']; ?></b></td>
      <td class="alpro-center" style="font-size:0.85em;color:#888;"><?php echo htmlspecialchars($og['timestamp']); ?></td>
      <td style="font-size:0.85em;color:#555;">
        <?php echo $og['op_name'] !== '' ? htmlspecialchars($og['op_name']) : '<span class="alpro-muted">–</span>'; ?>
      </td>
      <td class="alpro-center">
        <a href="javascript:void(0);" style="font-size:0.78em;color:#c82333;"
           onclick="markUnsentOutlet(<?php echo htmlspecialchars(json_encode($og['contacts']), ENT_QUOTES); ?>, '<?php echo md5($og['outlet']); ?>')">Undo</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</fieldset>
<?php endif; ?>

<script>
var blast_date = '<?php echo $blast_date; ?>';
var campaign_id = <?php echo $campaign_id; ?>;
var id_user = <?php echo isset($id_user) ? (int)$id_user : 0; ?>;

function getXMLHTTP() {
    if (window.XMLHttpRequest) { return new XMLHttpRequest(); }
    return new ActiveXObject("Microsoft.XMLHTTP");
}


function markUnsentOutlet(contacts, rowKey) {
    if (!confirm('Undo all ' + contacts.length + ' contact(s) from this outlet?')) { return; }
    var done = 0;
    for (var i = 0; i < contacts.length; i++) {
        (function(mama_id, baby_id) {
            var xmlhttp = getXMLHTTP();
            xmlhttp.onreadystatechange = function() {
                if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
                    done++;
                    if (done >= contacts.length) {
                        var orow = document.getElementById('orow_' + rowKey);
                        if (orow) { orow.parentNode.removeChild(orow); }
                    }
                }
            };
            xmlhttp.open('GET', 'mamabe_blast_ajax.php?action=unsent&campaign_id=' + campaign_id
                + '&mama_id=' + mama_id + '&baby_id=' + baby_id
                + '&blast_date=' + blast_date + '&u=' + id_user, true);
            xmlhttp.send();
        })(contacts[i].mama_id, contacts[i].baby_id);
    }
}
</script>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
