<?php
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');

header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(array('found' => false));
    exit;
}

$ic = isset($_GET['ic']) ? preg_replace('/[^A-Za-z0-9]/', '', trim($_GET['ic'])) : '';
if ($ic === '') {
    echo json_encode(array('found' => false));
    exit;
}

$stmt = $conn->prepare("SELECT * FROM mamabe_mama WHERE ic=? AND recycle=0 LIMIT 1");
$stmt->bind_param("s", $ic);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(array('found' => false));
    exit;
}

$is_inactive = strtotime($row['timestamp']) <= strtotime('-2 years');

echo json_encode(array(
    'found'  => true,
    'status' => $is_inactive ? 'inactive' : 'active',
    'mama'   => array(
        'id'          => (int)$row['id'],
        'mama_name'   => isset($row['mama_name'])   ? $row['mama_name']   : '',
        'phone'       => isset($row['phone'])       ? $row['phone']       : '',
        'email'       => isset($row['email'])       ? $row['email']       : '',
        'address'     => isset($row['address'])     ? $row['address']     : '',
        'language'    => isset($row['language'])    ? (int)$row['language'] : 0,
        'outlet_id'   => isset($row['outlet_id'])   ? (int)$row['outlet_id'] : 0,
        'race'        => isset($row['race'])        ? $row['race']        : '',
        'diagnosis'   => isset($row['diagnosis'])   ? $row['diagnosis']   : '',
        'allergic'    => isset($row['allergic'])    ? $row['allergic']    : '',
        'gender'      => isset($row['gender'])      ? $row['gender']      : '',
        'birth_date'  => isset($row['birth_date'])  ? $row['birth_date']  : '',
        'nationality' => isset($row['nationality']) ? $row['nationality'] : '',
        'timestamp'   => $row['timestamp'],
    ),
));
