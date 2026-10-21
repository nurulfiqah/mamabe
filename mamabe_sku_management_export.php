<?php
/**
 * CSV export (opens in Excel) of the SKU Management section: Mama Items Sold, Baby Items Sold,
 * and Grand Total (Mama + Baby). Uses the same dash_month/dash_year filters as mamabe_sku_master.php,
 * so "Show" on that page and "Export" here agree.
 */
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
ob_end_clean();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}

$sku_name_map = array();
$_sm = $conn->query("SELECT sku, item_name, price, type FROM mamabe_sku_master WHERE recycle=0");
if ($_sm) { while ($_r = $_sm->fetch_assoc()) { $sku_name_map[$_r['sku']] = array('name' => $_r['item_name'], 'price' => (float)$_r['price'], 'type' => (int)$_r['type']); } }

$dash_year  = isset($_GET['dash_year'])  ? (int)$_GET['dash_year']  : (int)date('Y');
$dash_month = isset($_GET['dash_month']) ? (int)$_GET['dash_month'] : (int)date('n');

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

$_mama_total_sales = 0; $_mama_total_qty = 0;
$_baby_total_sales = 0; $_baby_total_qty = 0;
foreach ($sales_mama as $_sku => $_cnt) {
    $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name' => $_sku, 'price' => 0, 'type' => 1);
    $_mama_total_sales += (float)$info['price'] * $_cnt;
    $_mama_total_qty   += $_cnt;
}
foreach ($sales_baby as $_sku => $_cnt) {
    $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name' => $_sku, 'price' => 0, 'type' => 2);
    $_baby_total_sales += (float)$info['price'] * $_cnt;
    $_baby_total_qty   += $_cnt;
}

$_months = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                 7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');
$_period = ($dash_month ? $_months[$dash_month] : 'All months') . ' ' . ($dash_year ? $dash_year : 'all years');

$filename = 'mamabe_sku_management_' . ($dash_year ?: 'all') . '-' . ($dash_month ?: 'all') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accented outlet names correctly

fputcsv($out, array('MamaBe SKU Management Report - ' . $_period));
fputcsv($out, array());

// ── Mama Items Sold ──────────────────────────────────────────────────────────
fputcsv($out, array('Mama Items Sold'));
fputcsv($out, array('#', 'Item Code', 'Item Name', 'Qty', 'Total Price (RM)'));
if (empty($sales_mama)) {
    fputcsv($out, array('', 'No mama sales data for this period.'));
} else {
    $_rank = 1;
    foreach ($sales_mama as $_sku => $_cnt) {
        $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name' => $_sku, 'price' => 0, 'type' => 1);
        fputcsv($out, array($_rank++, $_sku, $info['name'], $_cnt, number_format((float)$info['price'] * $_cnt, 2, '.', '')));
    }
    fputcsv($out, array('', '', 'Total', $_mama_total_qty, number_format($_mama_total_sales, 2, '.', '')));
}
fputcsv($out, array());

// ── Baby Items Sold ──────────────────────────────────────────────────────────
fputcsv($out, array('Baby Items Sold'));
fputcsv($out, array('#', 'Item Code', 'Item Name', 'Qty', 'Total Price (RM)'));
if (empty($sales_baby)) {
    fputcsv($out, array('', 'No baby sales data for this period.'));
} else {
    $_rank = 1;
    foreach ($sales_baby as $_sku => $_cnt) {
        $info = isset($sku_name_map[$_sku]) ? $sku_name_map[$_sku] : array('name' => $_sku, 'price' => 0, 'type' => 2);
        fputcsv($out, array($_rank++, $_sku, $info['name'], $_cnt, number_format((float)$info['price'] * $_cnt, 2, '.', '')));
    }
    fputcsv($out, array('', '', 'Total', $_baby_total_qty, number_format($_baby_total_sales, 2, '.', '')));
}
fputcsv($out, array());

// ── Grand Total (Mama + Baby) ────────────────────────────────────────────────
fputcsv($out, array('Grand Total (Mama + Baby)'));
fputcsv($out, array('Items', 'Total Price (RM)'));
fputcsv($out, array($_mama_total_qty + $_baby_total_qty, number_format($_mama_total_sales + $_baby_total_sales, 2, '.', '')));

fclose($out);
exit;
