<?php
$connect=1;
include ('../common/index_adv.php');
header('Content-Type: text/html; charset=UTF-8');
$q=trim(mysqli_real_escape_string($conn,$_GET["q"] ?? ''));

if (!$q) return;

$sql = "select DISTINCT customer_name as customer_name, ic from customer where (customer_name LIKE '%$q%' or c_id LIKE '%$q%' or ic LIKE '%$q%') and recycle=0";
$rsd = mysqli_query($conn,$sql);
while($rs = mysqli_fetch_array($rsd)) {
	$ic = $rs['ic'];
	$cname = $rs['customer_name'];
	echo "$ic : $cname\n";
}
?>
