<?php
require_once __DIR__ . '/xilnex_config.php';
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');

ob_clean();
header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

$invoice = isset($_REQUEST['receipt_no']) ? trim($_REQUEST['receipt_no']) : '';
if (!$invoice) {
    echo json_encode(array('success' => false, 'error' => 'No receipt number provided'));
    exit;
}

function CallAPI($method, $url, $data = false)
{
    $curl = curl_init();
    switch ($method) {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);
            if ($data) curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_PUT, 1);
            break;
        default:
            if ($data) $url = sprintf("%s?%s", $url, http_build_query($data));
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "appid: " . XILNEX_APP_ID,
        "token: " . XILNEX_TOKEN,
        "auth: 5"
    ));
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_ENCODING, "");
    curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($curl, CURLOPT_CAINFO, "C:/xampp/htdocs/odb/xilnex_api/cacert.pem");
    $result = curl_exec($curl);
    $err    = curl_error($curl);
    curl_close($curl);
    if ($err) { return json_encode(array('_curl_error' => $err)); }
    return $result;
}

$get_data = CallAPI('GET', "https://api.xilnex.com/logic/v2/sales/" . urlencode($invoice));
$response = json_decode($get_data, true);

if (isset($response['_curl_error'])) {
    echo json_encode(array('success' => false, 'error' => 'cURL Error: ' . $response['_curl_error']));
    exit;
}
if (!$response || !isset($response['ok']) || !$response['ok'] || $response['data'] === null) {
    echo json_encode(array('success' => false, 'error' => 'API Error: ' . (isset($response['status']) ? $response['status'] : 'Unknown error')));
    exit;
}
if (empty($response['data']['sale'])) {
    echo json_encode(array('success' => false, 'error' => 'Invalid ID or transaction not yet synced to Xilnex Cloud'));
    exit;
}

echo json_encode(array('success' => true, 'sale' => $response['data']['sale']));
exit;
