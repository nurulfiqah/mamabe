<?php
require_once __DIR__ . '/xilnex_config.php';
ini_set('display_errors', '0');
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

// Load existing SKU master for flagging known items (type: 1=mama, 2=baby)
$skus_mama   = array();
$skus_baby   = array();
$prices_mama = array();
$prices_baby = array();
$res_sku = $conn->query("SELECT sku, price, type FROM mamabe_sku_master WHERE recycle=0");
if ($res_sku) {
    while ($row = $res_sku->fetch_assoc()) {
        if ($row['type'] == 1) { $skus_mama[$row['sku']] = true; $prices_mama[$row['sku']] = (float)$row['price']; }
        else { $skus_baby[$row['sku']] = true; $prices_baby[$row['sku']] = (float)$row['price']; }
    }
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
if (empty($response['data']['sale']) || empty($response['data']['sale']['items'])) {
    echo json_encode(array('success' => false, 'error' => 'Invalid ID or transaction not yet synced to Xilnex Cloud'));
    exit;
}

// Return ALL items with flags and price-match result
$allItems = array();
foreach ($response['data']['sale']['items'] as $item) {
    $sku       = $item['itemCode'] ?? '';
    $api_price = (float)($item['unitPrice'] ?? 0);
    $in_mama   = isset($skus_mama[$sku]);
    $in_baby   = isset($skus_baby[$sku]);
    $db_price  = 0;
    $price_ok  = null; // null = not in master, true = match, false = mismatch
    if ($in_mama) {
        $db_price = isset($prices_mama[$sku]) ? $prices_mama[$sku] : 0;
        if ($db_price > 0) { $price_ok = (abs($api_price - $db_price) < 0.01) ? true : false; }
    } elseif ($in_baby) {
        $db_price = isset($prices_baby[$sku]) ? $prices_baby[$sku] : 0;
        if ($db_price > 0) { $price_ok = (abs($api_price - $db_price) < 0.01) ? true : false; }
    }
    $item_qty = 1;
    if (isset($item['qty']))      { $item_qty = (int)$item['qty']; }
    elseif (isset($item['quantity'])) { $item_qty = (int)$item['quantity']; }
    elseif (isset($item['itemQty'])) { $item_qty = (int)$item['itemQty']; }
    if ($item_qty < 1) { $item_qty = 1; }

    $allItems[] = array(
        'sku'      => $sku,
        'name'     => $item['itemName'] ?? '',
        'price'    => $api_price,
        'db_price' => $db_price,
        'price_ok' => $price_ok,
        'in_mama'  => $in_mama,
        'in_baby'  => $in_baby,
        'qty'      => $item_qty
    );
}

echo json_encode(array(
    'success'    => true,
    'receipt_no' => $invoice,
    'items'      => $allItems
));
exit;
