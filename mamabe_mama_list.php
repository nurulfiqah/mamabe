<?php
require_once('../lock_adv.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

$lang_map = array(1 => 'English', 2 => 'BM', 3 => 'Chinese', 4 => 'Tamil');

// ── Access control ──
// mamab_autho=1 → admin (see all). Otherwise → outlet staff (see own outlet only).
$_is_admin    = (isset($mamab_autho) && ($mamab_autho == '1' || $mamab_autho == '3'));
$_staff_outlets = array();
if (!$_is_admin && !empty($outlet_autho)) {
    $_staff_outlets = array_filter(array_map('intval', explode(',', $outlet_autho)));
}
$_outlet_in = !empty($_staff_outlets) ? implode(',', $_staff_outlets) : '';
// ALPRO BABY outlets are always visible to all outlet staff
$_alpro_baby_ids = " OR outlet_id IN (SELECT id FROM outlet WHERE recycle=0 AND comp_name LIKE '%ALPRO BABY%')";

// ── Stat cards ──
$_s_mama = (!$_is_admin && $_outlet_in) ? " AND (outlet_id IN ($_outlet_in)$_alpro_baby_ids)" : '';
$_s_baby = (!$_is_admin && $_outlet_in) ? " AND mama_id IN (SELECT id FROM mamabe_mama WHERE recycle=0 AND (outlet_id IN ($_outlet_in)$_alpro_baby_ids))" : '';
$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_mama WHERE recycle=0$_s_mama");
$stat_total = $res ? $res->fetch_assoc()['cnt'] : 0;
$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_baby WHERE recycle=0 AND is_delivered=0$_s_baby");
$stat_expecting = $res ? $res->fetch_assoc()['cnt'] : 0;
$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_baby WHERE recycle=0 AND is_delivered=1$_s_baby");
$stat_delivered = $res ? $res->fetch_assoc()['cnt'] : 0;
$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_baby WHERE recycle=0 AND is_delivered=2$_s_baby");
$stat_miscarriage = $res ? $res->fetch_assoc()['cnt'] : 0;

function formatWA($phone) {
    $hp = preg_replace('/\D/', '', $phone);
    if (substr($hp, 0, 3) === '601') { return $hp; }
    if (substr($hp, 0, 2) === '01')  { return '6' . $hp; }
    if (substr($hp, 0, 1) === '1')   { return '6' . $hp; }
    return $hp;
}

// ── Search / filter / pagination params ──
$search        = isset($_GET['search'])     ? trim($_GET['search'])       : '';
$filter_outlet = isset($_GET['outlet'])     ? (int)$_GET['outlet']        : 0;
$filter_baby   = isset($_GET['baby_status'])? trim($_GET['baby_status'])  : '';
$filter_month  = isset($_GET['month'])      ? (int)$_GET['month']         : 0;
$filter_year   = isset($_GET['year'])       ? (int)$_GET['year']          : 0;
$filter_status = isset($_GET['status']) && in_array($_GET['status'], array('active','inactive','all','website')) ? $_GET['status'] : 'active';
$per_page      = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], array(10, 25, 50, 100, 200), true) ? (int)$_GET['per_page'] : 25;
$page          = isset($_GET['page'])       ? max(1, (int)$_GET['page'])  : 1;
$offset        = ($page - 1) * $per_page;

// ── Build WHERE ──
// A mama a website registration just created (mama_is_new=1 on that submission) stays off this list until the AI
// check on it has actually finished (status 0 Queued / 1 Checking = not yet) - she shouldn't show up as a real
// customer before anyone's verified anything about her. An EXISTING mama is never hidden by this, even if she has
// an unrelated new submission still checking - only a brand-new, not-yet-checked mama is affected.
$where  = "m.recycle=0 AND NOT EXISTS (
    SELECT 1 FROM mamabe_submission s WHERE s.mama_id = m.id AND s.mama_is_new = 1 AND s.status IN (0, 1)
)";
$params = array();
$types  = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (m.mama_name LIKE ? OR m.ic LIKE ? OR m.phone LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}
if (!$_is_admin && $_outlet_in) {
    if ($filter_outlet > 0 && in_array($filter_outlet, $_staff_outlets)) {
        $where .= " AND m.outlet_id=?";
        $params[] = $filter_outlet;
        $types   .= 'i';
    } else {
        $where .= " AND (m.outlet_id IN ($_outlet_in) OR m.outlet_id IN (SELECT id FROM outlet WHERE recycle=0 AND comp_name LIKE '%ALPRO BABY%'))";
    }
} elseif ($_is_admin && $filter_outlet > 0) {
    $where .= " AND m.outlet_id=?";
    $params[] = $filter_outlet;
    $types   .= 'i';
}
if ($_is_admin && $filter_month > 0) {
    $where .= " AND MONTH(m.timestamp)=?";
    $params[] = $filter_month;
    $types   .= 'i';
}
if ($_is_admin && $filter_year > 0) {
    $where .= " AND YEAR(m.timestamp)=?";
    $params[] = $filter_year;
    $types   .= 'i';
}
if ($filter_status === 'active') {
    $where .= " AND m.timestamp > DATE_SUB(NOW(), INTERVAL 2 YEAR)";
} elseif ($filter_status === 'inactive') {
    $where .= " AND m.timestamp <= DATE_SUB(NOW(), INTERVAL 2 YEAR)";
} elseif ($filter_status === 'website') {
    // Mamas created by the public MamaBe website form (registration/ - MAMABE_REGISTERED_BY in registration/common/config.php)
    $where .= " AND m.registered_by = 'MamaBe website form'";
}
if ($filter_baby === 'expecting') {
    $where .= " AND EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_id=m.id AND b.recycle=0 AND b.is_delivered=0)";
} elseif ($filter_baby === 'delivered') {
    $where .= " AND EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_id=m.id AND b.recycle=0 AND b.is_delivered=1)";
} elseif ($filter_baby === 'miscarriage') {
    $where .= " AND EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_id=m.id AND b.recycle=0 AND b.is_delivered=2)";
}

// ── Total count ──
$count_sql  = "SELECT COUNT(*) AS cnt FROM mamabe_mama m WHERE $where";
$count_stmt = $conn->prepare($count_sql);
if ($types) { $count_stmt->bind_param($types, ...$params); }
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_assoc()['cnt'];
$count_stmt->close();
$total_pages = max(1, ceil($total_rows / $per_page));

// ── Fetch page ──
$sql  = "SELECT m.id, m.mama_name, m.ic, m.phone, m.language, m.timestamp,
                o.comp_name AS outlet_name
         FROM mamabe_mama m
         LEFT JOIN outlet o ON m.outlet_id = o.id
         WHERE $where
         ORDER BY m.timestamp DESC
         LIMIT ? OFFSET ?";
$p_types  = $types . 'ii';
$p_params = array_merge($params, array($per_page, $offset));
$stmt = $conn->prepare($sql);
$stmt->bind_param($p_types, ...$p_params);
$stmt->execute();
$mamas = $stmt->get_result();
$stmt->close();

// ── Load babies for this page only ──
$mama_ids   = array();
$mamas_rows = array();
while ($row = $mamas->fetch_assoc()) {
    $mamas_rows[]              = $row;
    $mama_ids[]                = (int)$row['id'];
}

$babies_map = array();
if (!empty($mama_ids)) {
    $in = implode(',', $mama_ids);
    $br = $conn->query("
        SELECT id, mama_id, baby_name, mykid, lmp, edd, is_delivered, gender,
               miscarriage_cause, miscarriage_date, miscarriage_time
        FROM mamabe_baby
        WHERE recycle=0 AND mama_id IN ($in)
        ORDER BY timestamp ASC
    ");
    if ($br) {
        while ($b = $br->fetch_assoc()) {
            $babies_map[$b['mama_id']][] = $b;
        }
    }
}

// ── Outlets for filter dropdown — only outlets with at least 1 mama ──
$outlets_list = $conn->query("SELECT id, code, comp_name FROM outlet WHERE recycle=0 AND id IN (SELECT DISTINCT outlet_id FROM mamabe_mama WHERE recycle=0) ORDER BY comp_name");
$staff_outlets_list = array();
if (!$_is_admin && !empty($_staff_outlets)) {
    $in = implode(',', $_staff_outlets);
    $sr = $conn->query("SELECT id, code, comp_name FROM outlet WHERE recycle=0 AND id IN ($in) ORDER BY comp_name");
    if ($sr) { while ($o = $sr->fetch_assoc()) { $staff_outlets_list[] = $o; } }
}

// ── Years for filter dropdown ──
$years_list = array();
$yr = $conn->query("SELECT DISTINCT YEAR(timestamp) AS yr FROM mamabe_mama WHERE recycle=0 ORDER BY yr DESC");
if ($yr) { while ($y = $yr->fetch_assoc()) { $years_list[] = (int)$y['yr']; } }

// ── Build pagination URL helpers ──
function page_url($p) {
    $args = $_GET;
    $args['page'] = $p;
    return '?' . http_build_query($args);
}
function per_page_url($n) {
    $args = $_GET;
    $args['per_page'] = $n;
    $args['page'] = 1;
    return '?' . http_build_query($args);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Mama List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<style>
.mb-stats-main { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.mb-card-main {
    flex:1 1 160px; background:#fff; border-radius:10px;
    border-left:6px solid #ccc; padding:16px 20px;
    box-shadow:0 2px 6px rgba(0,0,0,0.08); display:flex; align-items:center; gap:14px;
}
.mb-card-main .card-icon img { width:44px; height:44px; object-fit:contain; }
.mb-card-main .card-body .num { font-size:2.2em; font-weight:700; line-height:1.1; }
.mb-card-main .card-body .lbl { font-size:0.83em; color:#888; margin-top:4px; }
.mb-card-main.c-blue   { border-color:#2a6496; } .mb-card-main.c-blue   .num { color:#2a6496; }
.mb-card-main.c-pink   { border-color:#e91e8c; } .mb-card-main.c-pink   .num { color:#e91e8c; }
.mb-card-main.c-orange { border-color:#e67e22; } .mb-card-main.c-orange .num { color:#e67e22; }
.mb-card-main.c-girly  { border-color:#f06292; } .mb-card-main.c-girly  .num { color:#f06292; }
.mb-card-main.c-green  { border-color:#128C7E; } .mb-card-main.c-green  .num { color:#128C7E; }
.mb-card-main.c-purple { border-color:#8e44ad; } .mb-card-main.c-purple .num { color:#8e44ad; }
.mb-card-main.c-red    { border-color:#c82333; } .mb-card-main.c-red    .num { color:#c82333; }
.alpro-table td { vertical-align:top; padding:6px 8px; }
.cell-line { display:block; line-height:1.7; }
.cell-muted { color:#999; font-size:0.85em; }
.cell-name  { font-weight:bold; }
.cell-ic    { font-family:monospace; font-size:0.9em; }
.cell-phone { color:#128C7E; font-weight:bold; }
.search-bar {
  display: flex;
  gap: 6px;
  flex-wrap: nowrap;
  align-items: center;
  margin-bottom: 14px;
}
.search-bar input[type=text],
.search-bar select,
.search-bar .alpro-btn {
  height: 34px;
  padding: 0 8px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 0.85em;
  box-sizing: border-box;
  white-space: nowrap;
}
.search-bar input[type=text] {
  flex: 1 1 150px;
}
.search-bar select { line-height: normal; }
.search-bar select[name=outlet] { flex: 3 1 200px; }
.search-bar select[name=month]  { width: 120px; }
.search-bar select[name=year]   { width: 90px; }
.search-bar select[name=baby_status] { width: 130px; }
.search-bar .alpro-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
/* TomSelect outlet override */
.search-bar .ts-wrapper { flex: 3 1 200px !important; }
.search-bar .ts-wrapper .ts-control { flex-wrap: nowrap; overflow: hidden; height: 34px; align-items: center; }
.search-bar .ts-wrapper .ts-control .item { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* "Show N entries" / "Showing X to Y of Z entries" + pager row, DataTables-style (matches mamabe/voucher/index.php) */
.mamabe-list-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.mamabe-show-entries { display: flex; align-items: center; gap: 6px; font-size: 0.9em; color: #333; }
.mamabe-show-entries select { padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em; }
.mamabe-list-toolbar-right { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.mamabe-muted { color: #888; font-size: 0.9em; }

.mamabe-dt-pagination { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.mamabe-dt-page {
    display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 10px;
    border: 1px solid #dee2e6; border-radius: 6px; background: #fff; color: #333; font-size: 0.88em;
    text-decoration: none; box-sizing: border-box;
}
.mamabe-dt-page:hover:not(.disabled):not(.current) { background: #f1f3f5; }
.mamabe-dt-page.current { background: #1a3a6b; border-color: #1a3a6b; color: #fff; font-weight: bold; }
.mamabe-dt-page.disabled { color: #bbb; background: #f8f9fa; cursor: default; pointer-events: none; }
.mamabe-dt-ellipsis { padding: 0 4px; color: #888; }

</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mamabee.png"> MamaBe – Mama List</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<div class="mb-stats-main">
  <div class="mb-card-main c-pink">
    <div class="card-icon"><img src="images/mamabe.jpeg" alt=""></div>
    <div class="card-body">
      <div class="num"><?php echo $stat_total; ?></div>
      <div class="lbl">Total Mama</div>
    </div>
  </div>
  <div class="mb-card-main c-orange">
    <div class="card-icon"><img src="images/milk.gif" alt=""></div>
    <div class="card-body">
      <div class="num"><?php echo $stat_expecting; ?></div>
      <div class="lbl">Expecting</div>
    </div>
  </div>
  <div class="mb-card-main c-purple">
    <div class="card-icon"><img src="images/footprint.gif" alt=""></div>
    <div class="card-body">
      <div class="num"><?php echo $stat_delivered; ?></div>
      <div class="lbl">Safely Delivered</div>
    </div>
  </div>
  <div class="mb-card-main c-red">
    <div class="card-icon"><img src="images/love.gif" alt=""></div>
    <div class="card-body">
      <div class="num"><?php echo $stat_miscarriage; ?></div>
      <div class="lbl">Miscarriage</div>
    </div>
  </div>
</div>

<fieldset>
  <legend><b>Mama List</b> <span style="font-weight:normal;color:#888;font-size:0.9em">(<?php echo $total_rows; ?> record<?php echo $total_rows != 1 ? 's' : ''; ?>)</span></legend>

  <!-- Search & Filter -->
  <form method="get" style="margin-bottom:20px;">
    <div class="search-bar">
      <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
             placeholder="Search name, IC, phone...">
      <?php if (!$_is_admin && count($staff_outlets_list) > 1): ?>
      <select name="outlet" style="width:120px;">
        <option value="0">– All –</option>
        <?php foreach ($staff_outlets_list as $o): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo ($filter_outlet == $o['id']) ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($o['code'] . ' - ' . $o['comp_name']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php if ($_is_admin): ?>
      <select name="outlet" id="outlet_select">
        <option value="0">– All Outlets –</option>
        <?php if ($outlets_list): while ($o = $outlets_list->fetch_assoc()): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo ($filter_outlet == $o['id']) ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($o['code'] . ' - ' . $o['comp_name']); ?>
        </option>
        <?php endwhile; endif; ?>
      </select>
      <select name="month">
        <option value="0">– All Months –</option>
        <?php
        $months = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                        7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');
        foreach ($months as $mn => $ml):
        ?>
        <option value="<?php echo $mn; ?>" <?php echo ($filter_month === $mn) ? 'selected' : ''; ?>><?php echo $ml; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year">
        <option value="0">– All Years –</option>
        <?php foreach ($years_list as $yr): ?>
        <option value="<?php echo $yr; ?>" <?php echo ($filter_year === $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <select name="baby_status">
        <option value="">– All Status –</option>
        <option value="expecting"   <?php echo ($filter_baby === 'expecting')   ? 'selected' : ''; ?>>Expecting</option>
        <option value="delivered"   <?php echo ($filter_baby === 'delivered')   ? 'selected' : ''; ?>>Safely Delivered</option>
        <option value="miscarriage" <?php echo ($filter_baby === 'miscarriage') ? 'selected' : ''; ?>>Miscarriage</option>
      </select>
      <select name="status">
        <option value="active"   <?php echo ($filter_status === 'active')   ? 'selected' : ''; ?>>Active mamas only</option>
        <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive only (2+ yrs)</option>
        <option value="all"      <?php echo ($filter_status === 'all')      ? 'selected' : ''; ?>>All (Active + Inactive)</option>
        <option value="website"  <?php echo ($filter_status === 'website')  ? 'selected' : ''; ?>>Registered via website</option>
      </select>
      <input class="alpro-btn alpro-btn-blue" type="submit" value="Search">
      <input class="alpro-btn alpro-btn-grey" type="submit" formaction="mamabe_mama_export.php" value="&#128190; Export to Excel">
      <a class="alpro-btn alpro-btn-grey" href="mamabe_mama_list.php">Reset</a>
    </div>
  </form>

  <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
      <th width="30">#</th>
      <th>Mama Details</th>
      <th>Language / Outlet / Phone</th>
      <th>Baby Information</th>
      <th width="60">Action</th>
    </tr>

    <?php if (!empty($mamas_rows)):
      $i = $offset + 1;
      foreach ($mamas_rows as $row):
        $lang   = isset($lang_map[$row['language']]) ? $lang_map[$row['language']] : '-';
        $outlet = isset($row['outlet_name']) ? $row['outlet_name'] : '-';
        $phone  = ($row['phone'] != '') ? $row['phone'] : '-';
        $babies = isset($babies_map[$row['id']]) ? $babies_map[$row['id']] : array();
    ?>
    <tr>
      <td class="alpro-center"><?php echo $i++; ?></td>

      <td>
        <span class="cell-line cell-muted"><?php echo htmlspecialchars($row['timestamp']); ?></span>
        <span class="cell-line cell-name"><a href="mamabe_mama_detail.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars($row['mama_name']); ?></a>
          <?php if (strtotime($row['timestamp']) <= strtotime('-2 years')): ?>
          <span style="display:inline-block; margin-left:5px; padding:1px 6px; font-size:0.7em; font-weight:bold; color:#996633; background:#fff3e0; border:1px solid #e0c090; border-radius:3px; vertical-align:middle;" title="No activity/reactivation in 2+ years since registration">Inactive</span>
          <?php endif; ?>
        </span>
        <span class="cell-line cell-ic"><?php echo htmlspecialchars($row['ic']); ?></span>
      </td>

      <td>
        <span class="cell-line">
          <?php if ($phone != '-'):
            $contact = formatWA($phone); ?>
          <a href="javascript:void(0);"
             onclick="window.open('https://api.whatsapp.com/send?phone=<?php echo $contact; ?>&language=en','wa','width=1,height=1,left=1,top=1,toolbar=no,scrollbars=no,menubar=no,resizable=no');"
             title="Send WhatsApp" class="cell-phone">
            <img src="images/chat.png" width="14px"> <?php echo htmlspecialchars($phone); ?>
          </a>
          <?php else: ?>
          <span class="cell-muted">-</span>
          <?php endif; ?>
        </span>
        <span class="cell-line"><?php echo htmlspecialchars($lang); ?></span>
        <span class="cell-line"><?php echo htmlspecialchars($outlet); ?></span>
      </td>

      <td>
        <?php if (count($babies) === 0): ?>
          <span class="cell-muted">No baby record yet.</span>
        <?php else: foreach ($babies as $b):
          $is_misc = ($b['is_delivered'] == 2);
          $row_style = $is_misc ? 'color:#c82333; background-color:#ffebeb; display:block; padding:4px; border-radius:3px;' : '';
        ?>
          <div style="margin-bottom:4px; border-bottom:1px dashed <?php echo $is_misc ? 'transparent' : '#ddd'; ?>; <?php echo $row_style; ?>">
          <?php if ($is_misc): ?>
            <span class="cell-line" style="color:#c82333;">Date: <?php echo (!empty($b['miscarriage_date']) && $b['miscarriage_date'] != '0000-00-00') ? htmlspecialchars($b['miscarriage_date']) : '–'; ?></span>
            <span class="cell-line" style="color:#c82333;">Time: <?php echo (!empty($b['miscarriage_time']) && $b['miscarriage_time'] != '00:00:00') ? htmlspecialchars($b['miscarriage_time']) : '–'; ?>
              <strong style="float:right;">[Miscarriage]</strong>
            </span>
            <?php if (!empty($b['miscarriage_cause'])): ?>
            <span class="cell-line" style="color:#c82333;font-size:0.9em;">Cause: <?php echo htmlspecialchars($b['miscarriage_cause']); ?></span>
            <?php endif; ?>
          <?php elseif ($b['baby_name'] != ''): ?>
            <span class="cell-line cell-name">
              <?php if ($b['is_delivered'] == 1): ?>
                <?php if ($b['gender'] === 'Female'): ?>
                <img src="images/girl.gif" alt="Girl" style="width:25px;height:25px;margin-right:4px;vertical-align:text-bottom;">
                <?php elseif ($b['gender'] === 'Male'): ?>
                <img src="images/boy.gif" alt="Boy" style="width:25px;height:25px;margin-right:4px;vertical-align:text-bottom;">
                <?php else: ?>
                <img src="images/milk.gif" alt="Baby" style="width:24px;height:24px;margin-right:4px;vertical-align:text-bottom;">
                <?php endif; ?>
              <?php endif; ?>
              <a href="mamabe_mama_detail.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars($b['baby_name']); ?></a>
            </span>
            <span class="cell-line cell-ic"><?php echo ($b['mykid'] != '') ? htmlspecialchars($b['mykid']) : '<span class="cell-muted">MyKid: –</span>'; ?></span>
          <?php else: ?>
            <span class="cell-line cell-muted">LMP: <?php echo ($b['lmp'] && $b['lmp'] != '0000-00-00') ? htmlspecialchars($b['lmp']) : '–'; ?></span>
            <span class="cell-line cell-muted">EDD: <?php echo ($b['edd'] && $b['edd'] != '0000-00-00') ? htmlspecialchars($b['edd']) : '–'; ?></span>
          <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </td>

      <td class="alpro-center">
        <a href="mamabe_mama_detail.php?id=<?php echo (int)$row['id']; ?>" title="View">
          <img src="images/view.png" style="width:22px;vertical-align:middle;">
        </a>
      </td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="5" class="alpro-center alpro-muted">No mama found.</td></tr>
    <?php endif; ?>
  </table>

  <!-- Show entries + pagination -->
  <?php $from = $total_rows > 0 ? $offset + 1 : 0; $to = min($offset + $per_page, $total_rows); ?>
  <div class="mamabe-list-toolbar">
    <label class="mamabe-show-entries">
        Show
        <select onchange="location.href = this.value;">
            <?php foreach (array(10, 25, 50, 100, 200) as $n): ?>
            <option value="<?php echo htmlspecialchars(per_page_url($n)); ?>" <?php echo $per_page === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
        </select>
        entries
    </label>

    <div class="mamabe-list-toolbar-right">
        <span class="mamabe-muted">
            <?php if ($total_rows > 0): ?>
            Showing <?php echo $from; ?> to <?php echo $to; ?> of <?php echo $total_rows; ?> entries
            <?php else: ?>
            No entries
            <?php endif; ?>
        </span>
        <?php if ($total_pages > 1): ?>
        <div class="mamabe-dt-pagination">
            <?php $prevDisabled = $page <= 1; ?>
            <a class="mamabe-dt-page <?php echo $prevDisabled ? 'disabled' : ''; ?>"
               href="<?php echo $prevDisabled ? '#' : htmlspecialchars(page_url($page - 1)); ?>">Previous</a>

            <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if ($start > 1) { echo '<a class="mamabe-dt-page" href="' . htmlspecialchars(page_url(1)) . '">1</a>'; if ($start > 2) echo '<span class="mamabe-dt-ellipsis">&hellip;</span>'; }
            for ($p = $start; $p <= $end; $p++) {
                echo $p === $page
                    ? '<span class="mamabe-dt-page current">' . $p . '</span>'
                    : '<a class="mamabe-dt-page" href="' . htmlspecialchars(page_url($p)) . '">' . $p . '</a>';
            }
            if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="mamabe-dt-ellipsis">&hellip;</span>'; echo '<a class="mamabe-dt-page" href="' . htmlspecialchars(page_url($total_pages)) . '">' . $total_pages . '</a>'; }
            ?>

            <?php $nextDisabled = $page >= $total_pages; ?>
            <a class="mamabe-dt-page <?php echo $nextDisabled ? 'disabled' : ''; ?>"
               href="<?php echo $nextDisabled ? '#' : htmlspecialchars(page_url($page + 1)); ?>">Next</a>
        </div>
        <?php endif; ?>
    </div>
  </div>

</fieldset>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var el = document.getElementById('outlet_select');
    if (el) {
        new TomSelect("#outlet_select", {
            create: false,
            sortField: { field: "text", direction: "asc" },
            maxOptions: null,
            plugins: ['dropdown_input']
        });
    }
});
</script>
</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
