<?php
require_once __DIR__ . '/xilnex_config.php';
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 30);

$raw = isset($_POST['id']) ? trim($_POST['id']) : '';
// Keep letters, digits, hyphens — don't strip letters like customer/ajax.php does
$id  = preg_replace('/[^A-Za-z0-9\-\._]/', '', $raw);

if (!$id) {
    echo json_encode(array('data' => array('clients' => array())));
    exit;
}

$curl = curl_init();
curl_setopt($curl, CURLOPT_HTTPHEADER, array(
    "Content-Type: application/json",
    "appid: " . XILNEX_APP_ID,
    "token: " . XILNEX_TOKEN,
    "auth: 5"
));
curl_setopt($curl, CURLOPT_URL, "https://api.xilnex.com/logic/v2/clients/query/?clientcode=" . urlencode($id));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_ENCODING, "");
curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
curl_setopt($curl, CURLOPT_TIMEOUT, 60);
curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($curl, CURLOPT_CAINFO, "C:/xampp/htdocs/odb/xilnex_api/cacert.pem");
$result = curl_exec($curl);
$err    = curl_error($curl);
curl_close($curl);

if ($err) {
    echo json_encode(array('data' => array('clients' => array()), '_error' => $err));
    exit;
}

echo $result;
