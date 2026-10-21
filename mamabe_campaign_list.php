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

CREATE TABLE mamabe_campaign (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    duration INT NOT NULL DEFAULT 1,
    date_type INT(1) NOT NULL DEFAULT 1,
    event VARCHAR(200) NOT NULL,
    instruction TEXT NOT NULL,
    operator INT(10) NOT NULL DEFAULT 0,
    recycle INT(1) NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mamabe_wa (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    wa_bi TEXT NOT NULL,
    wa_bm TEXT NOT NULL,
    wa_cn TEXT NOT NULL,
    operator INT(10) NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

// Handle delete
$del_id = isset($_GET['del']) ? (int)$_GET['del'] : 0;
if ($del_id) {
    $stmt = $conn->prepare("UPDATE mamabe_campaign SET recycle=1 WHERE id=?");
    $stmt->bind_param("i", $del_id);
    $stmt->execute();
    $stmt->close();
    header('Location: mamabe_campaign_list.php');
    exit;
}

function clean_wa($text) {
    $text = str_replace(array('<br />', '<br/>', '<br>'), "\n", $text);
    $text = strip_tags($text);
    return htmlspecialchars(trim($text));
}

$date_type_map = array(1 => 'First Menstrual', 2 => 'Birth Date', 3 => 'EDD');
$date_dir_map  = array(1 => 'FROM', 2 => 'FROM', 3 => 'BEFORE');

// Load all campaigns
$campaigns = $conn->query("
    SELECT id, duration, date_type, event, instruction, timestamp
    FROM mamabe_campaign
    WHERE recycle = 0
    ORDER BY date_type ASC, duration ASC
");

// Load all WA records keyed by campaign_id
$wa_map = array();
$wr = $conn->query("SELECT campaign_id, wa_bi, wa_bm, wa_cn FROM mamabe_wa ORDER BY id ASC");
if ($wr) {
    while ($w = $wr->fetch_assoc()) {
        $wa_map[$w['campaign_id']] = $w;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Campaign List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.alpro-table td { vertical-align:top; padding:6px 8px; }
.wa-block { border:1px solid #e0e0e0; border-radius:4px; padding:8px 10px; margin-bottom:6px; background:#f9f9f9; }
.wa-block:last-child { margin-bottom:0; }
.wa-lang  { font-weight:bold; font-size:0.8em; color:#fff; background:#128C7E; border-radius:3px; padding:1px 6px; display:inline-block; margin-bottom:4px; }
.wa-lang.bm  { background:#2a6496; }
.wa-lang.cn  { background:#c82333; }
.wa-msg  { white-space:pre-wrap; font-size:0.88em; color:#333; max-height:120px; overflow-y:auto; }
.instr-cell { font-size:0.88em; color:#555; max-height:100px; overflow-y:auto; }
.dur-cell   { text-align:center; font-weight:bold; color:#2a6496; }
.dur-sub    { font-size:0.8em; color:#888; font-weight:normal; }
.no-wa      { color:#bbb; font-size:0.85em; font-style:italic; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/chat.png"> MamaBe – Campaign List</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>


<fieldset>
  <legend><b>Campaign List</b></legend>
  <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
      <th width="30">#</th>
      <th width="160">Duration</th>
      <th width="160">Event Name</th>
      <th width="200">Instruction</th>
      <th>WhatsApp Messages</th>
      <th width="60">Action</th>
    </tr>

    <?php if ($campaigns && $campaigns->num_rows > 0):
      $i = 1;
      while ($row = $campaigns->fetch_assoc()):
        $dt_label = isset($date_type_map[$row['date_type']]) ? $date_type_map[$row['date_type']] : '-';
        $dt_dir   = isset($date_dir_map[$row['date_type']])  ? $date_dir_map[$row['date_type']]  : '';
        $wa       = isset($wa_map[$row['id']]) ? $wa_map[$row['id']] : null;
    ?>
    <tr>
      <td class="alpro-center"><?php echo $i++; ?></td>

      <td class="dur-cell">
        Day <?php echo (int)$row['duration']; ?>
        <span class="dur-sub"><br><?php echo $dt_dir; ?> <?php echo $dt_label; ?></span>
      </td>

      <td>
        <strong><?php echo htmlspecialchars($row['event'] ?? ''); ?></strong>
        <div style="font-size:0.8em;color:#aaa;margin-top:3px"><?php echo htmlspecialchars($row['timestamp'] ?? ''); ?></div>
      </td>

      <td>
        <div class="instr-cell"><?php echo nl2br(htmlspecialchars($row['instruction'] ?? '')); ?></div>
      </td>

      <td>
        <?php if ($wa): ?>
          <?php if ($wa['wa_bi'] != ''): ?>
          <div class="wa-block">
            <span class="wa-lang">BI (English)</span>
            <div class="wa-msg"><?php echo clean_wa($wa['wa_bi']); ?></div>
          </div>
          <?php endif; ?>
          <?php if ($wa['wa_bm'] != ''): ?>
          <div class="wa-block">
            <span class="wa-lang bm">BM (Bahasa)</span>
            <div class="wa-msg"><?php echo clean_wa($wa['wa_bm']); ?></div>
          </div>
          <?php endif; ?>
          <?php if ($wa['wa_cn'] != ''): ?>
          <div class="wa-block">
            <span class="wa-lang cn">Chinese</span>
            <div class="wa-msg"><?php echo clean_wa($wa['wa_cn']); ?></div>
          </div>
          <?php endif; ?>
          <?php if ($wa['wa_bi'] == '' && $wa['wa_bm'] == '' && $wa['wa_cn'] == ''): ?>
          <span class="no-wa">No WhatsApp message set.</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="no-wa">No WhatsApp message set.</span>
        <?php endif; ?>
      </td>

      <td class="alpro-center" style="white-space:nowrap;">
        <div style="display:flex; flex-direction:column; gap:7px; align-items:center;">
          <a href="mamabe_blast.php?id=<?php echo (int)$row['id']; ?>" title="Blast">
            <img src="images/chat.png" style="width:20px;vertical-align:middle;">
          </a>
          <a href="mamabe_edit_campaign.php?id=<?php echo (int)$row['id']; ?>" title="Edit">
            <img src="images/edit.png" style="width:20px;vertical-align:middle;">
          </a>
          <a href="mamabe_campaign_list.php?del=<?php echo (int)$row['id']; ?>" title="Delete"
             onclick="return confirm('Delete this campaign?');">
            <img src="images/delete.png" style="width:20px;vertical-align:middle;">
          </a>
        </div>
      </td>
    </tr>
    <?php endwhile; else: ?>
    <tr><td colspan="6" class="alpro-center alpro-muted">No campaign created yet.</td></tr>
    <?php endif; ?>
  </table>
</fieldset>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
