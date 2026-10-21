<?php
// HQ dashboard: MamaBe registrations per outlet for a chosen month - how many mamas and babies each outlet has
// registered. Credited to the outlet the mama is registered under (mamabe_mama.outlet_id), counted on the day each
// record was created. Staff with MamaBe admin access (mamab_autho 1 or 3) only.
require_once('../lock_adv.php');
require_once(__DIR__ . '/registration/common/lib/MamabeRecords.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

if (!isset($mamab_autho) || !in_array($mamab_autho, array('1', '3'), true)) {
    header('Location: /odb/index.php');
    exit;
}

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

$start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$end   = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));

// Every active Alpro Pharmacy / Alpro Baby outlet (same list the public form offers), with its mama and baby counts.
$sql = "
    SELECT o.id, o.code, o.comp_name,
           (SELECT COUNT(*) FROM mamabe_mama m
             WHERE m.outlet_id = o.id AND m.recycle = 0 AND m.timestamp >= ? AND m.timestamp < ?) AS mamas,
           (SELECT COUNT(*) FROM mamabe_baby b
              JOIN mamabe_mama m ON m.id = b.mama_id
             WHERE m.outlet_id = o.id AND b.recycle = 0 AND b.timestamp >= ? AND b.timestamp < ?) AS babies
      FROM outlet o
     WHERE o.recycle = 0
       AND (o.comp_name LIKE 'ALPRO PHARMA%' OR o.comp_name LIKE 'ALPRO BABY%')
     ORDER BY o.comp_name";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssss', $start, $end, $start, $end);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$filter_outlet = isset($_GET['outlet']) ? (int)$_GET['outlet'] : 0;
$search        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Outlet dropdown list = every outlet in the query (all active), before the activity filter below.
$outletOptions = array();
foreach ($rows as $r) $outletOptions[(int)$r['id']] = MamabeRecords::outletName($r['comp_name']);
asort($outletOptions);

// Only outlets that registered someone this month, busiest first (mamas + babies, then mamas, then name).
$rows = array_values(array_filter($rows, function ($r) use ($filter_outlet, $search) {
    if ((int)$r['mamas'] + (int)$r['babies'] === 0) return false;
    if ($filter_outlet && (int)$r['id'] !== $filter_outlet) return false;
    if ($search !== '' && mb_stripos($r['comp_name'], $search, 0, 'UTF-8') === false) return false;
    return true;
}));
usort($rows, function ($a, $b) {
    $ta = (int)$a['mamas'] + (int)$a['babies'];
    $tb = (int)$b['mamas'] + (int)$b['babies'];
    return $tb <=> $ta ?: (int)$b['mamas'] <=> (int)$a['mamas'] ?: strcmp($a['comp_name'], $b['comp_name']);
});

$totalMamas = 0; $totalBabies = 0;
foreach ($rows as $r) { $totalMamas += (int)$r['mamas']; $totalBabies += (int)$r['babies']; }


$months = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Outlet Dashboard</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.od-stats { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.od-card { flex:1 1 180px; background:#fff; border-radius:10px; border-left:6px solid #ccc; padding:16px 20px; box-shadow:0 2px 6px rgba(0,0,0,0.08); }
.od-card .num { font-size:2em; font-weight:700; line-height:1.1; }
.od-card .lbl { font-size:0.83em; color:#888; margin-top:4px; }
.od-card.c-pink { border-color:#e91e8c; } .od-card.c-pink .num { color:#e91e8c; }
.od-card.c-blue { border-color:#2a6496; } .od-card.c-blue .num { color:#2a6496; }
.od-zero { color:#bbb; }
.od-table td, .od-table th { padding:8px 10px; }
.od-table tfoot td { font-weight:bold; background:#f5f9ff; }
.od-filter { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
.od-filter select, .od-filter input[type=text] { height:36px; padding:0 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; box-sizing:border-box; min-width:150px; line-height:normal; }
.od-filter select[name=outlet] { min-width:280px; }
.od-filter input[type=text] { flex:1 1 220px; min-width:220px; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mamabee.png"> MamaBe – Outlet Dashboard</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<div class="od-stats">
  <div class="od-card c-pink">
    <div class="num"><?php echo $totalMamas; ?></div>
    <div class="lbl">Mama registered – <?php echo $months[$month] . ' ' . $year; ?></div>
  </div>
  <div class="od-card c-blue">
    <div class="num"><?php echo $totalBabies; ?></div>
    <div class="lbl">Baby registered – <?php echo $months[$month] . ' ' . $year; ?></div>
  </div>
</div>

<fieldset>
  <legend><b>Registrations per Outlet</b></legend>

  <form method="get" class="od-filter">
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search outlet name...">
    <select name="month">
      <?php foreach ($months as $n => $label): ?>
      <option value="<?php echo $n; ?>" <?php echo $n === $month ? 'selected' : ''; ?>><?php echo $label; ?></option>
      <?php endforeach; ?>
    </select>
    <select name="year">
      <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
      <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
      <?php endfor; ?>
    </select>
    <select name="outlet">
      <option value="0">All outlets</option>
      <?php foreach ($outletOptions as $oid => $oname): ?>
      <option value="<?php echo $oid; ?>" <?php echo $oid === $filter_outlet ? 'selected' : ''; ?>><?php echo htmlspecialchars($oname); ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="alpro-btn alpro-btn-blue">Show</button>
  </form>

  <table class="myTable alpro-table od-table" width="100%">
    <thead>
      <tr>
        <th width="40">#</th>
        <th>Outlet</th>
        <th width="120" class="alpro-center">Mama</th>
        <th width="120" class="alpro-center">Baby</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="4" class="alpro-center alpro-muted">No outlets found.</td></tr>
    <?php else: $i = 1; foreach ($rows as $r): ?>
      <tr>
        <td class="alpro-center"><?php echo $i++; ?></td>
        <td><?php echo htmlspecialchars(MamabeRecords::outletName($r["comp_name"])); ?></td>
        <td class="alpro-center <?php echo (int)$r['mamas'] === 0 ? 'od-zero' : ''; ?>"><?php echo (int)$r['mamas']; ?></td>
        <td class="alpro-center <?php echo (int)$r['babies'] === 0 ? 'od-zero' : ''; ?>"><?php echo (int)$r['babies']; ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" class="alpro-center">Total</td>
        <td class="alpro-center"><?php echo $totalMamas; ?></td>
        <td class="alpro-center"><?php echo $totalBabies; ?></td>
      </tr>
    </tfoot>
  </table>
</fieldset>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
