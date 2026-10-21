<?php
/**
 * Insert Voucher - staff loads a batch of pre-printed voucher codes (paste or Excel upload) as unassigned stock
 * into the master voucher table (mamabe_voucher: id, voucher_code, type, baby_id, insert_date, recycle; type 1 =
 * Mama, 2 = Baby). baby_id is ready for linking a specific mamabe_baby row to a Baby-type voucher, but there's no
 * picker for it yet - it stays NULL until that's added. A listing/redeem/assign page can follow once this is confirmed.
 */
// lock_adv.php echoes odb's shared top navbar HTML as soon as the session check passes, using hardcoded "../../odb/"
// asset paths - correct one level under odb/ (odb/mamabe/*.php) but broken one level deeper here (odb/mamabe/voucher/),
// so its icons show as broken images. Buffer and rewrite them to absolute, same fix as registration/header.php.
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
echo str_replace('../../odb/', '/odb/', ob_get_clean());

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

// Master voucher table. Same convention as the rest of the MamaBe module (mamabe_mama_add.php etc.): self-migrating
// table created on first use, not a separate sql/ dump.
$conn->query("CREATE TABLE IF NOT EXISTS mamabe_voucher (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_code VARCHAR(20) NOT NULL,
    type TINYINT UNSIGNED NOT NULL COMMENT '1 = Mama, 2 = Baby',
    baby_id INT NULL COMMENT 'mamabe_baby.id - set only when type = 2 (Baby)',
    insert_date DATETIME NOT NULL,
    recycle TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY idx_voucher_code (voucher_code),
    KEY idx_baby (baby_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Back-link on mamabe_baby: which voucher (mamabe_voucher.id) was issued for the mama side of this pregnancy/baby
// row, and which for the baby side - a mama can have several mamabe_baby rows (twins, several pregnancies), so this
// lives per row, not per mama. Same self-migrating pattern as mamabe_price_mismatch.php's columns on this table.
$_c = $conn->query("SHOW COLUMNS FROM mamabe_baby LIKE 'mama_voucher_id'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_baby
        ADD COLUMN mama_voucher_id INT NULL COMMENT 'mamabe_voucher.id - the Mama-type voucher issued for this pregnancy/baby row',
        ADD COLUMN baby_voucher_id INT NULL COMMENT 'mamabe_voucher.id - the Baby-type voucher issued for this baby'");
}

const MAMABE_TYPE_MAMA = 1;
const MAMABE_TYPE_BABY = 2;
const MAMABE_VOUCHER_TYPES = array(
    MAMABE_TYPE_MAMA => 'Mama',
    MAMABE_TYPE_BABY => 'Baby',
);

/**
 * Shared bulk stock-in: $rows is a list of array('code' => string, 'type' => int|null). Drops codes over 20 chars
 * or with no/invalid type (those specific rows are just skipped). Duplicates are strict, all-or-nothing: if even
 * ONE code is repeated within the batch itself, or already exists in mamabe_voucher (any recycle state - the
 * column has a UNIQUE key regardless of whether that voucher was later assigned), NOTHING is inserted at all -
 * the whole batch is rejected so staff can fix the file/paste and resubmit, rather than silently losing track of
 * which codes made it in.
 * Returns array('unique', 'inserted', 'dup_in_file', 'dup_in_db', 'too_long', 'bad_type', 'rejected_all').
 */
function mamabe_bulk_insert_vouchers(mysqli $conn, array $rows) {
    $seen = array(); $tooLong = array(); $badType = array(); $dupInFile = array();
    foreach ($rows as $row) {
        $c = trim((string)$row['code']);
        if ($c === '') continue;
        if (mb_strlen($c, 'UTF-8') > 20) { $tooLong[] = $c; continue; }
        if (!isset(MAMABE_VOUCHER_TYPES[$row['type']])) { $badType[] = $c; continue; }
        $key = mb_strtoupper($c, 'UTF-8');
        if (isset($seen[$key])) { $dupInFile[] = $c; continue; }
        $seen[$key] = array('code' => $c, 'type' => (int)$row['type']);
    }
    $codes = array_values($seen);

    // Already in the table, in ANY recycle state - voucher_code is unique regardless of whether it was assigned.
    $dupInDb = array();
    if ($codes) {
        $chunks = array_chunk($codes, 500);   // keep each IN() query a sane size
        foreach ($chunks as $chunk) {
            $chunkCodes   = array_column($chunk, 'code');
            $placeholders = implode(',', array_fill(0, count($chunkCodes), '?'));
            $stmt = $conn->prepare("SELECT voucher_code FROM mamabe_voucher WHERE voucher_code IN ($placeholders)");
            $stmt->bind_param(str_repeat('s', count($chunkCodes)), ...$chunkCodes);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing = array();
            while ($r = $res->fetch_assoc()) $existing[mb_strtoupper($r['voucher_code'], 'UTF-8')] = true;
            $stmt->close();
            foreach ($chunk as $row) {
                if (isset($existing[mb_strtoupper($row['code'], 'UTF-8')])) $dupInDb[] = $row['code'];
            }
        }
    }

    if ($dupInFile || $dupInDb) {
        return array('unique' => count($codes), 'inserted' => 0, 'dup_in_file' => $dupInFile, 'dup_in_db' => $dupInDb,
                     'too_long' => $tooLong, 'bad_type' => $badType, 'rejected_all' => true);
    }

    $inserted = 0;
    if ($codes) {
        $now = date('Y-m-d H:i:s');
        $null = null;
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO mamabe_voucher (voucher_code, type, baby_id, insert_date, recycle) VALUES (?, ?, ?, ?, 0)");
        $stmt->bind_param('siis', $code, $type, $null, $now);
        foreach ($codes as $row) {
            $code = $row['code'];
            $type = $row['type'];
            if ($stmt->execute()) $inserted++;
        }
        $stmt->close();
        $conn->commit();
    }

    return array('unique' => count($codes), 'inserted' => $inserted, 'dup_in_file' => array(), 'dup_in_db' => array(),
                 'too_long' => $tooLong, 'bad_type' => $badType, 'rejected_all' => false);
}

/** Builds the status message for a bulk-insert report, whichever path (paste/Excel) produced it. */
function mamabe_bulk_report_msg(array $report, $typeLabel) {
    if ($report['rejected_all']) {
        $dupCount = count($report['dup_in_file']) + count($report['dup_in_db']);
        return "$dupCount duplicate code(s) found - nothing was inserted. Fix the file/paste and try again.";
    }
    return "Inserted {$report['inserted']} of {$report['unique']} unique $typeLabel voucher code(s).";
}

$msg = '';
$msg_type = 'success';
$bulk_report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'excel') {
    // Bulk stock-in (Excel): the filled template.php file - column A = code, column B = "Mama"/"Baby" per row.
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $msg = 'Please choose a filled template file to upload.';
        $msg_type = 'danger';
    } elseif ($_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'The upload failed. Please try again.';
        $msg_type = 'danger';
    } else {
        try {
            require_once('../../common/vendor/autoload.php');
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($_FILES['excel_file']['tmp_name']);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($_FILES['excel_file']['tmp_name'])->getActiveSheet();
            $typeByLabel = array('mama' => MAMABE_TYPE_MAMA, 'baby' => MAMABE_TYPE_BABY);

            $rows = array();
            foreach ($sheet->getRowIterator(2) as $sheetRow) {   // row 1 is the header
                $code = trim((string)$sheet->getCell('A' . $sheetRow->getRowIndex())->getValue());
                $typeText = trim((string)$sheet->getCell('B' . $sheetRow->getRowIndex())->getValue());
                if ($code === '' && $typeText === '') continue;   // blank template row
                $rows[] = array('code' => $code, 'type' => $typeByLabel[mb_strtolower($typeText, 'UTF-8')] ?? null);
            }

            if (!$rows) {
                $msg = 'The file has no voucher rows below the header.';
                $msg_type = 'danger';
            } else {
                $report = mamabe_bulk_insert_vouchers($conn, $rows);
                $bulk_report = $report + array('type' => 'mixed (from file)');
                $msg = mamabe_bulk_report_msg($report, 'mixed (from file)');
                $msg_type = $report['inserted'] > 0 ? 'success' : 'danger';
            }
        } catch (Throwable $e) {
            // Show the real reader error - this is a staff-only internal tool, and a guess ("make sure it's the
            // template") isn't enough to diagnose a parse failure on a real Excel-edited file.
            $msg = 'Could not read that file: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

// ── Bulk Delete by Insert Date: for an "oops, inserted 2000 wrong codes" moment. Only ever deletes UNASSIGNED
// (recycle = 0) stock - a voucher already given to a mama/baby (recycle = 1) is never touched by this, no matter
// what date range is picked, since mamabe_baby/mamabe_submission may already reference its id. A real hard DELETE,
// not a recycle flag - recycle on this table means "assigned", not "soft-deleted", so flagging a mistaken row
// recycle=1 would make it look like real assigned stock instead of removing it. ──
$del_date_from = isset($_GET['del_from']) ? trim($_GET['del_from']) : (isset($_POST['del_from']) ? trim($_POST['del_from']) : '');
$del_date_to   = isset($_GET['del_to'])   ? trim($_GET['del_to'])   : (isset($_POST['del_to'])   ? trim($_POST['del_to'])   : '');
$del_type      = isset($_GET['del_type']) ? (int)$_GET['del_type'] : (isset($_POST['del_type']) ? (int)$_POST['del_type'] : 0);
$del_valid_date = function ($d) { return $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); };

$delete_preview = null;
$delete_result  = null;

if ($del_valid_date($del_date_from) && $del_valid_date($del_date_to) && $del_date_from <= $del_date_to) {
    $delWhere  = array('DATE(insert_date) BETWEEN ? AND ?');
    $delParams = array($del_date_from, $del_date_to);
    $delTypes  = 'ss';
    if ($del_type) { $delWhere[] = 'type = ?'; $delParams[] = $del_type; $delTypes .= 'i'; }
    $delWhereSql = implode(' AND ', $delWhere);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'bulk_delete') {
        if (empty($_POST['confirm_delete'])) {
            $msg = 'Please tick the confirm checkbox before deleting.';
            $msg_type = 'danger';
        } else {
            $stmt = $conn->prepare("DELETE FROM mamabe_voucher WHERE recycle = 0 AND $delWhereSql");
            $stmt->bind_param($delTypes, ...$delParams);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            $delete_result = $deleted;
            $msg = "Deleted $deleted unassigned voucher(s) inserted between $del_date_from and $del_date_to.";
            $msg_type = $deleted > 0 ? 'success' : 'danger';
        }
    }

    // Always re-show a fresh preview for the current filter (after a delete too, so the count reflects what's left).
    $stmt = $conn->prepare("SELECT COUNT(*) FROM mamabe_voucher WHERE recycle = 0 AND $delWhereSql");
    $stmt->bind_param($delTypes, ...$delParams);
    $stmt->execute();
    $stmt->bind_result($delUnassignedCount);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM mamabe_voucher WHERE recycle = 1 AND $delWhereSql");
    $stmt->bind_param($delTypes, ...$delParams);
    $stmt->execute();
    $stmt->bind_result($delAssignedCount);
    $stmt->fetch();
    $stmt->close();

    $sampleStmt = $conn->prepare("SELECT voucher_code FROM mamabe_voucher WHERE recycle = 0 AND $delWhereSql ORDER BY id LIMIT 20");
    $sampleStmt->bind_param($delTypes, ...$delParams);
    $sampleStmt->execute();
    $sampleRes = $sampleStmt->get_result();
    $delSample = array();
    while ($row = $sampleRes->fetch_assoc()) $delSample[] = $row['voucher_code'];
    $sampleStmt->close();

    $delete_preview = array('unassigned' => $delUnassignedCount, 'assigned' => $delAssignedCount, 'sample' => $delSample);
}

// ── Voucher list: two tabs (Not Assigned / Assigned), paginated, filterable by type + code search. ──
// "Assigned" = recycle = 1 (see registration/ajax_approve.php: approving stamps recycle = 1 on the voucher and
// mama_voucher_id/baby_voucher_id on the mamabe_baby row it was given to). We look that mamabe_baby row back up
// here just to show WHO it went to - a voucher is referenced by at most one mamabe_baby row (the unique keys on
// mamabe_baby.mama_voucher_id / baby_voucher_id guarantee that), so this join never duplicates a row.
$tab = isset($_GET['tab']) && $_GET['tab'] === 'assigned' ? 'assigned' : 'unassigned';
$f_type = isset($_GET['f_type']) && isset(MAMABE_VOUCHER_TYPES[(int)$_GET['f_type']]) ? (int)$_GET['f_type'] : 0;
$f_q    = isset($_GET['f_q']) ? trim($_GET['f_q']) : '';
$per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], array(25, 50, 100, 150, 200, 500), true) ? (int)$_GET['per_page'] : 25;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);

$where  = array($tab === 'assigned' ? 'v.recycle = 1' : 'v.recycle = 0');
$params = array();
$types  = '';
if ($f_type) { $where[] = 'v.type = ?'; $params[] = $f_type; $types .= 'i'; }
if ($f_q !== '') { $where[] = 'v.voucher_code LIKE ?'; $params[] = '%' . $f_q . '%'; $types .= 's'; }
$whereSql = implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) FROM mamabe_voucher v WHERE $whereSql");
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$countStmt->bind_result($total);
$countStmt->fetch();
$countStmt->close();

$pages  = max(1, (int)ceil($total / $per_page));
$page   = min($page, $pages);
$offset = ($page - 1) * $per_page;

if ($tab === 'assigned') {
    $sql = "SELECT v.id, v.voucher_code, v.type, v.insert_date, COALESCE(bm.mama_id, bb.mama_id) AS mama_id, m.mama_name, m.ic
              FROM mamabe_voucher v
              LEFT JOIN mamabe_baby bm ON bm.mama_voucher_id = v.id
              LEFT JOIN mamabe_baby bb ON bb.baby_voucher_id = v.id
              LEFT JOIN mamabe_mama m ON m.id = COALESCE(bm.mama_id, bb.mama_id)
             WHERE $whereSql
             ORDER BY v.id DESC LIMIT $per_page OFFSET $offset";
} else {
    $sql = "SELECT v.id, v.voucher_code, v.type, v.insert_date
              FROM mamabe_voucher v
             WHERE $whereSql
             ORDER BY v.id DESC LIMIT $per_page OFFSET $offset";
}
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result();

/** Keeps tab/filters while only changing the given param (e.g. page, or tab itself). */
function mamabe_voucher_url($overrides) {
    $q = array_merge(array(
        'tab' => $_GET['tab'] ?? 'unassigned', 'f_type' => $_GET['f_type'] ?? '', 'f_q' => $_GET['f_q'] ?? '',
        'per_page' => $_GET['per_page'] ?? 30, 'page' => $_GET['page'] ?? 1,
    ), $overrides);
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Insert Voucher</title>
<link rel="stylesheet" href="../../common/css/layout.css">
<link rel="stylesheet" href="../../common/css/page.css">
<link rel="stylesheet" href="../../common/css/alpro-core.css">
<link rel="stylesheet" href="../../common/css/alpro-components.css">
<link rel="stylesheet" href="../../common/css/alpro-utils.css">
<style>
.mamabe-muted { color: #888; font-size: 0.9em; }

/* "Show N entries" / "Showing X to Y of Z entries" + pager row, DataTables-style */
.mamabe-list-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.mamabe-show-entries { display: flex; align-items: center; gap: 6px; font-size: 0.9em; color: #333; }
.mamabe-show-entries select { padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em; }
.mamabe-list-toolbar-right { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

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
  <h1 class="headerH1"><img src="../images/mom.png"> MamaBe – Insert Voucher</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../mamabe_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo htmlspecialchars($msg_type); ?>" style="margin:8px 0"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<fieldset>
<legend><b>Bulk Insert Vouchers</b> <span style="font-weight:normal;color:#888;font-size:0.85em;">load a batch of pre-printed codes as unassigned stock</span></legend>

<?php if ($bulk_report): ?>
<div class="alpro-alert alpro-<?php echo htmlspecialchars($msg_type); ?>" style="margin-bottom:10px;">
    <?php if ($bulk_report['rejected_all']): ?>
        <b>Nothing was inserted</b> - this batch has duplicate code(s). Even one duplicate blocks the whole batch, so fix it and resubmit.
        <?php if ($bulk_report['dup_in_file']): ?>
            <div>Repeated within this batch: <?php echo count($bulk_report['dup_in_file']); ?> code(s)<?php echo count($bulk_report['dup_in_file']) <= 20 ? ' - ' . htmlspecialchars(implode(', ', $bulk_report['dup_in_file'])) : ''; ?></div>
        <?php endif; ?>
        <?php if ($bulk_report['dup_in_db']): ?>
            <div>Already in the system: <?php echo count($bulk_report['dup_in_db']); ?> code(s)<?php echo count($bulk_report['dup_in_db']) <= 20 ? ' - ' . htmlspecialchars(implode(', ', $bulk_report['dup_in_db'])) : ''; ?></div>
        <?php endif; ?>
    <?php else: ?>
        <b><?php echo (int)$bulk_report['inserted']; ?></b> of <b><?php echo (int)$bulk_report['unique']; ?></b> unique code(s) inserted as <b><?php echo htmlspecialchars($bulk_report['type']); ?></b> vouchers.
    <?php endif; ?>
    <?php if ($bulk_report['too_long']): ?>
        <div>Skipped (over 20 characters): <?php echo count($bulk_report['too_long']); ?> code(s)</div>
    <?php endif; ?>
    <?php if (!empty($bulk_report['bad_type'])): ?>
        <div>Skipped (missing/invalid type): <?php echo count($bulk_report['bad_type']); ?> code(s)</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<p class="mamabe-muted" style="font-size:0.85em;margin:0 0 10px;">Column A = voucher code, Column B = Mama/Baby (dropdown) per row - each row can be its own type.</p>
<form method="post" id="excelForm" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;">
    <input type="hidden" name="mode" value="excel">
    <a href="template.php" class="alpro-btn alpro-btn-grey" style="flex-shrink:0;">&#11015; Download Template</a>
    <input type="file" class="alpro-input" id="excel_file" name="excel_file" accept=".xlsx,.xls" required style="flex:1;">
    <button type="submit" class="alpro-btn alpro-btn-blue" style="flex-shrink:0;">Upload &amp; Insert</button>
</form>
</fieldset>

<fieldset>
<legend><b>Bulk Delete by Insert Date</b> <span style="font-weight:normal;color:#888;font-size:0.85em;">fix a batch that was inserted wrong - only ever deletes stock not yet assigned to anyone</span></legend>

<form method="get" style="display:flex;gap:10px;align-items:flex-end;">
    <div class="alpro-field" style="flex:1;">
        <label for="del_from">From date</label>
        <input type="date" class="alpro-input" id="del_from" name="del_from" value="<?php echo htmlspecialchars($del_date_from); ?>" required>
    </div>
    <div class="alpro-field" style="flex:1;">
        <label for="del_to">To date</label>
        <input type="date" class="alpro-input" id="del_to" name="del_to" value="<?php echo htmlspecialchars($del_date_to); ?>" required>
    </div>
    <div class="alpro-field" style="flex:1;">
        <label for="del_type">Type</label>
        <select class="alpro-input" id="del_type" name="del_type">
            <option value="">All</option>
            <?php foreach (MAMABE_VOUCHER_TYPES as $code => $label): ?>
            <option value="<?php echo (int)$code; ?>" <?php echo $del_type === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="alpro-btn alpro-btn-grey" style="flex-shrink:0;">Check</button>
</form>

<?php if ($delete_preview): ?>
<div class="alpro-alert <?php echo $delete_preview['unassigned'] > 0 ? 'alpro-danger' : 'alpro-success'; ?>" style="margin-top:12px;">
    <b><?php echo (int)$delete_preview['unassigned']; ?></b> unassigned voucher(s) match <?php echo htmlspecialchars($del_date_from); ?> to <?php echo htmlspecialchars($del_date_to); ?><?php echo $del_type ? ' (' . htmlspecialchars(MAMABE_VOUCHER_TYPES[$del_type]) . ' only)' : ''; ?>.
    <?php if ($delete_preview['assigned'] > 0): ?>
        <div><?php echo (int)$delete_preview['assigned']; ?> more match this date/type but are already assigned to a mama/baby - those are never deleted, whatever happens below.</div>
    <?php endif; ?>
    <?php if ($delete_preview['sample']): ?>
        <div style="margin-top:6px;font-size:0.85em;">Sample: <?php echo htmlspecialchars(implode(', ', $delete_preview['sample'])); ?><?php echo $delete_preview['unassigned'] > count($delete_preview['sample']) ? '…' : ''; ?></div>
    <?php endif; ?>

    <?php if ($delete_preview['unassigned'] > 0): ?>
    <form method="post" style="margin-top:12px;" onsubmit="return confirm('Permanently delete <?php echo (int)$delete_preview['unassigned']; ?> voucher(s)? This cannot be undone.');">
        <input type="hidden" name="mode" value="bulk_delete">
        <input type="hidden" name="del_from" value="<?php echo htmlspecialchars($del_date_from); ?>">
        <input type="hidden" name="del_to" value="<?php echo htmlspecialchars($del_date_to); ?>">
        <input type="hidden" name="del_type" value="<?php echo htmlspecialchars((string)$del_type); ?>">
        <label style="display:flex;align-items:center;gap:6px;font-size:0.9em;">
            <input type="checkbox" name="confirm_delete" value="1" required>
            I confirm I want to permanently delete these <?php echo (int)$delete_preview['unassigned']; ?> voucher(s). This cannot be undone.
        </label>
        <button type="submit" class="alpro-btn alpro-btn-danger" style="margin-top:8px;background:#c82333;color:#fff;border-color:#c82333;">Delete</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
</fieldset>

<fieldset>
<legend><b>Voucher List</b></legend>

<div style="display:flex;gap:6px;margin-bottom:12px;">
    <a href="<?php echo htmlspecialchars(mamabe_voucher_url(array('tab' => 'unassigned', 'page' => 1))); ?>"
       class="alpro-btn <?php echo $tab === 'unassigned' ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">Not Assigned</a>
    <a href="<?php echo htmlspecialchars(mamabe_voucher_url(array('tab' => 'assigned', 'page' => 1))); ?>"
       class="alpro-btn <?php echo $tab === 'assigned' ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">Assigned</a>
</div>

<form method="get" class="mamabe-search-bar" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
    <select name="f_type" class="alpro-input" style="width:auto;">
        <option value="">– Type: All –</option>
        <?php foreach (MAMABE_VOUCHER_TYPES as $code => $label): ?>
        <option value="<?php echo (int)$code; ?>" <?php echo $f_type === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="f_q" class="alpro-input" style="width:auto;" placeholder="Search voucher code..." value="<?php echo htmlspecialchars($f_q); ?>">
    <button type="submit" class="alpro-btn alpro-btn-blue">Search</button>
    <a href="<?php echo htmlspecialchars(mamabe_voucher_url(array('f_type' => '', 'f_q' => '', 'page' => 1))); ?>" class="alpro-btn alpro-btn-grey">Reset</a>
</form>

<?php $from = $total > 0 ? ($page - 1) * $per_page + 1 : 0; $to = min($page * $per_page, $total); ?>

<div style="overflow-x:auto;">
<table class="myTable alpro-table" width="100%">
    <thead>
        <tr>
            <th>Code</th><th>Type</th><th>Inserted</th>
            <?php if ($tab === 'assigned'): ?><th>Name</th><th>IC</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php if ($list && $list->num_rows > 0): while ($r = $list->fetch_assoc()): ?>
        <tr>
            <td><b><?php echo htmlspecialchars($r['voucher_code']); ?></b></td>
            <td><?php echo htmlspecialchars(MAMABE_VOUCHER_TYPES[(int)$r['type']] ?? $r['type']); ?></td>
            <td><?php echo htmlspecialchars($r['insert_date']); ?></td>
            <?php if ($tab === 'assigned'): ?>
            <td><?php echo !empty($r['mama_id']) ? htmlspecialchars($r['mama_name']) : '<span class="mamabe-muted">–</span>'; ?></td>
            <td><?php echo !empty($r['mama_id']) ? htmlspecialchars($r['ic']) : '<span class="mamabe-muted">–</span>'; ?></td>
            <?php endif; ?>
        </tr>
    <?php endwhile; else: ?>
        <tr><td colspan="<?php echo $tab === 'assigned' ? 5 : 3; ?>" style="text-align:center;color:#999;">No vouchers found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<div class="mamabe-list-toolbar" style="margin-top:12px;margin-bottom:0;">
    <label class="mamabe-show-entries">
        Show
        <select onchange="location.href = this.value;">
            <?php foreach (array(25, 50, 100, 150, 200, 500) as $n): ?>
            <option value="<?php echo htmlspecialchars(mamabe_voucher_url(array('per_page' => $n, 'page' => 1))); ?>" <?php echo $per_page === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
        </select>
        entries
    </label>

    <div class="mamabe-list-toolbar-right">
        <span class="mamabe-muted" style="font-size:0.9em;">
            <?php if ($total > 0): ?>
            Showing <?php echo $from; ?> to <?php echo $to; ?> of <?php echo (int)$total; ?> entries
            <?php else: ?>
            No entries
            <?php endif; ?>
        </span>
        <?php if ($pages > 1): ?>
        <div class="mamabe-dt-pagination">
            <?php $prevDisabled = $page <= 1; ?>
            <a class="mamabe-dt-page <?php echo $prevDisabled ? 'disabled' : ''; ?>"
               href="<?php echo $prevDisabled ? '#' : htmlspecialchars(mamabe_voucher_url(array('page' => $page - 1))); ?>">Previous</a>

            <?php
            $start = max(1, $page - 2);
            $end   = min($pages, $page + 2);
            if ($start > 1) { echo '<a class="mamabe-dt-page" href="' . htmlspecialchars(mamabe_voucher_url(array('page' => 1))) . '">1</a>'; if ($start > 2) echo '<span class="mamabe-dt-ellipsis">&hellip;</span>'; }
            for ($p = $start; $p <= $end; $p++) {
                echo $p === $page
                    ? '<span class="mamabe-dt-page current">' . $p . '</span>'
                    : '<a class="mamabe-dt-page" href="' . htmlspecialchars(mamabe_voucher_url(array('page' => $p))) . '">' . $p . '</a>';
            }
            if ($end < $pages) { if ($end < $pages - 1) echo '<span class="mamabe-dt-ellipsis">&hellip;</span>'; echo '<a class="mamabe-dt-page" href="' . htmlspecialchars(mamabe_voucher_url(array('page' => $pages))) . '">' . $pages . '</a>'; }
            ?>

            <?php $nextDisabled = $page >= $pages; ?>
            <a class="mamabe-dt-page <?php echo $nextDisabled ? 'disabled' : ''; ?>"
               href="<?php echo $nextDisabled ? '#' : htmlspecialchars(mamabe_voucher_url(array('page' => $page + 1))); ?>">Next</a>
        </div>
        <?php endif; ?>
    </div>
</div>
</fieldset>

</body>
</html>
