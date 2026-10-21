<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

$_is_admin = (isset($mamab_autho) && ($mamab_autho == '1' || $mamab_autho == '3'));
if (!$_is_admin) {
    header('Location: mamabe_mama_list.php');
    exit;
}

// Auto-add remark columns if not yet present
$_c = $conn->query("SHOW COLUMNS FROM mamabe_baby LIKE 'mismatch_remark'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_baby
        ADD COLUMN mismatch_remark      TEXT NOT NULL,
        ADD COLUMN baby_mismatch_remark TEXT NOT NULL");
}

if (isset($_POST['save_remark'])) {
    $rm_baby_id = (int)($_POST['baby_id'] ?? 0);
    $rm_type    = trim($_POST['remark_type'] ?? '');
    $rm_text    = trim($_POST['remark_text'] ?? '');
    $rm_col     = ($rm_type === 'baby') ? 'baby_mismatch_remark' : 'mismatch_remark';
    $stmt = $conn->prepare("UPDATE mamabe_baby SET $rm_col=? WHERE id=?");
    $stmt->bind_param("si", $rm_text, $rm_baby_id);
    $stmt->execute();
    $stmt->close();
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
    exit;
}

$filter_outlet = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : 0;
$filter_type   = isset($_GET['type'])      ? trim($_GET['type'])      : '';

$outlets = $conn->query("SELECT id, code, comp_name FROM outlet WHERE recycle=0 ORDER BY comp_name");

$where_parts = array("mm.recycle=0", "mb.recycle=0",
    "(mb.receipt_price_mismatch IS NOT NULL AND mb.receipt_price_mismatch != '')
     OR (mb.baby_receipt_price_mismatch IS NOT NULL AND mb.baby_receipt_price_mismatch != '')"
);
if ($filter_outlet > 0) { $where_parts[] = "mm.outlet_id=" . $filter_outlet; }
if ($filter_type === 'mama') {
    $where_parts[] = "(mb.receipt_price_mismatch IS NOT NULL AND mb.receipt_price_mismatch != '')";
} elseif ($filter_type === 'baby') {
    $where_parts[] = "(mb.baby_receipt_price_mismatch IS NOT NULL AND mb.baby_receipt_price_mismatch != '')";
}

$where_sql = implode(' AND ', $where_parts);
$sql = "
    SELECT mb.id AS baby_id, mm.id AS mama_id,
           mm.mama_name, mm.ic, mm.phone,
           o.comp_name AS outlet_name,
           mb.receipt_no, mb.receipt_price_mismatch, mb.benefit_50_off_at, mb.mismatch_remark,
           mb.baby_receipt_no, mb.baby_receipt_price_mismatch, mb.benefit_50_off_baby_at, mb.baby_mismatch_remark
    FROM mamabe_baby mb
    JOIN mamabe_mama mm ON mb.mama_id = mm.id
    LEFT JOIN outlet o ON mm.outlet_id = o.id
    WHERE $where_sql
";
$result = $conn->query($sql);
$rows = array();
if ($result) { while ($r = $result->fetch_assoc()) { $rows[] = $r; } }

// ── Flatten: one entry per mismatch type, then sort by verified date, newest first ──
$entries = array();
foreach ($rows as $r) {
    if (!empty($r['receipt_price_mismatch'])) {
        $entries[] = array(
            'baby_id' => $r['baby_id'], 'mama_id' => $r['mama_id'],
            'mama_name' => $r['mama_name'], 'ic' => $r['ic'], 'phone' => $r['phone'],
            'outlet_name' => $r['outlet_name'],
            'type'    => 'Mama 50% Off',
            'class'   => 'type-mama',
            'receipt' => $r['receipt_no'],
            'date'    => $r['benefit_50_off_at'],
            'detail'  => $r['receipt_price_mismatch'],
            'remark_type' => 'mama',
            'remark'  => $r['mismatch_remark'],
        );
    }
    if (!empty($r['baby_receipt_price_mismatch'])) {
        $entries[] = array(
            'baby_id' => $r['baby_id'], 'mama_id' => $r['mama_id'],
            'mama_name' => $r['mama_name'], 'ic' => $r['ic'], 'phone' => $r['phone'],
            'outlet_name' => $r['outlet_name'],
            'type'    => 'Baby 50% Off',
            'class'   => 'type-baby',
            'receipt' => $r['baby_receipt_no'],
            'date'    => $r['benefit_50_off_baby_at'],
            'detail'  => $r['baby_receipt_price_mismatch'],
            'remark_type' => 'baby',
            'remark'  => $r['baby_mismatch_remark'],
        );
    }
}
usort($entries, function($a, $b) {
    $ad = (!empty($a['date']) && $a['date'] !== '0000-00-00 00:00:00') ? strtotime($a['date']) : 0;
    $bd = (!empty($b['date']) && $b['date'] !== '0000-00-00 00:00:00') ? strtotime($b['date']) : 0;
    return $bd - $ad;
});
$total = count($entries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Mismatch Report</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; background:#f8f8f8; border:1px solid #e0e0e0; border-radius:6px; padding:10px 14px; }
.filter-bar label { font-size:0.82em; font-weight:bold; color:#555; display:block; margin-bottom:3px; }
.filter-bar select { padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.88em; }
.mm-table { width:100%; border-collapse:collapse; font-size:0.88em; }
.mm-table th { background:#2a6496; color:#fff; padding:8px 10px; text-align:left; white-space:nowrap; border:1px solid #1a5070; }
.mm-table td { padding:7px 10px; border:1px solid #e0e0e0; vertical-align:top; }
.mm-table tr:nth-child(even) td { background:#fafafa; }
.mm-table tr:hover td { background:#f0f6ff; }
.outlet-row td { background:#e8f0fb !important; font-weight:bold; color:#2a6496; font-size:0.88em; padding:5px 10px; }
.type-badge { display:inline-block; border-radius:10px; padding:2px 8px; font-size:0.78em; font-weight:bold; white-space:nowrap; }
.type-mama { background:#fff3cd; color:#856404; border:1px solid #f0d080; }
.type-baby { background:#e8f8e8; color:#1a7a1a; border:1px solid #a0d8a0; }
.mismatch-detail { background:#fff3f3; border-left:3px solid #e53935; padding:4px 8px; border-radius:0 3px 3px 0; font-size:0.85em; color:#444; }
.receipt-tag { font-family:monospace; background:#f5f5f5; border:1px solid #ddd; border-radius:3px; padding:1px 6px; font-size:0.88em; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mom.png"> MamaBe – Mismatch Report</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<form method="get" class="filter-bar">
  <div>
    <label>Outlet</label>
    <select name="outlet_id" onchange="this.form.submit()">
      <option value="0">-- All Outlets --</option>
      <?php if ($outlets): while ($o = $outlets->fetch_assoc()): ?>
      <option value="<?php echo (int)$o['id']; ?>" <?php echo ($filter_outlet == $o['id']) ? 'selected' : ''; ?>>
        <?php echo htmlspecialchars($o['code'] . ' - ' . $o['comp_name']); ?>
      </option>
      <?php endwhile; endif; ?>
    </select>
  </div>
  <div>
    <label>Type</label>
    <select name="type" onchange="this.form.submit()">
      <option value="">All Types</option>
      <option value="mama" <?php echo ($filter_type === 'mama') ? 'selected' : ''; ?>>Mama 50% Off</option>
      <option value="baby" <?php echo ($filter_type === 'baby') ? 'selected' : ''; ?>>Baby 50% Off</option>
    </select>
  </div>
  <div style="align-self:flex-end;">
    <a class="alpro-btn alpro-btn-grey" href="mamabe_price_mismatch.php" style="font-size:0.85em; padding:5px 12px;">Reset</a>
  </div>
  <div style="margin-left:auto; align-self:center; font-size:0.85em; color:#c62828; font-weight:bold;">
    &#9888; <?php echo $total; ?> issue record(s) found
  </div>
</form>

<?php if ($total === 0): ?>
<div class="alpro-alert alpro-success">&#10003; No mismatches found. All receipts are valid.</div>
<?php else: ?>

<fieldset>
<table class="mm-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Outlet</th>
      <th>Mama Name</th>
      <th>IC</th>
      <th>Phone</th>
      <th>Type</th>
      <th>Receipt No.</th>
      <th>Verified Date</th>
      <th>Mismatch / Issue Detail</th>
      <th>Remark</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
  <?php $i = 1; foreach ($entries as $m): ?>
    <tr>
      <td style="text-align:center; color:#888;"><?php echo $i++; ?></td>
      <td style="font-size:0.82em; color:#2a6496; font-weight:bold;"><?php echo htmlspecialchars($m['outlet_name'] ?? ''); ?></td>
      <td><a href="mamabe_mama_detail.php?id=<?php echo (int)$m['mama_id']; ?>" style="color:#1a56b0; font-weight:bold;"><?php echo htmlspecialchars($m['mama_name'] ?? ''); ?></a></td>
      <td style="font-family:monospace; font-size:0.85em;"><?php echo htmlspecialchars($m['ic'] ?? ''); ?></td>
      <td style="font-size:0.85em;"><?php echo htmlspecialchars($m['phone'] ?? ''); ?></td>
      <td><span class="type-badge <?php echo $m['class']; ?>"><?php echo htmlspecialchars($m['type']); ?></span></td>
      <td><span class="receipt-tag"><?php echo htmlspecialchars($m['receipt']); ?></span></td>
      <td style="font-size:0.8em; color:#666; white-space:nowrap;"><?php echo ($m['date'] && $m['date'] !== '0000-00-00 00:00:00') ? htmlspecialchars($m['date']) : '–'; ?></td>
      <td>
        <div class="mismatch-detail">
        <?php
        $lines = array_filter(array_map('trim', explode("\n", $m['detail'] ?? '')));
        foreach ($lines as $line):
        ?>
          <div style="padding:2px 0; border-bottom:1px dashed #f5b8b8; line-height:1.4;">&#9888; <?php echo htmlspecialchars($line); ?></div>
        <?php endforeach; ?>
        </div>
      </td>
      <td style="min-width:180px;">
        <form method="post" style="display:flex;gap:4px;align-items:flex-start;">
          <input type="hidden" name="baby_id" value="<?php echo (int)$m['baby_id']; ?>">
          <input type="hidden" name="remark_type" value="<?php echo htmlspecialchars($m['remark_type'] ?? ''); ?>">
          <textarea name="remark_text" rows="2" placeholder="Add remark..." style="flex:1;font-size:0.82em;padding:4px 6px;border:1px solid #ccc;border-radius:4px;resize:vertical;"><?php echo htmlspecialchars($m['remark'] ?? ''); ?></textarea>
          <button type="submit" name="save_remark" value="1" class="alpro-btn alpro-btn-grey" style="font-size:0.75em;padding:3px 8px;white-space:nowrap;">Save</button>
        </form>
      </td>
      <td style="text-align:center; white-space:nowrap;">
        <a href="mamabe_mama_detail.php?id=<?php echo (int)$m['mama_id']; ?>" class="alpro-btn alpro-btn-grey" style="font-size:0.78em; padding:3px 8px;">&#9998; View</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</fieldset>

<?php endif; ?>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
