<?php
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
ob_end_clean();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}

$lang_map = array(1 => 'English', 2 => 'BM', 3 => 'Chinese', 4 => 'Tamil');

// ── Access control — identical scoping to mamabe_mama_list.php ──
$_is_admin    = (isset($mamab_autho) && ($mamab_autho == '1' || $mamab_autho == '3'));
$_staff_outlets = array();
if (!$_is_admin && !empty($outlet_autho)) {
    $_staff_outlets = array_filter(array_map('intval', explode(',', $outlet_autho)));
}
$_outlet_in = !empty($_staff_outlets) ? implode(',', $_staff_outlets) : '';

// ── Same filters as the Mama List page (export respects the current view) ──
$search        = isset($_GET['search'])     ? trim($_GET['search'])       : '';
$filter_outlet = isset($_GET['outlet'])     ? (int)$_GET['outlet']        : 0;
$filter_baby   = isset($_GET['baby_status'])? trim($_GET['baby_status'])  : '';
$filter_month  = isset($_GET['month'])      ? (int)$_GET['month']         : 0;
$filter_year   = isset($_GET['year'])       ? (int)$_GET['year']          : 0;
$filter_status = isset($_GET['status']) && in_array($_GET['status'], array('active','inactive','all','website')) ? $_GET['status'] : 'active';

$where  = "m.recycle=0";
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
    $where .= " AND m.registered_by = 'MamaBe website form'";
}
if ($filter_baby === 'expecting') {
    $where .= " AND EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_id=m.id AND b.recycle=0 AND b.is_delivered=0)";
} elseif ($filter_baby === 'delivered') {
    $where .= " AND EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_id=m.id AND b.recycle=0 AND b.is_delivered=1)";
} elseif ($filter_baby === 'miscarriage') {
    $where .= " AND EXISTS (SELECT 1 FROM mamabe_baby b WHERE b.mama_id=m.id AND b.recycle=0 AND b.is_delivered=2)";
}

// No pagination — export every row matching the current filter.
$sql  = "SELECT m.id, m.mama_name, m.ic, m.phone, m.address, m.language, m.timestamp, m.registered_by,
                o.comp_name AS outlet_name
         FROM mamabe_mama m
         LEFT JOIN outlet o ON m.outlet_id = o.id
         WHERE $where
         ORDER BY m.timestamp DESC";
$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$mamas = $stmt->get_result();

$mama_ids   = array();
$mamas_rows = array();
while ($row = $mamas->fetch_assoc()) {
    $mamas_rows[] = $row;
    $mama_ids[]   = (int)$row['id'];
}
$stmt->close();

$babies_map = array();
if (!empty($mama_ids)) {
    $in = implode(',', $mama_ids);
    $br = $conn->query("SELECT mama_id, lmp, edd FROM mamabe_baby WHERE recycle=0 AND mama_id IN ($in) ORDER BY timestamp ASC");
    if ($br) {
        while ($b = $br->fetch_assoc()) {
            $babies_map[$b['mama_id']][] = $b;
        }
    }
}

require_once('../common/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book  = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Mama List');

$headers = array('Name', 'IC', 'Phone', 'Address', 'Language', 'Outlet', 'Registered', 'Registered By', 'LMP', 'EDD');
$cols    = array('A','B','C','D','E','F','G','H','I','J');
foreach ($headers as $i => $h) {
    $sheet->setCellValue($cols[$i] . '1', $h);
}
$sheet->getStyle('A1:J1')->getFont()->setBold(true);
$sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F0FA');

$r = 2;
foreach ($mamas_rows as $m) {
    $lang    = isset($lang_map[$m['language']]) ? $lang_map[$m['language']] : '';
    $outlet  = isset($m['outlet_name']) ? $m['outlet_name'] : '';
    $babies  = isset($babies_map[$m['id']]) ? $babies_map[$m['id']] : array();
    // Flatten multi-line address into one line so rows stay compact.
    $address = trim(preg_replace('/\s*[\r\n]+\s*/', ', ', (string)$m['address']));

    $babyRows = empty($babies) ? array(array('lmp' => '', 'edd' => '')) : $babies;
    foreach ($babyRows as $b) {
        $sheet->setCellValue('A' . $r, $m['mama_name']);
        // IC/phone as explicit text — otherwise Excel treats the long digit
        // string as a number and mangles it into scientific notation.
        $sheet->setCellValueExplicit('B' . $r, $m['ic'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C' . $r, $m['phone'], DataType::TYPE_STRING);
        $sheet->setCellValue('D' . $r, $address);
        $sheet->setCellValue('E' . $r, $lang);
        $sheet->setCellValue('F' . $r, $outlet);
        $sheet->setCellValue('G' . $r, $m['timestamp']);
        $sheet->setCellValue('H' . $r, $m['registered_by']);
        $sheet->setCellValue('I' . $r, $b['lmp']);
        $sheet->setCellValue('J' . $r, $b['edd']);
        $r++;
    }
}

foreach ($cols as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}
$sheet->getStyle('A1:J' . ($r - 1))->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

$filename = 'mamabe_mama_list_' . date('Y-m-d_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$writer = new Xlsx($book);
$writer->save('php://output');
