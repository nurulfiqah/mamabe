<?php
/**
 * CSV export (opens in Excel) of the Registrations per Outlet section. Uses the same dash_month/
 * dash_year/od_outlet/od_q filters as mamabe_sku_master.php, so "Show" on that page and "Export" here agree.
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

// Reads dash_month/dash_year/od_outlet/od_q from $_GET itself and sets $od_rows / $od_total_mamas / $od_total_babies.
require __DIR__ . '/mamabe_outlet_data.php';

$_months = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                 7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');
$_period = ($od_month ? $_months[$od_month] : 'All months') . ' ' . ($od_year ? $od_year : 'all years');

$filename = 'mamabe_outlet_report_' . ($od_year ?: 'all') . '-' . ($od_month ?: 'all') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accented outlet names correctly

fputcsv($out, array('MamaBe Registrations per Outlet - ' . $_period));
fputcsv($out, array());
fputcsv($out, array('#', 'Outlet', 'Mama', 'Baby'));
if (empty($od_rows)) {
    fputcsv($out, array('', 'No outlets found.'));
} else {
    $_i = 1;
    foreach ($od_rows as $r) {
        fputcsv($out, array($_i++, MamabeRecords::outletName($r['comp_name']), (int)$r['mamas'], (int)$r['babies']));
    }
    fputcsv($out, array('', 'Total', $od_total_mamas, $od_total_babies));
}

fclose($out);
exit;
