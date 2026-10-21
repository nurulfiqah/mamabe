<meta http-equiv="Content-type" content="text/html; charset=utf-8">

<?php
error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set('Asia/Kuala_Lumpur');

$connect = 1;
include('../common/index_adv.php');

// --- VIP Card Lookup ---
$ic = isset($_GET['ic']) ? trim(mysqli_real_escape_string($conn, $_GET['ic'])) : '';
if ($ic != '') {
    $name = isset($_GET['name']) ? trim(mysqli_real_escape_string($conn, $_GET['name'])) : '';

    $query = "SELECT id, customer_name, phone, ic, c_addr, language
              FROM customer
              WHERE ic = '$ic' AND customer_name = '$name' AND recycle = '0'
              LIMIT 1";

    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $id            = stripslashes((string)$row['id']);
        $phone         = stripslashes((string)$row['phone']);
        $customer_name = stripslashes((string)$row['customer_name']);
        $c_addr        = stripslashes((string)$row['c_addr']);
        $language      = stripslashes((string)$row['language']);

        // Strip newlines and pipes to avoid breaking the delimiter
        $c_addr = str_replace(array("\r", "\n", "|"), array(" ", " ", " "), $c_addr);

        echo "<img src='../common/img/tick.png' title='Ok'>|$ic|$customer_name|$c_addr|$language|$id|$phone";
    }
}
?>
