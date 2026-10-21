<?php
/**
 * Mama search for the Insert Voucher page (same outlet-scoping as mamabe_mama_list.php /
 * mamabe_mama_export.php): staff see only their own outlet's mamas (plus ALPRO BABY outlets), admins see everyone.
 * GET: q (name / IC / phone, min 2 chars). Response JSON: [{id, name, ic, phone, outlet, babies:[{id,label}]}].
 */
require_once('../../lock_adv.php');
$connect = 1;
include('../../common/index_adv.php');
header('Content-Type: application/json; charset=UTF-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(array());
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode(array());
    exit;
}

// Same outlet-scoping as the rest of this module: admins (mamab_autho 1/3) see every mama, other staff only their
// own outlet(s) (outlet_autho, comma-separated) plus the ALPRO BABY outlets (shared across all outlets).
$_is_admin      = (isset($mamab_autho) && ($mamab_autho == '1' || $mamab_autho == '3'));
$_staff_outlets = array();
if (!$_is_admin && !empty($outlet_autho)) {
    $_staff_outlets = array_filter(array_map('intval', explode(',', $outlet_autho)));
}
$_outlet_in = !empty($_staff_outlets) ? implode(',', $_staff_outlets) : '';

$where  = 'm.recycle = 0 AND (m.mama_name LIKE ? OR m.ic LIKE ? OR m.phone LIKE ?)';
$params = array();
$types  = '';
$like   = '%' . $q . '%';
$params[] = $like; $params[] = $like; $params[] = $like;
$types   .= 'sss';

if (!$_is_admin) {
    if ($_outlet_in) {
        $where .= " AND (m.outlet_id IN ($_outlet_in) OR m.outlet_id IN (SELECT id FROM outlet WHERE recycle=0 AND comp_name LIKE '%ALPRO BABY%'))";
    } else {
        $where .= ' AND 1=0';   // no outlet access at all -> no results
    }
}

$stmt = $conn->prepare(
    "SELECT m.id, m.mama_name, m.ic, m.phone, o.comp_name AS outlet_name
       FROM mamabe_mama m
       LEFT JOIN outlet o ON o.id = m.outlet_id
      WHERE $where
      ORDER BY m.mama_name
      LIMIT 20"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$mamas = array();
$ids   = array();
while ($r = $res->fetch_assoc()) {
    $mamas[] = array(
        'id'     => (int)$r['id'],
        'name'   => $r['mama_name'],
        'ic'     => $r['ic'],
        'phone'  => $r['phone'],
        'outlet' => $r['outlet_name'],
        'babies' => array(),
    );
    $ids[] = (int)$r['id'];
}
$stmt->close();

if ($ids) {
    $byMama = array();
    $in = implode(',', $ids);
    $br = $conn->query("SELECT id, mama_id, baby_name, dob, is_delivered FROM mamabe_baby
                         WHERE recycle = 0 AND mama_id IN ($in) ORDER BY timestamp ASC");
    while ($br && ($b = $br->fetch_assoc())) {
        $label = $b['baby_name'] !== '' ? $b['baby_name'] : ((int)$b['is_delivered'] === 1 ? 'Baby (unnamed)' : 'Pregnancy (unborn)');
        if ($b['dob']) $label .= ' - b. ' . $b['dob'];
        $byMama[(int)$b['mama_id']][] = array('id' => (int)$b['id'], 'label' => $label);
    }
    foreach ($mamas as &$m) {
        $m['babies'] = isset($byMama[$m['id']]) ? $byMama[$m['id']] : array();
    }
    unset($m);
}

echo json_encode($mamas, JSON_UNESCAPED_UNICODE);
