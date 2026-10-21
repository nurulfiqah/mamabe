<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

$conn->query("CREATE TABLE IF NOT EXISTS mamabe_sku_master (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(50) NOT NULL, item_name VARCHAR(255) NOT NULL DEFAULT '', price DECIMAL(10,2) NOT NULL DEFAULT 0.00, type TINYINT(1) NOT NULL DEFAULT 1, recycle TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$_chk = $conn->query("SHOW COLUMNS FROM mamabe_sku_master LIKE 'price'");
if ($_chk && $_chk->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_sku_master ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER item_name");
}

$conn->query("CREATE TABLE IF NOT EXISTS mamabe_free_item_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(10) NOT NULL DEFAULT 'baby',
    item_code VARCHAR(50) NOT NULL DEFAULT '',
    item_name VARCHAR(255) NOT NULL DEFAULT '',
    recycle TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed once, from the old fixed-slot config if it exists (preserves any
// edits already made there), else from the original defaults.
$_seed_diaper1 = array('code' => '100648468', 'name' => 'Diapers');
$_seed_diaper2 = array('code' => '100657196', 'name' => '(S) BZU BZU Velvet Breeze Baby Diapers Tape S 3pcs');
$_seed_vitd    = array('code' => '100624444', 'name' => 'Free Vitamin D Test');
$_old_cfg_res = $conn->query("SHOW TABLES LIKE 'mamabe_receipt_benefit_config'");
if ($_old_cfg_res && $_old_cfg_res->num_rows > 0) {
    $_old_cfg = $conn->query("SELECT slot, item_code, item_name FROM mamabe_receipt_benefit_config");
    if ($_old_cfg) {
        while ($_ocr = $_old_cfg->fetch_assoc()) {
            $_pair = array('code' => $_ocr['item_code'], 'name' => $_ocr['item_name']);
            if ((int)$_ocr['slot'] === 1) { $_seed_diaper1 = $_pair; }
            if ((int)$_ocr['slot'] === 2) { $_seed_diaper2 = $_pair; }
            if ((int)$_ocr['slot'] === 3) { $_seed_vitd = $_pair; }
        }
    }
}
$_cat_chk = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_free_item_catalog");
if ($_cat_chk && (int)$_cat_chk->fetch_assoc()['cnt'] === 0) {
    $stmt = $conn->prepare("INSERT INTO mamabe_free_item_catalog (scope, item_code, item_name) VALUES (?, ?, ?)");
    foreach (array(
        array('baby', $_seed_diaper1['code'], $_seed_diaper1['name']),
        array('baby', $_seed_diaper2['code'], $_seed_diaper2['name']),
        array('mama', $_seed_vitd['code'], $_seed_vitd['name']),
    ) as $_seed_row) {
        $stmt->bind_param("sss", $_seed_row[0], $_seed_row[1], $_seed_row[2]);
        $stmt->execute();
    }
    $stmt->close();
}

$msg = '';
$msg_type = '';

$_is_super_admin = (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true));

if ($_is_super_admin && isset($_POST['add_free_item'])) {
    $fi_scope = ($_POST['scope'] ?? '') === 'mama' ? 'mama' : 'baby';
    $fi_code  = trim($_POST['item_code'] ?? '');
    $fi_name  = trim($_POST['item_name'] ?? '');
    if ($fi_code && $fi_name) {
        $stmt = $conn->prepare("INSERT INTO mamabe_free_item_catalog (scope, item_code, item_name) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $fi_scope, $fi_code, $fi_name);
        if ($stmt->execute()) {
            $msg      = "Free item added.";
            $msg_type = "success";
        } else {
            $msg      = "Failed to add free item.";
            $msg_type = "danger";
        }
        $stmt->close();
    } else {
        $msg      = "Please fill in item code and name.";
        $msg_type = "danger";
    }
}

if ($_is_super_admin && isset($_POST['edit_free_item'])) {
    $fi_id    = (int)($_POST['edit_id'] ?? 0);
    $fi_scope = ($_POST['edit_scope'] ?? '') === 'mama' ? 'mama' : 'baby';
    $fi_code  = trim($_POST['edit_item_code'] ?? '');
    $fi_name  = trim($_POST['edit_item_name'] ?? '');
    if ($fi_id && $fi_code && $fi_name) {
        $stmt = $conn->prepare("UPDATE mamabe_free_item_catalog SET scope=?, item_code=?, item_name=? WHERE id=?");
        $stmt->bind_param("sssi", $fi_scope, $fi_code, $fi_name, $fi_id);
        $stmt->execute();
        $stmt->close();
        $msg      = "Free item updated.";
        $msg_type = "success";
    }
}

if ($_is_super_admin && isset($_POST['delete_free_item'])) {
    $fi_id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE mamabe_free_item_catalog SET recycle=1 WHERE id=?");
    $stmt->bind_param("i", $fi_id);
    $stmt->execute();
    $stmt->close();
    $msg      = "Free item removed.";
    $msg_type = "success";
}

if ($_is_super_admin && isset($_POST['add_sku'])) {
    $sku       = trim($_POST['sku'] ?? '');
    $item_name = trim($_POST['item_name'] ?? '');
    $price     = isset($_POST['price']) ? (float)$_POST['price'] : 0;
    $type      = isset($_POST['type']) ? (int)$_POST['type'] : 1;

    if ($sku && $item_name && in_array($type, array(1, 2))) {
        $stmt = $conn->prepare("INSERT INTO mamabe_sku_master (sku, item_name, price, type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssdi", $sku, $item_name, $price, $type);
        if ($stmt->execute()) {
            $msg      = "SKU added successfully!";
            $msg_type = "success";
        } else {
            $msg      = "Failed to add SKU.";
            $msg_type = "danger";
        }
        $stmt->close();
    } else {
        $msg      = "Please fill in all fields.";
        $msg_type = "danger";
    }
}

if ($_is_super_admin && isset($_POST['delete_sku'])) {
    $del_id = (int)($_POST['id'] ?? 0);
    $conn->query("UPDATE mamabe_sku_master SET recycle=1 WHERE id=$del_id");
    $msg      = "SKU removed.";
    $msg_type = "success";
}

if ($_is_super_admin && isset($_POST['edit_sku'])) {
    $edit_id   = (int)($_POST['edit_id'] ?? 0);
    $edit_sku_code = trim($_POST['edit_sku_code'] ?? '');
    $edit_name = trim($_POST['edit_item_name'] ?? '');
    $edit_price= (float)($_POST['edit_price'] ?? 0);
    $edit_type = (int)($_POST['edit_type'] ?? 0);
    if ($edit_id && $edit_sku_code && $edit_name && in_array($edit_type, array(1, 2))) {
        $stmt = $conn->prepare("UPDATE mamabe_sku_master SET sku=?, item_name=?, price=?, type=? WHERE id=?");
        $stmt->bind_param("ssdii", $edit_sku_code, $edit_name, $edit_price, $edit_type, $edit_id);
        $stmt->execute();
        $stmt->close();
        $msg      = "SKU updated.";
        $msg_type = "success";
    }
}

$skus_mama = $conn->query("SELECT * FROM mamabe_sku_master WHERE type=1 AND recycle=0 ORDER BY item_name");
$skus_baby = $conn->query("SELECT * FROM mamabe_sku_master WHERE type=2 AND recycle=0 ORDER BY item_name");

$free_items_mama_list = $conn->query("SELECT * FROM mamabe_free_item_catalog WHERE scope='mama' AND recycle=0 ORDER BY item_name");
$free_items_baby_list = $conn->query("SELECT * FROM mamabe_free_item_catalog WHERE scope='baby' AND recycle=0 ORDER BY item_name");

// ── Sales Dashboard ──
$sku_name_map = array();
$_sm = $conn->query("SELECT sku, item_name, price, type FROM mamabe_sku_master WHERE recycle=0");
if ($_sm) { while ($_r = $_sm->fetch_assoc()) { $sku_name_map[$_r['sku']] = array('name' => $_r['item_name'], 'price' => (float)$_r['price'], 'type' => (int)$_r['type']); } }

$dash_year  = isset($_GET['dash_year'])  ? (int)$_GET['dash_year']  : (int)date('Y');
$dash_month = isset($_GET['dash_month']) ? (int)$_GET['dash_month'] : (int)date('n');
$all_time   = ($dash_year == 0 && $dash_month == 0);

$_date_mama = '';
if ($dash_year  > 0) $_date_mama .= " AND YEAR(benefit_50_off_at)=$dash_year";
if ($dash_month > 0) $_date_mama .= " AND MONTH(benefit_50_off_at)=$dash_month";

$_date_baby = '';
if ($dash_year  > 0) $_date_baby .= " AND YEAR(benefit_50_off_baby_at)=$dash_year";
if ($dash_month > 0) $_date_baby .= " AND MONTH(benefit_50_off_baby_at)=$dash_month";

$sales_mama = array();
$_r1 = $conn->query("SELECT benefit_50_off_items FROM mamabe_baby WHERE recycle=0 AND benefit_50_off_items != ''$_date_mama");
if ($_r1) { while ($_row = $_r1->fetch_assoc()) { foreach (array_filter(array_map('trim', explode(',', $_row['benefit_50_off_items']))) as $_sku) { $sales_mama[$_sku] = isset($sales_mama[$_sku]) ? $sales_mama[$_sku] + 1 : 1; } } }
arsort($sales_mama);

$sales_baby = array();
$_r2 = $conn->query("SELECT benefit_50_off_baby_items FROM mamabe_baby WHERE recycle=0 AND benefit_50_off_baby_items != ''$_date_baby");
if ($_r2) { while ($_row = $_r2->fetch_assoc()) { foreach (array_filter(array_map('trim', explode(',', $_row['benefit_50_off_baby_items']))) as $_sku) { $sales_baby[$_sku] = isset($sales_baby[$_sku]) ? $sales_baby[$_sku] + 1 : 1; } } }
arsort($sales_baby);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – SKU Master</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mom.png"> MamaBe – SKU Master</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- ── Sales Dashboard ── -->
<?php
$_pie_colors = array(
    'rgba(233,30,140,0.8)','rgba(33,150,243,0.8)','rgba(255,152,0,0.8)',
    'rgba(76,175,80,0.8)','rgba(156,39,176,0.8)','rgba(0,188,212,0.8)',
    'rgba(255,87,34,0.8)','rgba(63,81,181,0.8)','rgba(0,150,136,0.8)',
    'rgba(244,67,54,0.8)'
);

$_mama_labels = array(); $_mama_data = array(); $_mama_colors = array(); $_mi = 0;
$_mama_total_sales = 0; $_mama_total_qty = 0;
foreach ($sales_mama as $_sku => $_cnt) {
    $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name' => $_sku, 'price' => 0, 'type' => 1);
    $_mama_labels[] = $info['name'];
    $_mama_data[]   = $_cnt;
    $_mama_colors[] = $_pie_colors[$_mi % count($_pie_colors)];
    $_mama_total_sales += (float)$info['price'] * $_cnt;
    $_mama_total_qty   += $_cnt;
    $_mi++;
}

$_baby_labels = array(); $_baby_data = array(); $_baby_colors = array(); $_bi = 0;
$_baby_total_sales = 0; $_baby_total_qty = 0;
foreach ($sales_baby as $_sku => $_cnt) {
    $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name' => $_sku, 'price' => 0, 'type' => 2);
    $_baby_labels[] = $info['name'];
    $_baby_data[]   = $_cnt;
    $_baby_colors[] = $_pie_colors[$_bi % count($_pie_colors)];
    $_baby_total_sales += (float)$info['price'] * $_cnt;
    $_baby_total_qty   += $_cnt;
    $_bi++;
}

$_cur_year  = (int)date('Y');
$_cur_month = (int)date('n');
$_months    = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                    7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');
?>

<?php include __DIR__ . '/mamabe_outlet_data.php'; ?>
<!-- Month filter -->
<div class="alpro-box" style="margin-bottom:16px; padding:10px 14px;">
  <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <input type="text" name="od_q" value="<?php echo htmlspecialchars($od_search); ?>" placeholder="Search outlet name..." style="height:30px; flex:1 1 auto; min-width:200px; padding:0 8px; border:1px solid #ccc; border-radius:4px; font-size:0.82em; min-width:200px;">
    <div style="margin-left:auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <select name="dash_month" onchange="this.form.submit()" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.82em;">
        <option value="0"<?php echo ($dash_month==0)?' selected':''; ?>>All Months</option>
        <?php foreach ($_months as $_mn => $_ml): ?>
        <option value="<?php echo $_mn; ?>"<?php echo ($dash_month==$_mn)?' selected':''; ?>><?php echo $_ml; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="dash_year" onchange="this.form.submit()" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.82em;">
        <option value="0"<?php echo ($dash_year==0)?' selected':''; ?>>All Years</option>
        <?php for ($_y=$_cur_year; $_y>=$_cur_year-4; $_y--): ?>
        <option value="<?php echo $_y; ?>"<?php echo ($dash_year==$_y)?' selected':''; ?>><?php echo $_y; ?></option>
        <?php endfor; ?>
      </select>
      <select name="od_outlet" style="height:30px; padding:0 8px; border:1px solid #ccc; border-radius:4px; font-size:0.82em; min-width:220px;">
        <option value="0">All outlets</option>
        <?php foreach ($od_outlet_options as $oid => $oname): ?>
        <option value="<?php echo $oid; ?>"<?php echo $oid === $od_filter_outlet ? ' selected' : ''; ?>><?php echo htmlspecialchars($oname); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="alpro-btn alpro-btn-blue" style="height:30px;">Show</button>
      <a href="mamabe_sku_master.php" class="alpro-btn alpro-btn-grey" style="height:30px; line-height:30px; padding:0 12px;">Reset</a>
    </div>
  </form>
</div>






<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
  <div class="alpro-box" style="padding:18px 16px; text-align:center; border-left:6px solid #e91e8c;">
    <div style="font-size:2em; font-weight:700; color:#e91e8c; line-height:1.1;"><?php echo (int)$_mama_total_qty; ?></div>
    <div style="font-size:0.8em; color:#888; margin-top:6px;">MAMA SKU sold</div>
  </div>
  <div class="alpro-box" style="padding:18px 16px; text-align:center; border-left:6px solid #00897b;">
    <div style="font-size:2em; font-weight:700; color:#00897b; line-height:1.1;"><?php echo (int)$_baby_total_qty; ?></div>
    <div style="font-size:0.8em; color:#888; margin-top:6px;">BABY SKU sold</div>
  </div>
  <div class="alpro-box" style="padding:18px 16px; text-align:center; border-left:6px solid #6a1b9a;">
    <div style="font-size:2em; font-weight:700; color:#6a1b9a; line-height:1.1;"><?php echo (int)($_mama_total_qty + $_baby_total_qty); ?></div>
    <div style="font-size:0.8em; color:#888; margin-top:6px;">Total SKU sold (mama + baby)</div>
  </div>
  <div class="alpro-box" style="padding:18px 16px; text-align:center; border-left:6px solid #2a6496;">
    <div style="font-size:2em; font-weight:700; color:#2a6496; line-height:1.1;"><?php echo (int)$od_grand_mamas; ?> <span style="font-size:0.5em; color:#888;">mama</span> / <?php echo (int)$od_grand_babies; ?> <span style="font-size:0.5em; color:#888;">baby</span></div>
    <div style="font-size:0.8em; color:#888; margin-top:6px;">Registered – all outlets</div>
  </div>
</div>
<fieldset style="margin-bottom:28px; background:#fafbfc; border:2px solid #cfd8dc; border-radius:12px; padding:16px 18px;">
  <legend style="display:flex; align-items:center; gap:10px; width:auto;">
    <b><span style="background:#eceff1;color:#263238;padding:4px 10px;border-radius:4px;font-size:1.05em;">SKU Management</span></b>
    <a href="mamabe_sku_management_export.php?dash_month=<?php echo (int)$dash_month; ?>&dash_year=<?php echo (int)$dash_year; ?>"
       class="alpro-btn alpro-btn-grey" style="font-size:0.78em; padding:3px 10px;">&#128190; Export to Excel</a>
  </legend>
<?php if (!empty($sales_mama) || !empty($sales_baby)): ?>
<div class="alpro-box" style="margin-bottom:16px; padding:10px 14px; display:flex; align-items:center; justify-content:center; gap:6px; flex-wrap:wrap; background:#f5f8fb;">
  <span style="font-size:0.82em; font-weight:bold; color:#2a6496; text-transform:uppercase; letter-spacing:1px;">Grand Total (Mama + Baby)</span>
  <span style="font-size:0.95em; font-weight:bold; color:#333;">
    <?php echo ($_mama_total_qty + $_baby_total_qty); ?> items &nbsp;|&nbsp; RM <?php echo number_format($_mama_total_sales + $_baby_total_sales, 2); ?>
  </span>
</div>
<?php endif; ?>
<div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:24px;">

  <!-- MAMA chart -->
  <div class="alpro-box" style="flex:1; min-width:340px;">
    <div style="font-size:0.78em; font-weight:bold; color:#e91e8c; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #fce4ec; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
      <span><span style="background:#fce4ec; padding:2px 8px; border-radius:3px;">Mama</span> Items Sold</span>
      <?php if (!empty($sales_mama)): ?>
      <span style="text-transform:none; letter-spacing:0; color:#c2185b;">Total: <?php echo $_mama_total_qty; ?> items &nbsp;|&nbsp; RM <?php echo number_format($_mama_total_sales, 2); ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($sales_mama)): ?>
    <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start;">
      <div style="flex:1; min-width:180px;">
        <table class="myTable alpro-table" width="100%" cellpadding="3" cellspacing="1" bgcolor="#EFEFEF">
          <tr><th width="28">#</th><th width="100">Item Code</th><th>Item Name</th><th width="45">Qty</th><th width="90">Total Price</th></tr>
          <?php $_rank=1; foreach ($sales_mama as $_sku => $_cnt):
            $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name'=>$_sku,'price'=>0,'type'=>1);
            $_total = (float)$info['price'] * $_cnt; ?>
          <tr>
            <td class="alpro-center" style="font-weight:bold;color:<?php echo $_rank<=3?'#e67e22':'#888';?>;"><?php echo $_rank++; ?></td>
            <td style="font-family:monospace; font-size:0.8em;"><?php echo htmlspecialchars($_sku); ?></td>
            <td style="font-size:0.82em; font-weight:bold;"><?php echo htmlspecialchars($info['name']); ?></td>
            <td class="alpro-center" style="font-weight:bold;"><?php echo $_cnt; ?></td>
            <td class="alpro-center" style="font-size:0.82em;">RM <?php echo number_format($_total, 2); ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="font-weight:bold; background:#fce4ec;">
            <td colspan="3" class="alpro-center">Total</td>
            <td class="alpro-center"><?php echo $_mama_total_qty; ?></td>
            <td class="alpro-center">RM <?php echo number_format($_mama_total_sales, 2); ?></td>
          </tr>
        </table>
      </div>
    </div>
    <?php else: ?>
    <p class="alpro-muted" style="font-size:0.85em;">No mama sales data<?php echo $all_time ? '.' : ' for this period.'; ?></p>
    <?php endif; ?>
  </div>

  <!-- BABY chart -->
  <div class="alpro-box" style="flex:1; min-width:340px;">
    <div style="font-size:0.78em; font-weight:bold; color:#00897b; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #e0f2f1; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
      <span><span style="background:#e0f2f1; padding:2px 8px; border-radius:3px;">Baby</span> Items Sold</span>
      <?php if (!empty($sales_baby)): ?>
      <span style="text-transform:none; letter-spacing:0; color:#00695c;">Total: <?php echo $_baby_total_qty; ?> items &nbsp;|&nbsp; RM <?php echo number_format($_baby_total_sales, 2); ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($sales_baby)): ?>
    <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start;">
      <div style="flex:1; min-width:180px;">
        <table class="myTable alpro-table" width="100%" cellpadding="3" cellspacing="1" bgcolor="#EFEFEF">
          <tr><th width="28">#</th><th width="100">Item Code</th><th>Item Name</th><th width="45">Qty</th><th width="90">Total Price</th></tr>
          <?php $_rank=1; foreach ($sales_baby as $_sku => $_cnt):
            $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name'=>$_sku,'price'=>0,'type'=>2);
            $_total = (float)$info['price'] * $_cnt; ?>
          <tr>
            <td class="alpro-center" style="font-weight:bold;color:<?php echo $_rank<=3?'#e67e22':'#888';?>;"><?php echo $_rank++; ?></td>
            <td style="font-family:monospace; font-size:0.8em;"><?php echo htmlspecialchars($_sku); ?></td>
            <td style="font-size:0.82em; font-weight:bold;"><?php echo htmlspecialchars($info['name']); ?></td>
            <td class="alpro-center" style="font-weight:bold;"><?php echo $_cnt; ?></td>
            <td class="alpro-center" style="font-size:0.82em;">RM <?php echo number_format($_total, 2); ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="font-weight:bold; background:#e0f2f1;">
            <td colspan="3" class="alpro-center">Total</td>
            <td class="alpro-center"><?php echo $_baby_total_qty; ?></td>
            <td class="alpro-center">RM <?php echo number_format($_baby_total_sales, 2); ?></td>
          </tr>
        </table>
      </div>
    </div>
    <?php else: ?>
    <p class="alpro-muted" style="font-size:0.85em;">No baby sales data<?php echo $all_time ? '.' : ' for this period.'; ?></p>
    <?php endif; ?>
  </div>

</div>
<fieldset style="margin-bottom:24px; background:#fff; border-radius:10px; box-shadow:0 2px 6px rgba(0,0,0,0.06); padding:14px 18px;">
<legend><b><span style="background:#f3e5f5;color:#6a1b9a;padding:3px 8px;border-radius:4px;">50% OFF SKUs</span></b></legend>
<div style="display:flex; gap:20px; flex-wrap:wrap;">

  <!-- MAMA SKUs -->
  <div style="flex:1; min-width:420px;">
    <fieldset>
      <legend><b><span style="background:#fce4ec;color:#e91e8c;padding:3px 8px;border-radius:4px;">MAMA 50% OFF SKUs</span></b></legend>
      <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
          <th width="35">#</th>
          <th>SKU Code</th>
          <th>Item Name</th>
          <th width="90">After 50% (RM)</th>
          <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?><th width="55">Action</th><?php endif; ?>
        </tr>
        <?php if ($skus_mama && $skus_mama->num_rows > 0): $i = 1; while ($row = $skus_mama->fetch_assoc()): ?>
        <tr>
          <td class="alpro-center"><?php echo $i++; ?></td>
          <td><b><?php echo htmlspecialchars($row['sku']); ?></b></td>
          <td><?php echo htmlspecialchars($row['item_name']); ?></td>
          <td class="alpro-center"><?php echo 'RM ' . number_format($row['price'], 2); ?></td>
          <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?>
          <td class="alpro-center" style="white-space:nowrap;"><button type="button" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;" onclick="openSkuEdit(<?php echo $row['id']; ?>,'<?php echo addslashes($row['sku']); ?>','<?php echo addslashes($row['item_name']); ?>',<?php echo $row['price']; ?>,<?php echo $row['type']; ?>)">&#9998;</button><?php if ($_is_super_admin): ?> <form method="post" style="display:inline;" onsubmit="return confirm('Remove this SKU?');"><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" name="delete_sku" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;color:#c62828;">&times;</button></form><?php endif; ?></td>
          <?php endif; ?>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="<?php echo (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)) ? 5 : 4; ?>" class="alpro-center alpro-muted">No Mama SKUs configured.</td></tr>
        <?php endif; ?>
      </table>
    </fieldset>
  </div>

  <!-- BABY SKUs -->
  <div style="flex:1; min-width:420px;">
    <fieldset>
      <legend><b><span style="background:#e0f2f1;color:#00897b;padding:3px 8px;border-radius:4px;">BABY 50% OFF SKUs</span></b></legend>
      <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
          <th width="35">#</th>
          <th>SKU Code</th>
          <th>Item Name</th>
          <th width="90">After 50% (RM)</th>
          <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?><th width="55">Action</th><?php endif; ?>
        </tr>
        <?php if ($skus_baby && $skus_baby->num_rows > 0): $i = 1; while ($row = $skus_baby->fetch_assoc()): ?>
        <tr>
          <td class="alpro-center"><?php echo $i++; ?></td>
          <td><b><?php echo htmlspecialchars($row['sku']); ?></b></td>
          <td><?php echo htmlspecialchars($row['item_name']); ?></td>
          <td class="alpro-center"><?php echo 'RM ' . number_format($row['price'], 2); ?></td>
          <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?>
          <td class="alpro-center" style="white-space:nowrap;"><button type="button" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;" onclick="openSkuEdit(<?php echo $row['id']; ?>,'<?php echo addslashes($row['sku']); ?>','<?php echo addslashes($row['item_name']); ?>',<?php echo $row['price']; ?>,<?php echo $row['type']; ?>)">&#9998;</button><?php if ($_is_super_admin): ?> <form method="post" style="display:inline;" onsubmit="return confirm('Remove this SKU?');"><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" name="delete_sku" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;color:#c62828;">&times;</button></form><?php endif; ?></td>
          <?php endif; ?>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="<?php echo (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)) ? 5 : 4; ?>" class="alpro-center alpro-muted">No Baby SKUs configured.</td></tr>
        <?php endif; ?>
      </table>
    </fieldset>
  </div>

</div>

<?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?>
<div style="margin-top:20px; padding-top:14px; border-top:1px solid #e0e8f0;">
  <div style="font-size:0.8em; font-weight:bold; color:#2a6496; margin-bottom:10px;">+ ADD NEW SKU</div>
  <form method="post">
    <table cellpadding="6" cellspacing="0" style="width:100%;">
      <tr>
        <td style="width:110px; font-size:0.84em; font-weight:bold;">Item Code</td>
        <td style="width:30%;"><input type="text" name="sku" required style="width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-family:monospace; font-size:0.9em;"></td>
        <td style="width:110px; padding-left:24px; font-size:0.84em; font-weight:bold; white-space:nowrap;">Item Name</td>
        <td><input type="text" name="item_name" required style="width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.9em;"></td>
      </tr>
      <tr>
        <td style="font-size:0.84em; font-weight:bold;">Price (After 50%)</td>
        <td style="width:30%;"><input type="number" name="price" value="0" step="0.01" min="0" style="width:140px; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.9em;"></td>
        <td style="width:110px; padding-left:24px; font-size:0.84em; font-weight:bold; white-space:nowrap;">Type</td>
        <td>
          <label style="margin-right:14px; font-size:0.88em;"><input type="radio" name="type" value="1" checked> <span style="background:#fce4ec;color:#e91e8c;padding:1px 7px;border-radius:3px;font-weight:bold;">Mama</span></label>
          <label style="font-size:0.88em;"><input type="radio" name="type" value="2"> <span style="background:#e0f2f1;color:#00897b;padding:1px 7px;border-radius:3px;font-weight:bold;">Baby</span></label>
        </td>
      </tr>
      <tr>
        <td colspan="4" style="padding-top:8px; text-align:right;"><button type="submit" name="add_sku" class="alpro-btn alpro-btn-blue">Add SKU</button></td>
      </tr>
    </table>
  </form>
</div>
</fieldset>

<fieldset style="margin-top:24px; background:#fff; border-radius:10px; box-shadow:0 2px 6px rgba(0,0,0,0.06); padding:14px 18px;">
  <legend><b><span style="background:#f3e5f5;color:#6a1b9a;padding:3px 8px;border-radius:4px;">&#9998; Free Items (Receipt-Matched)</span></b></legend>
  <div style="font-size:0.8em; color:#777; margin-bottom:12px;">Any item added here is auto-added on the Mama Detail page's "Free Items" list when a matching item code appears on a verified receipt. Add as many as needed for Mama or Baby.</div>

  <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:16px;">
    <div style="flex:1; min-width:320px;">
      <div style="margin-bottom:8px;"><span style="background:#fce4ec;color:#e91e8c;padding:3px 8px;border-radius:4px;font-size:0.9em;font-weight:bold;">MAMA Free Items</span></div>
      <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th width="35">#</th><th>Item Code</th><th>Item Name</th><th width="55">Action</th></tr>
        <?php if ($free_items_mama_list && $free_items_mama_list->num_rows > 0): $i = 1; while ($row = $free_items_mama_list->fetch_assoc()): ?>
        <tr>
          <td class="alpro-center"><?php echo $i++; ?></td>
          <td style="font-weight:bold;"><?php echo htmlspecialchars($row['item_code']); ?></td>
          <td><?php echo htmlspecialchars($row['item_name']); ?></td>
          <td class="alpro-center" style="white-space:nowrap;">
            <button type="button" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;" onclick="openFreeItemEdit(<?php echo $row['id']; ?>,'mama','<?php echo addslashes($row['item_code']); ?>','<?php echo addslashes($row['item_name']); ?>')">&#9998;</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this free item?');">
              <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
              <button type="submit" name="delete_free_item" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;color:#c62828;">&times;</button>
            </form>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="4" class="alpro-center alpro-muted">No Mama free items configured.</td></tr>
        <?php endif; ?>
      </table>
    </div>

    <div style="flex:1; min-width:320px;">
      <div style="margin-bottom:8px;"><span style="background:#e0f2f1;color:#00897b;padding:3px 8px;border-radius:4px;font-size:0.9em;font-weight:bold;">BABY Free Items</span></div>
      <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th width="35">#</th><th>Item Code</th><th>Item Name</th><th width="55">Action</th></tr>
        <?php if ($free_items_baby_list && $free_items_baby_list->num_rows > 0): $i = 1; while ($row = $free_items_baby_list->fetch_assoc()): ?>
        <tr>
          <td class="alpro-center"><?php echo $i++; ?></td>
          <td style="font-weight:bold;"><?php echo htmlspecialchars($row['item_code']); ?></td>
          <td><?php echo htmlspecialchars($row['item_name']); ?></td>
          <td class="alpro-center" style="white-space:nowrap;">
            <button type="button" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;" onclick="openFreeItemEdit(<?php echo $row['id']; ?>,'baby','<?php echo addslashes($row['item_code']); ?>','<?php echo addslashes($row['item_name']); ?>')">&#9998;</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this free item?');">
              <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
              <button type="submit" name="delete_free_item" class="alpro-btn alpro-btn-grey" style="padding:2px 8px;font-size:0.78em;color:#c62828;">&times;</button>
            </form>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="4" class="alpro-center alpro-muted">No Baby free items configured.</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div style="margin-top:20px; padding-top:14px; border-top:1px solid #e0e8f0;"><div style="font-size:0.8em; font-weight:bold; color:#2a6496; margin-bottom:10px;">+ ADD FREE ITEM</div>
  <form method="post">
    <table cellpadding="6" cellspacing="0" style="width:100%;">
      <tr>
        <td style="width:110px; font-size:0.84em; font-weight:bold;">Item Code</td>
        <td style="width:30%;"><input type="text" name="item_code" required style="width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-family:monospace; font-size:0.9em;"></td>
        <td style="width:110px; padding-left:24px; font-size:0.84em; font-weight:bold; white-space:nowrap;">Item Name</td>
        <td><input type="text" name="item_name" required style="width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.9em;"></td>
      </tr>
      <tr>
        <td style="font-size:0.84em; font-weight:bold;">Applies To</td>
        <td>
          <label style="margin-right:14px; font-size:0.88em;"><input type="radio" name="scope" value="mama" checked> <span style="background:#f3e8ff;color:#7c3db5;padding:1px 7px;border-radius:3px;font-weight:bold;">Mama</span></label>
          <label style="font-size:0.88em;"><input type="radio" name="scope" value="baby"> <span style="background:#e0f2f1;color:#00897b;padding:1px 7px;border-radius:3px;font-weight:bold;">Baby</span></label>
        </td>
      </tr>
      <tr>
        <td colspan="4" style="padding-top:8px; text-align:right;"><button type="submit" name="add_free_item" class="alpro-btn alpro-btn-blue">Add Free Item</button></td>
      </tr>
    </table>
  </form>
</div>

<!-- Free Item Edit Modal -->
<div id="free-item-edit-overlay" onclick="if(event.target===this)closeFreeItemEdit();"
     style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:#fff;width:100%;max-width:440px;border-radius:8px;box-shadow:0 6px 32px rgba(0,0,0,0.3);overflow:hidden;margin:auto;">
    <div style="background:#2a7a4a;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:bold;">&#9998; Edit Free Item</span>
      <button type="button" onclick="closeFreeItemEdit()" style="background:none;border:none;color:#fff;font-size:1.5em;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:18px;">
      <form method="post">
        <input type="hidden" name="edit_free_item" value="1">
        <input type="hidden" name="edit_id" id="fi_edit_id">
        <table cellpadding="7" cellspacing="0" style="width:100%;">
          <tr>
            <td style="font-size:0.84em;font-weight:bold;width:110px;">Item Code</td>
            <td><input type="text" name="edit_item_code" id="fi_edit_code" required style="width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;font-size:0.9em;box-sizing:border-box;"></td>
          </tr>
          <tr>
            <td style="font-size:0.84em;font-weight:bold;">Item Name</td>
            <td><input type="text" name="edit_item_name" id="fi_edit_name" required style="width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:0.9em;box-sizing:border-box;"></td>
          </tr>
          <tr>
            <td style="font-size:0.84em;font-weight:bold;">Applies To</td>
            <td>
              <label style="margin-right:14px;font-size:0.88em;"><input type="radio" name="edit_scope" id="fi_edit_scope_mama" value="mama"> <span style="background:#f3e8ff;color:#7c3db5;padding:1px 7px;border-radius:3px;font-weight:bold;">Mama</span></label>
              <label style="font-size:0.88em;"><input type="radio" name="edit_scope" id="fi_edit_scope_baby" value="baby"> <span style="background:#e0f2f1;color:#00897b;padding:1px 7px;border-radius:3px;font-weight:bold;">Baby</span></label>
            </td>
          </tr>
          <tr>
            <td></td>
            <td style="padding-top:8px;">
              <button type="submit" class="alpro-btn alpro-btn-blue">Save Changes</button>
              <button type="button" onclick="closeFreeItemEdit()" class="alpro-btn alpro-btn-grey" style="margin-left:6px;">Cancel</button>
            </td>
          </tr>
        </table>
      </form>
    </div>
  </div>
</div>
</fieldset>

</fieldset>

<fieldset style="margin-top:24px; background:#fff; border-radius:10px; box-shadow:0 2px 6px rgba(0,0,0,0.06); padding:14px 18px;">
  <legend style="display:flex; align-items:center; gap:10px; width:auto;">
    <b><span style="background:#e3f2fd;color:#1565c0;padding:3px 8px;border-radius:4px;">Registrations per Outlet</span></b>
    <a href="mamabe_outlet_report_export.php?dash_month=<?php echo (int)$dash_month; ?>&dash_year=<?php echo (int)$dash_year; ?>&od_outlet=<?php echo (int)$od_filter_outlet; ?>&od_q=<?php echo urlencode($od_search); ?>"
       class="alpro-btn alpro-btn-grey" style="font-size:0.78em; padding:3px 10px;">&#128190; Export to Excel</a>
  </legend>
  <?php include __DIR__ . '/mamabe_outlet_section.php'; ?>
</fieldset>

<script>
function openFreeItemEdit(id, scope, code, name) {
    document.getElementById('fi_edit_id').value = id;
    document.getElementById('fi_edit_code').value = code;
    document.getElementById('fi_edit_name').value = name;
    document.getElementById('fi_edit_scope_mama').checked = (scope === 'mama');
    document.getElementById('fi_edit_scope_baby').checked = (scope === 'baby');
    document.getElementById('free-item-edit-overlay').style.display = 'flex';
}
function closeFreeItemEdit() {
    document.getElementById('free-item-edit-overlay').style.display = 'none';
}
</script>
<?php endif; ?>

<!-- SKU Edit Modal -->
<div id="sku-edit-overlay" onclick="if(event.target===this)closeSkuEdit();"
     style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:#fff;width:100%;max-width:440px;border-radius:8px;box-shadow:0 6px 32px rgba(0,0,0,0.3);overflow:hidden;margin:auto;">
    <div style="background:#2a6496;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:bold;">&#9998; Edit SKU</span>
      <button type="button" onclick="closeSkuEdit()" style="background:none;border:none;color:#fff;font-size:1.5em;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:18px;">
      <form method="post" id="sku-edit-form">
        <input type="hidden" name="edit_sku" value="1">
        <input type="hidden" name="edit_id" id="edit_id">
        <table cellpadding="7" cellspacing="0" style="width:100%;">
          <tr>
            <td style="font-size:0.84em;font-weight:bold;width:110px;">SKU Code</td>
            <td><input type="text" name="edit_sku_code" id="edit_sku_code" required style="width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;font-size:0.9em;box-sizing:border-box;"></td>
          </tr>
          <tr>
            <td style="font-size:0.84em;font-weight:bold;">Item Name</td>
            <td><input type="text" name="edit_item_name" id="edit_item_name" required style="width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:0.9em;box-sizing:border-box;"></td>
          </tr>
          <tr>
            <td style="font-size:0.84em;font-weight:bold;">Price (After 50%)</td>
            <td><input type="number" name="edit_price" id="edit_price" step="0.01" min="0" style="width:150px;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:0.9em;"></td>
          </tr>
          <tr>
            <td style="font-size:0.84em;font-weight:bold;">Type</td>
            <td>
              <label style="margin-right:14px;font-size:0.88em;"><input type="radio" name="edit_type" id="edit_type_1" value="1"> <span style="background:#fce4ec;color:#e91e8c;padding:1px 7px;border-radius:3px;font-weight:bold;">Mama</span></label>
              <label style="font-size:0.88em;"><input type="radio" name="edit_type" id="edit_type_2" value="2"> <span style="background:#e0f2f1;color:#00897b;padding:1px 7px;border-radius:3px;font-weight:bold;">Baby</span></label>
            </td>
          </tr>
          <tr>
            <td></td>
            <td style="padding-top:8px;">
              <button type="submit" class="alpro-btn alpro-btn-blue">Save Changes</button>
              <button type="button" onclick="closeSkuEdit()" class="alpro-btn alpro-btn-grey" style="margin-left:6px;">Cancel</button>
            </td>
          </tr>
        </table>
      </form>
    </div>
  </div>
</div>
<script>
function openSkuEdit(id, sku, name, price, type) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_sku_code').value = sku;
    document.getElementById('edit_item_name').value = name;
    document.getElementById('edit_price').value = parseFloat(price).toFixed(2);
    document.getElementById('edit_type_1').checked = (type == 1);
    document.getElementById('edit_type_2').checked = (type == 2);
    var ov = document.getElementById('sku-edit-overlay');
    ov.style.display = 'flex';
}
function closeSkuEdit() {
    document.getElementById('sku-edit-overlay').style.display = 'none';
}
</script>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
