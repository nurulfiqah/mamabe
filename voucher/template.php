<?php
/**
 * Downloads a blank .xlsx template for bulk voucher stock-in: "Voucher Code" + "Type" (Mama/Baby dropdown), 5000
 * blank rows ready to fill. Upload the filled file back on index.php's "Upload Excel" form.
 */
// lock_adv.php echoes odb's shared top navbar HTML as soon as the session check passes - a file download has no page
// to put it in, so buffer and discard it (same ob_start()/ob_end_clean() trick as mamabe_mama_export.php). Without
// this, that HTML lands in the .xlsx stream ahead of the real file bytes and Excel refuses to open it.
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
include('../../common/index_adv.php');
ob_end_clean();
require_once('../../common/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book  = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Vouchers');

$sheet->setCellValue('A1', 'Voucher Code');
$sheet->setCellValue('B1', 'Type');
$sheet->getStyle('A1:B1')->getFont()->setBold(true);
$sheet->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F0FA');
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(14);

// Type column: a dropdown restricted to Mama / Baby, so staff can't mistype it - matches MAMABE_VOUCHER_TYPES in
// index.php (1 = Mama, 2 = Baby); the upload handler reads the text "Mama"/"Baby" back, not a number.
$lastRow = 5001;
for ($r = 2; $r <= $lastRow; $r++) {
    $dv = $sheet->getCell('B' . $r)->getDataValidation();
    $dv->setType(DataValidation::TYPE_LIST);
    $dv->setErrorStyle(DataValidation::STYLE_STOP);
    $dv->setAllowBlank(true);
    $dv->setShowDropDown(true);
    $dv->setShowErrorMessage(true);
    $dv->setErrorTitle('Invalid type');
    $dv->setError('Please choose Mama or Baby from the dropdown.');
    $dv->setFormula1('"Mama,Baby"');
}
// Voucher Code column kept as plain text so long numeric-looking codes aren't mangled.
$sheet->getStyle('A2:A' . $lastRow)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

$filename = 'mamabe_voucher_template.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$writer = new Xlsx($book);
$writer->save('php://output');
