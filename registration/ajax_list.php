<?php
/**
 * JSON list of submissions for index.php (admin only): grouped by registration event (one row per PERSON - the
 * mother, then each baby - grouped under the event they were submitted in together), paginated by event.
 * A 'both' registration (register_type 'pink' + 'baby' saved as two linked rows, see api/register.php's batch_id)
 * shows as one event with two people; a standalone pink or baby row is its own one-person event.
 * GET: page, f_type ('' | 1 Mother only | 2 Baby only | 3 Both), f_status (result code or ''),
 *      f_q (matches a typed or AI-read mother/baby name).
 */
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeRecords.php');

const MAMABE_TYPE_MOTHER = 1;   // pink only
const MAMABE_TYPE_BABY   = 2;   // baby only
const MAMABE_TYPE_BOTH   = 3;   // both

// Self-migrating approval columns (see ajax_approve.php) - checked here too so the SELECT below never hits an
// unknown-column error on a server where ajax_approve.php hasn't run yet.
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'voucher_id'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission
        ADD COLUMN approved_at DATETIME NULL,
        ADD COLUMN approved_by INT NULL,
        ADD COLUMN voucher_id INT NULL COMMENT 'mamabe_voucher.id assigned when this person was approved'");
}
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'rejected_at'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission ADD COLUMN rejected_at DATETIME NULL, ADD COLUMN rejected_by INT NULL");
}
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'email'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission ADD COLUMN email VARCHAR(100) NOT NULL DEFAULT '' AFTER phone");
}

$per_page = 30;
MamabeVerifier::loadSettings($conn);
$today    = date('Y-m-d');
$meta     = MamabeVerifier::statusMeta();
$langs    = MamabeRecords::languages();
$sources  = MamabeRecords::sources($conn, true);
$regTypes = MamabeRecords::registerTypes();

$f_type = isset($_GET['f_type']) && in_array($_GET['f_type'], array('1', '2', '3'), true) ? (int)$_GET['f_type'] : 0;

$rowWhere  = array('1=1');
$rowParams = array();
$rowTypes  = '';

$f_status = isset($_GET['f_status']) ? $_GET['f_status'] : '';
if ($f_status !== '' && ctype_digit($f_status) && isset($meta[(int)$f_status])) {
    $rowWhere[]  = 's.status = ?';
    $rowParams[] = (int)$f_status;
    $rowTypes   .= 'i';
}

$f_q = isset($_GET['f_q']) ? trim($_GET['f_q']) : '';
if ($f_q !== '') {
    $like       = '%' . addcslashes($f_q, '%_\\') . '%';
    $rowWhere[] = '(s.mother_name LIKE ? OR s.child_name LIKE ? OR s.extracted_mother LIKE ? OR s.extracted_child LIKE ?)';
    for ($i = 0; $i < 4; $i++) $rowParams[] = $like;
    $rowTypes  .= 'ssss';
}
$rowWhereSql = implode(' AND ', $rowWhere);

// ── Step 1: every registration event's composition (which types it has), unfiltered - needed for the Type filter. ──
$batches = array();   // bkey => array('n_pink'=>, 'n_baby'=>, 'created_at'=>)
$res = $conn->query("SELECT COALESCE(batch_id, id) AS bkey, MAX(created_at) AS created_at,
                             SUM(register_type = 'pink') AS n_pink, SUM(register_type = 'baby') AS n_baby
                      FROM mamabe_submission GROUP BY bkey");
while ($res && ($r = $res->fetch_assoc())) {
    $batches[(int)$r['bkey']] = array(
        'created_at' => $r['created_at'],
        'type'       => ((int)$r['n_pink'] > 0 && (int)$r['n_baby'] > 0) ? MAMABE_TYPE_BOTH
                       : ((int)$r['n_pink'] > 0 ? MAMABE_TYPE_MOTHER : MAMABE_TYPE_BABY),
    );
}
if ($f_type) {
    $batches = array_filter($batches, function ($b) use ($f_type) { return $b['type'] === $f_type; });
}

// ── Step 2: rows (people) matching the Result/Name filters, restricted to the type-filtered events. ──
$stmt = $conn->prepare(
    "SELECT s.id, s.mama_id, s.mama_is_new, COALESCE(s.batch_id, s.id) AS bkey, s.register_type, s.source, o.comp_name AS outlet_name,
            s.mother_name, s.ic, s.phone, s.email, s.address, s.language, s.edd,
            s.child_name, s.baby_mykid, s.baby_dob, s.baby_gender, s.pink_file, s.baby_file,
            s.extracted_child, s.extracted_mother, s.extracted_edd, s.extracted_dob, s.extracted_mykid,
            s.status, s.check_note, s.nas_synced, s.nas_note, s.created_at, s.checked_at,
            s.voucher_id, v.voucher_code, s.rejected_at, s.approved_at,
            (SELECT COUNT(*) FROM mamabe_submission p WHERE p.mama_id = s.mama_id AND p.register_type = s.register_type AND p.id < s.id) AS earlier_same
       FROM mamabe_submission s
       LEFT JOIN outlet o ON o.id = s.preferred_outlet_id
       LEFT JOIN mamabe_voucher v ON v.id = s.voucher_id
      WHERE $rowWhereSql
      ORDER BY s.id"
);
if ($rowParams) $stmt->bind_param($rowTypes, ...$rowParams);
$stmt->execute();
$res = $stmt->get_result();
$byBatch = array();   // bkey => array of raw rows
while ($r = $res->fetch_assoc()) {
    $bkey = (int)$r['bkey'];
    if (!isset($batches[$bkey])) continue;   // filtered out by Type
    $byBatch[$bkey][] = $r;
}
$stmt->close();

// ── Step 3: paginate over the events that still have at least one matching person, newest first. ──
$bkeys = array_keys($byBatch);
usort($bkeys, function ($a, $b) use ($batches) { return strcmp($batches[$b]['created_at'] . sprintf('%010d', $b), $batches[$a]['created_at'] . sprintf('%010d', $a)); });

$total  = count($bkeys);
$pages  = max(1, (int)ceil($total / $per_page));
$page   = min($pages, max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1));
$pageKeys = array_slice($bkeys, ($page - 1) * $per_page, $per_page);

$typeLabels = array(MAMABE_TYPE_MOTHER => 'Mother only', MAMABE_TYPE_BABY => 'Baby only', MAMABE_TYPE_BOTH => 'Both');

$events = array();
foreach ($pageKeys as $bkey) {
    $rows = $byBatch[$bkey];
    // Mother (pink) row first, then baby row(s), matching the form's own step order.
    usort($rows, function ($a, $b) { return $a['register_type'] === $b['register_type'] ? $a['id'] <=> $b['id'] : ($a['register_type'] === 'pink' ? -1 : 1); });

    $people = array();
    foreach ($rows as $r) {
        $m = isset($meta[(int)$r['status']]) ? $meta[(int)$r['status']] : array('Unknown', 'gray');
        $isPink = $r['register_type'] === 'pink';

        $motherOk = $eddOk = $childOk = $motherOnDocOk = $dobOk = null;
        $dobViaMykid = false;
        if ($isPink) {
            $c = MamabeVerifier::motherChecks(array(
                'mother_name' => $r['mother_name'], 'extracted_mother' => $r['extracted_mother'], 'extracted_edd' => $r['extracted_edd'],
            ), $today);
            $motherOk = $c['mother']['ok'];
            $eddOk    = $c['edd']['ok'];
        } else {
            $c = MamabeVerifier::babyChecks(array(
                'child_name' => $r['child_name'], 'extracted_child' => $r['extracted_child'],
                'mother_name' => $r['mother_name'], 'extracted_mother' => $r['extracted_mother'],
                'extracted_dob' => $r['extracted_dob'], 'extracted_mykid' => $r['extracted_mykid'],
            ), $today);
            $childOk       = $c['child']['ok'];
            $motherOnDocOk = $c['mother_on_doc']['ok'];
            $dobOk         = $c['dob']['ok'];
            $dobViaMykid   = !empty($c['dob']['via_mykid']);
        }

        $viaMotherName = !$isPink && $childOk !== true && $motherOnDocOk === true;
        $people[] = array(
            'id'               => (int)$r['id'],
            'who'              => $isPink ? 'Mother' : 'Baby',
            'register_type'    => $r['register_type'],
            'name'             => $isPink ? $r['mother_name'] : $r['child_name'],
            'name_match'       => $isPink ? $motherOk : ($childOk === true || $motherOnDocOk === true ? true : ($childOk === null && $motherOnDocOk === null ? null : false)),
            'via_mother_name'  => $viaMotherName,
            'mother_on_doc_ok' => $motherOnDocOk,
            'has_pink_book'    => !empty($r['pink_file']),
            'has_baby_book'    => !empty($r['baby_file']),
            'extracted_child'  => $r['extracted_child'],
            'extracted_mother' => $r['extracted_mother'],
            'extracted_edd'    => $r['extracted_edd'],
            'extracted_dob'    => $r['extracted_dob'],
            'extracted_mykid'  => $r['extracted_mykid'],
            'edd_ok'           => $eddOk,
            'dob_ok'           => $dobOk,
            'dob_via_mykid'    => $dobViaMykid,
            'edd'              => $r['edd'],
            'baby_mykid'       => $r['baby_mykid'],
            'baby_dob'         => $r['baby_dob'],
            'baby_gender'      => $r['baby_gender'],
            'status'           => (int)$r['status'],
            'status_label'     => $m[0],
            'status_pill'      => $m[1],
            'note'             => $r['check_note'],
            'nas'              => $r['nas_synced'] ? 'done' : ($r['nas_note'] ? $r['nas_note'] : 'waiting'),
            'voucher_code'     => $r['voucher_id'] !== null ? $r['voucher_code'] : null,
            'rejected'         => $r['rejected_at'] !== null,
            // Approved but no voucher assigned - she already has a physical one from the outlet (mamabe_mama.receipt_no
            // for the mama side, mamabe_baby.baby_receipt_no for the baby side), see MamabeApproval.php.
            'approved_no_voucher' => $r['approved_at'] !== null && $r['voucher_id'] === null,
            // Same mama already sent this same kind of registration earlier (mother-only again, baby-only again) -
            // staff-only flag, the mama's own record is never duplicated (matched by IC/phone in MamabeRecords::save()).
            'repeat'           => (int)$r['earlier_same'] > 0,
        );
    }

    $first = $rows[0];
    $events[] = array(
        'bkey'             => $bkey,
        'created_at'       => $batches[$bkey]['created_at'],
        'type_label'       => $typeLabels[$batches[$bkey]['type']],
        'mama_id'          => $first['mama_id'] === null ? null : (int)$first['mama_id'],
        'mama_is_new'      => (bool)$first['mama_is_new'],
        'mother_name'      => $first['mother_name'],
        'ic'               => $first['ic'],
        'phone'            => $first['phone'],
        'email'            => $first['email'],
        'address'          => $first['address'],
        'language'         => isset($langs[(int)$first['language']]) ? $langs[(int)$first['language']] : '',
        'source'           => isset($sources[$first['source']]) ? $sources[$first['source']] : '',
        'outlet'           => $first['outlet_name'] === null ? '' : MamabeRecords::outletName($first['outlet_name']),
        'people'           => $people,
    );
}

echo json_encode(array(
    'detail_url' => MAMABE_MAMA_DETAIL_URL,
    'events'   => $events,
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
    'per_page' => $per_page,
), JSON_UNESCAPED_UNICODE);
