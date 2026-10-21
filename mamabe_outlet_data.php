<?php
// Outlet registration list (mamas + babies per outlet). Included inside the SKU Master page. Uses the page's shared
// period filter (dash_month / dash_year, 0 = All) - the same one that drives the sales figures above it - plus its own
// outlet and search filters. Needs $conn and the MamabeRecords class already loaded by the including page.
require_once(__DIR__ . '/registration/common/lib/MamabeRecords.php');

$od_months = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');

$od_month = isset($_GET['dash_month']) ? (int)$_GET['dash_month'] : (int)date('n');
$od_year  = isset($_GET['dash_year'])  ? (int)$_GET['dash_year']  : (int)date('Y');
if ($od_month < 0 || $od_month > 12) $od_month = 0;
if ($od_year < 2000 || $od_year > 2100) $od_year = 0;
$od_filter_outlet = isset($_GET['od_outlet']) ? (int)$_GET['od_outlet'] : 0;
$od_search        = isset($_GET['od_q']) ? trim((string)$_GET['od_q']) : '';

$od_label = ($od_month ? $od_months[$od_month] : 'All months') . ' ' . ($od_year ? $od_year : 'all years');

$od_date_cond = function ($col) use ($od_month, $od_year) {
    $c = '';
    if ($od_year)  $c .= " AND YEAR($col) = $od_year";
    if ($od_month) $c .= " AND MONTH($col) = $od_month";
    return $c;
};
$od_mama_cond = $od_date_cond('m.timestamp');
$od_baby_cond = $od_date_cond('b.timestamp');

$od_all = $conn->query("
    SELECT o.id, o.comp_name,
           (SELECT COUNT(*) FROM mamabe_mama m
             WHERE m.outlet_id = o.id AND m.recycle = 0 $od_mama_cond) AS mamas,
           (SELECT COUNT(*) FROM mamabe_baby b
              JOIN mamabe_mama m ON m.id = b.mama_id
             WHERE m.outlet_id = o.id AND b.recycle = 0 $od_baby_cond) AS babies
      FROM outlet o
     WHERE o.recycle = 0
       AND (o.comp_name LIKE 'ALPRO PHARMA%' OR o.comp_name LIKE 'ALPRO BABY%')
     ORDER BY o.comp_name")->fetch_all(MYSQLI_ASSOC);

$od_outlet_options = array();
foreach ($od_all as $r) $od_outlet_options[(int)$r['id']] = MamabeRecords::outletName($r['comp_name']);
asort($od_outlet_options);

$od_rows = array_values(array_filter($od_all, function ($r) use ($od_filter_outlet, $od_search) {
    if ((int)$r['mamas'] + (int)$r['babies'] === 0) return false;
    if ($od_filter_outlet && (int)$r['id'] !== $od_filter_outlet) return false;
    if ($od_search !== '' && mb_stripos($r['comp_name'], $od_search, 0, 'UTF-8') === false) return false;
    return true;
}));
usort($od_rows, function ($a, $b) {
    $ta = (int)$a['mamas'] + (int)$a['babies'];
    $tb = (int)$b['mamas'] + (int)$b['babies'];
    return $tb <=> $ta ?: (int)$b['mamas'] <=> (int)$a['mamas'] ?: strcmp($a['comp_name'], $b['comp_name']);
});

$od_total_mamas = 0; $od_total_babies = 0;
foreach ($od_rows as $r) { $od_total_mamas += (int)$r['mamas']; $od_total_babies += (int)$r['babies']; }
$od_grand_mamas = 0; $od_grand_babies = 0;
foreach ($od_all as $r) { $od_grand_mamas += (int)$r['mamas']; $od_grand_babies += (int)$r['babies']; }
?>
