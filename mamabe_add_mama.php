<?php
require_once('../lock_adv.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

// A mama becomes "Inactive" once 2 years have passed since her registration
// timestamp (recycle=0 still). Registering with an IC that matches an
// Inactive mama reactivates her existing record (resets the timestamp,
// logs the event) instead of blocking as a duplicate. Active matches still
// block as before.
function mamabe_check_ic_reactivation($conn, $ic, $staff_name) {
    $conn->query("CREATE TABLE IF NOT EXISTS mamabe_reactivation_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mama_id INT NOT NULL,
        staff_name VARCHAR(150) NOT NULL DEFAULT '',
        old_timestamp DATETIME NOT NULL,
        new_timestamp DATETIME NOT NULL,
        reactivated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chk = $conn->prepare("SELECT id, timestamp FROM mamabe_mama WHERE ic=? AND recycle=0 LIMIT 1");
    $chk->bind_param("s", $ic);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        return null;
    }
    $chk->bind_result($existing_id, $existing_ts);
    $chk->fetch();
    $chk->close();

    $is_inactive = strtotime($existing_ts) <= strtotime('-2 years');
    if (!$is_inactive) {
        return array('status' => 'active', 'id' => $existing_id);
    }

    $new_ts = date('Y-m-d H:i:s');
    $upd = $conn->prepare("UPDATE mamabe_mama SET timestamp=? WHERE id=?");
    $upd->bind_param("si", $new_ts, $existing_id);
    $upd->execute();
    $upd->close();

    $log = $conn->prepare("INSERT INTO mamabe_reactivation_log (mama_id, staff_name, old_timestamp, new_timestamp, reactivated_at) VALUES (?, ?, ?, ?, ?)");
    $log->bind_param("issss", $existing_id, $staff_name, $existing_ts, $new_ts, $new_ts);
    $log->execute();
    $log->close();

    return array('status' => 'reactivated', 'id' => $existing_id);
}

$lang_map = [1 => 'English', 2 => 'Bahasa Malaysia', 3 => 'Chinese'];

$outlets = $conn->query("SELECT id, comp_name FROM outlet WHERE recycle=0 ORDER BY comp_name");

$msg      = '';
$msg_type = '';

if (isset($_POST['save_mama'])) {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $mama_name   = trim($_POST['mama_name'] ?? '');
    $ic          = trim($_POST['ic'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $language    = (int)($_POST['language'] ?? 0);
    $outlet_id   = (int)($_POST['outlet_id'] ?? 0);
    $timestamp   = date('Y-m-d H:i:s');

    if (!$customer_id || !$mama_name || !$ic || !$address || !$outlet_id) {
        $msg      = 'Please fill in all required fields and select a customer.';
        $msg_type = 'danger';
    } else {
        $react = mamabe_check_ic_reactivation($conn, $ic, isset($nama_staff) ? $nama_staff : '');
        if ($react && $react['status'] === 'active') {
            $msg      = 'This mama (IC: ' . htmlspecialchars($ic) . ') is already registered.';
            $msg_type = 'warn';
        } elseif ($react && $react['status'] === 'reactivated') {
            header('Location: mamabe_mama_detail.php?id=' . $react['id'] . '&reactivated=1');
            exit;
        } else {
            $_empty_text = '';
            $stmt = $conn->prepare("
                INSERT INTO mamabe_mama (customer_id, mama_name, ic, phone, address, language, outlet_id, diagnosis, receipt_price_mismatch, recycle, timestamp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
            ");
            if (!$stmt) {
                $msg      = 'Database error: ' . $conn->error;
                $msg_type = 'danger';
            } else {
                $stmt->bind_param("issssiisss", $customer_id, $mama_name, $ic, $phone, $address, $language, $outlet_id, $_empty_text, $_empty_text, $timestamp);
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    $stmt->close();
                    header('Location: mamabe_mama_detail.php?id=' . $new_id . '&added=1');
                    exit;
                } else {
                    $msg      = 'Database error. Please try again.';
                    $msg_type = 'danger';
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Add Mama</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<link rel="stylesheet" href="../common/css/jquery.autocomplete.css">
<style>
.mama-layout  { display:flex; gap:20px; flex-wrap:wrap; }
.mama-left    { flex:1 1 0; min-width:280px; }
.mama-right   { flex:1 1 0; min-width:280px; }
.mama-section-title { font-weight:bold; font-size:0.88em; color:#2a6496;
                      border-bottom:2px solid #d0dfe8; padding-bottom:5px; margin-bottom:14px; }
.mama-field   { margin-bottom:14px; }
.mama-field label { display:block; font-weight:600; font-size:0.88em; margin-bottom:4px; color:#444; }
.mama-row     { display:flex; gap:12px; flex-wrap:wrap; }
.mama-row .mama-field { flex:1 1 140px; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mom.png"> MamaBe – Add Mama</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<div class="alpro-box">

  <?php if ($msg): ?>
  <div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
  <?php endif; ?>

  <div style="background:#e8f4fd;border:1px solid #b8daff;border-radius:5px;padding:10px 14px;margin-bottom:18px;font-size:0.9em;color:#2a6496;">
    &#8505; This form searches existing Alpro customers.
    If the customer is <strong>not in the system</strong>, use <a href="mamabe_mama_add.php"><strong>Register New Customer</strong></a> instead.
  </div>

  <form method="post">

    <input type="hidden" name="customer_id" id="customer_id" value="">

    <div class="mama-layout">

      <!-- ── LEFT: Personal Info ── -->
      <div class="mama-left">
        <div class="mama-section-title">Mama Information</div>

        <div class="mama-field">
          <label>Mama Name <span style="color:red">*</span> <span id="vipCard" style="font-size:0.85em"></span></label>
          <input class="alpro-input" type="text" name="mama_name" id="mama_name" style="width:100%"
                 value="<?php echo htmlspecialchars(isset($_POST['mama_name']) ? $_POST['mama_name'] : ''); ?>"
                 placeholder="Type name, IC, or member ID to search..."
                 autocomplete="off" required>
        </div>

        <div class="mama-row">
          <div class="mama-field">
            <label>IC Number <span style="color:red">*</span></label>
            <input class="alpro-input alpro-mono" type="text" name="ic" id="ic" style="width:100%;background:#f5f5f5"
                   value="<?php echo htmlspecialchars(isset($_POST['ic']) ? $_POST['ic'] : ''); ?>" readonly required>
          </div>
          <div class="mama-field">
            <label>Phone Number</label>
            <input class="alpro-input" type="text" name="phone" id="phone" style="width:100%"
                   value="<?php echo htmlspecialchars(isset($_POST['phone']) ? $_POST['phone'] : ''); ?>">
          </div>
        </div>

        <div class="mama-field">
          <label>Address <span style="color:red">*</span></label>
          <textarea class="alpro-input" name="address" id="address" rows="4" required readonly
                    style="width:100%;resize:vertical;background:#f5f5f5"><?php echo htmlspecialchars(isset($_POST['address']) ? $_POST['address'] : ''); ?></textarea>
        </div>
      </div>

      <!-- ── RIGHT: Settings ── -->
      <div class="mama-right">
        <div class="mama-section-title">Registration Settings</div>

        <div class="mama-field">
          <label>Language <span style="color:red">*</span></label>
          <select class="alpro-input" name="language" id="language" style="width:100%">
            <?php foreach ($lang_map as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo ((isset($_POST['language']) ? $_POST['language'] : 1) == $k) ? 'selected' : ''; ?>>
              <?php echo $v; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mama-field">
          <label>Outlet <span style="color:red">*</span></label>
          <select class="alpro-input" name="outlet_id" id="outlet_id_select" style="width:100%">
            <option value="">-- Select Outlet --</option>
            <?php if ($outlets): while ($o = $outlets->fetch_assoc()): ?>
            <option value="<?php echo (int)$o['id']; ?>"
              <?php echo ((isset($_POST['outlet_id']) ? $_POST['outlet_id'] : '') == $o['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($o['comp_name']); ?>
            </option>
            <?php endwhile; endif; ?>
          </select>
        </div>

      </div>

    </div>

    <div class="alpro-actions" style="margin-top:20px;border-top:1px solid #e0e0e0;padding-top:16px">
      <input class="alpro-btn alpro-btn-blue" type="submit" name="save_mama" value="Save Mama"
             onclick="return confirm('Confirm to save this mama record?');">
      <a class="alpro-btn alpro-btn-grey" href="mamabe_mama_list.php">Back</a>
    </div>

  </form>
</div>

<script src="../common/js/jquery-1.5.1.js"></script>
<script src="../common/js/jquery.autocomplete.js"></script>
<script>
function getXMLHTTP() {
    var xmlhttp = false;
    try { xmlhttp = new XMLHttpRequest(); }
    catch(e) {
        try { xmlhttp = new ActiveXObject("Microsoft.XMLHTTP"); }
        catch(e) {
            try { xmlhttp = new ActiveXObject("Msxml2.XMLHTTP"); }
            catch(e1) { xmlhttp = false; }
        }
    }
    return xmlhttp;
}

$().ready(function() {
    $("#mama_name").autocomplete("mamabe_search_customer.php", {
        width: 500,
        matchContains: true,
        selectFirst: false,
        delay: 10
    });

    $("#mama_name").result(function(event, data, formatted) {
        var selected = document.getElementById("mama_name").value;
        var info = selected.split(" : ");
        var strURL = "mamabe_ajax.php?ic=" + encodeURIComponent(info[0]) + "&name=" + encodeURIComponent(info[1]);

        var xmlhttp = getXMLHTTP();
        if (xmlhttp) {
            xmlhttp.onreadystatechange = function() {
                if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
                    var parts = xmlhttp.responseText.split('|');
                    // parts: tick | ic | name | addr | language | id | phone
                    document.getElementById("vipCard").innerHTML  = parts[0] + ' <a href="javascript:void(0);" onclick="resetCustomer();" style="font-size:0.8em;color:#c82333;">[Change]</a>';
                    document.getElementById("ic").value           = parts[1];
                    document.getElementById("mama_name").value    = parts[2];
                    document.getElementById("address").value      = parts[3];
                    if (parts[4]) {
                        document.getElementById("language").value = parts[4];
                    }
                    document.getElementById("customer_id").value  = parts[5];
                    if (parts[6]) {
                        document.getElementById("phone").value    = parts[6];
                    }
                    // Lock fields after selection
                    document.getElementById("mama_name").readOnly = true;
                    document.getElementById("mama_name").style.background = '#f5f5f5';
                }
            };
            xmlhttp.open("GET", strURL, true);
            xmlhttp.send();
        }
    });

function resetCustomer() {
    document.getElementById("mama_name").readOnly = false;
    document.getElementById("mama_name").style.background = '';
    document.getElementById("mama_name").value   = '';
    document.getElementById("ic").value          = '';
    document.getElementById("phone").value       = '';
    document.getElementById("address").value     = '';
    document.getElementById("customer_id").value = '';
    document.getElementById("vipCard").innerHTML = '';
    document.getElementById("mama_name").focus();
}
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    new TomSelect("#outlet_id_select", {
        create: false,
        sortField: { field: "text", direction: "asc" },
        maxOptions: null,
        plugins: ['dropdown_input']
    });
});
</script>
</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
