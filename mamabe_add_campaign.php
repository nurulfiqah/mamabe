<?php
require_once('../lock_adv.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

$msg      = '';
$msg_type = '';

if (isset($_POST['save_campaign'])) {
    $duration   = (int)($_POST['duration'] ?? 0);
    $date_type  = (int)($_POST['date_type'] ?? 0);
    $event      = trim($_POST['event'] ?? '');
    $instruction = trim($_POST['instruction'] ?? '');
    $wa_bi      = trim($_POST['wa_bi'] ?? '');
    $wa_bm      = trim($_POST['wa_bm'] ?? '');
    $wa_cn      = trim($_POST['wa_cn'] ?? '');
    $operator   = isset($id_user) ? (int)$id_user : 0;
    $timestamp  = date('Y-m-d H:i:s');

    if (!isset($_POST['duration']) || $_POST['duration'] === '' || !$event || !$instruction) {
        $msg      = 'Please fill in all required fields (Duration, Event Name, Instruction).';
        $msg_type = 'danger';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO mamabe_campaign (duration, date_type, event, instruction, operator, recycle, timestamp)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        if (!$stmt) {
            $msg      = 'Database error: ' . $conn->error;
            $msg_type = 'danger';
        } else {
            $stmt->bind_param("iissis", $duration, $date_type, $event, $instruction, $operator, $timestamp);
            if ($stmt->execute()) {
                $campaign_id = $stmt->insert_id;
                $stmt->close();

                // Save WA messages
                $stmt2 = $conn->prepare("
                    INSERT INTO mamabe_wa (campaign_id, wa_bi, wa_bm, wa_cn, operator, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if ($stmt2) {
                    $stmt2->bind_param("isssss", $campaign_id, $wa_bi, $wa_bm, $wa_cn, $operator, $timestamp);
                    $stmt2->execute();
                    $stmt2->close();
                }

                header('Location: mamabe_campaign_list.php');
                exit;
            } else {
                $msg      = 'Database error. Please try again.';
                $msg_type = 'danger';
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Add Campaign</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.camp-layout   { display:flex; gap:20px; flex-wrap:wrap; }
.camp-left     { flex:0 0 340px; min-width:260px; }
.camp-right    { flex:1 1 400px; }
.camp-section-title { font-weight:bold; font-size:0.88em; color:#2a6496;
                      border-bottom:2px solid #d0dfe8; padding-bottom:5px; margin-bottom:12px; }
.wa-cols       { display:flex; gap:12px; flex-wrap:wrap; }
.wa-col        { flex:1 1 200px; border:1px solid #e0e0e0; border-radius:5px;
                 padding:12px; background:#fafafa; }
.wa-col-title  { font-weight:bold; font-size:0.85em; color:#fff; border-radius:3px;
                 padding:3px 10px; display:block; margin-bottom:8px; text-align:center; }
.wa-bi { background:#128C7E; }
.wa-bm { background:#2a6496; }
.wa-cn { background:#c82333; }
.camp-field    { margin-bottom:14px; }
.camp-field label { display:block; font-weight:600; font-size:0.88em; margin-bottom:4px; color:#444; }
</style>
<script type="text/javascript" src="../common/tiny_mce/tiny_mce.js"></script>
<script type="text/javascript">
tinyMCE.init({
    mode        : "specific_textareas",
    editor_selector : "wa-tinymce",
    theme       : "advanced",
    plugins     : "autolink,lists,style,layer,table,advhr,advimage,advlink,inlinepopups,insertdatetime,preview,media,searchreplace,print,contextmenu,paste,directionality,fullscreen,noneditable,visualchars,nonbreaking,xhtmlxtras,template,wordcount,advlist,visualblocks",
    theme_advanced_buttons1 : "undo,redo,|,bold,italic,underline,strikethrough,|,forecolor,backcolor,|,justifyleft,justifycenter,justifyright,justifyfull,|,bullist,numlist,|,fontselect,fontsizeselect",
    theme_advanced_buttons2 : "table,split_cells,merge_cells,|,hr,|,charmap,removeformat,|,styleselect,formatselect,|,replace,|,sub,sup,|,link,unlink,|,print,|,fullscreen,preview,code",
    theme_advanced_toolbar_location   : "top",
    theme_advanced_toolbar_align      : "left",
    theme_advanced_statusbar_location : "bottom",
    theme_advanced_resizing : true
});
</script>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/chat.png"> MamaBe – Add Campaign</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<div class="alpro-box">

  <?php if ($msg): ?>
  <div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="camp-layout">

      <!-- ── LEFT: Campaign Details ── -->
      <div class="camp-left">
        <div class="camp-section-title">Campaign Details</div>

        <div class="camp-field">
          <label>Duration <span style="color:red">*</span></label>
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <span style="font-weight:bold;font-size:0.9em">Day</span>
            <select class="alpro-input" name="duration" style="width:90px">
              <option value="">--</option>
              <option value="0" <?php echo (isset($_POST['duration']) && $_POST['duration'] === '0') ? 'selected' : ''; ?>>0</option>
              <?php for ($x = 1; $x <= 1095; $x++):
                $sel = (isset($_POST['duration']) && (int)$_POST['duration'] === $x) ? 'selected' : ''; ?>
              <option value="<?php echo $x; ?>" <?php echo $sel; ?>><?php echo $x; ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="camp-field">
          <label>Date Type <span style="color:red">*</span></label>
          <select class="alpro-input" name="date_type" style="width:100%; height:60px;">
            <option value="1" <?php echo (!isset($_POST['date_type']) || $_POST['date_type'] == 1) ? 'selected' : ''; ?>>FROM First Menstrual (Start Pregnancy)</option>
            <option value="2" <?php echo (isset($_POST['date_type']) && $_POST['date_type'] == 2) ? 'selected' : ''; ?>>FROM Birth Day (After Baby Give Birth)</option>
          </select>
        </div>

        <div class="camp-field">
          <label>Event Name <span style="color:red">*</span></label>
          <input class="alpro-input" type="text" name="event" style="width:100%"
                 value="<?php echo htmlspecialchars(isset($_POST['event']) ? $_POST['event'] : ''); ?>"
                 placeholder="e.g. Week 12 Check-up Reminder">
        </div>

        <div class="camp-field">
          <label>Instruction <span style="color:red">*</span></label>
          <textarea class="alpro-input" name="instruction" rows="6"
                    style="width:100%;resize:vertical"
                    placeholder="Internal notes or instructions..."><?php echo htmlspecialchars(isset($_POST['instruction']) ? $_POST['instruction'] : ''); ?></textarea>
        </div>

        <div class="alpro-actions" style="margin-top:6px">
          <input class="alpro-btn alpro-btn-blue" type="submit" name="save_campaign" value="Save Campaign">
          <a class="alpro-btn alpro-btn-grey" href="mamabe_campaign_list.php">Back</a>
        </div>
      </div>

      <!-- ── RIGHT: WhatsApp Messages (3 columns) ── -->
      <div class="camp-right">
        <div class="camp-section-title">WhatsApp Messages</div>
        <div class="wa-cols">

          <div class="wa-col">
            <span class="wa-col-title wa-bi">BI – English</span>
            <textarea class="alpro-input wa-tinymce" name="wa_bi" rows="12"
                      style="width:100%;resize:vertical"
                      placeholder="Message in English..."><?php echo htmlspecialchars(isset($_POST['wa_bi']) ? $_POST['wa_bi'] : ''); ?></textarea>
          </div>

          <div class="wa-col">
            <span class="wa-col-title wa-bm">BM – Bahasa</span>
            <textarea class="alpro-input wa-tinymce" name="wa_bm" rows="12"
                      style="width:100%;resize:vertical"
                      placeholder="Mesej dalam BM..."><?php echo htmlspecialchars(isset($_POST['wa_bm']) ? $_POST['wa_bm'] : ''); ?></textarea>
          </div>

          <div class="wa-col">
            <span class="wa-col-title wa-cn">Chinese – 中文</span>
            <textarea class="alpro-input wa-tinymce" name="wa_cn" rows="12"
                      style="width:100%;resize:vertical"
                      placeholder="中文讯息..."><?php echo htmlspecialchars(isset($_POST['wa_cn']) ? $_POST['wa_cn'] : ''); ?></textarea>
          </div>

        </div>
      </div>

    </div>
  </form>
</div>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
