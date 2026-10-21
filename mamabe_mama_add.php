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

// Auto-add extra columns if not yet present
$_c = $conn->query("SHOW COLUMNS FROM mamabe_mama LIKE 'email'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_mama
        ADD COLUMN email       VARCHAR(100) NOT NULL DEFAULT '',
        ADD COLUMN gender      VARCHAR(10)  NOT NULL DEFAULT '',
        ADD COLUMN birth_date  DATE         NULL DEFAULT NULL,
        ADD COLUMN race        VARCHAR(100) NOT NULL DEFAULT '',
        ADD COLUMN diagnosis   TEXT         NOT NULL,
        ADD COLUMN allergic    VARCHAR(255) NOT NULL DEFAULT ''");
}
$_c2 = $conn->query("SHOW COLUMNS FROM mamabe_mama LIKE 'nationality'");
if ($_c2 && $_c2->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_mama ADD COLUMN nationality VARCHAR(100) NOT NULL DEFAULT '' AFTER race");
}

$lang_map = array(1 => 'English (BI)', 2 => 'Bahasa Malaysia (BM)', 3 => 'Chinese (BC)');
$_is_admin = (isset($mamab_autho) && ($mamab_autho == '1' || $mamab_autho == '3'));
if (!$_is_admin && !empty($outlet_autho)) {
    $_oid_list = implode(',', array_filter(array_map('intval', explode(',', $outlet_autho))));
    $_outlet_q = "SELECT id, code, comp_name FROM outlet WHERE recycle=0 AND id IN ($_oid_list) ORDER BY comp_name";
} else {
    $_outlet_q = "SELECT id, code, comp_name FROM outlet WHERE recycle=0 ORDER BY comp_name";
}
$outlets  = $conn->query($_outlet_q);
$outlets2 = $conn->query($_outlet_q);

// Load race list – fallback to hardcoded if table missing
$races = array();
try {
    $rr = $conn->query("SELECT id, ethnic FROM mydna_ethnicities ORDER BY ethnic");
} catch (mysqli_sql_exception $e) {
    $rr = false;
}
if ($rr && $rr->num_rows > 0) {
    while ($r = $rr->fetch_assoc()) { $races[] = array('ethnic' => $r['ethnic']); }
} else {
    $races = array(
        array('ethnic' => 'Malay'),
        array('ethnic' => 'Chinese'),
        array('ethnic' => 'Indian'),
        array('ethnic' => 'Bumiputera Sabah'),
        array('ethnic' => 'Bumiputera Sarawak'),
        array('ethnic' => 'Orang Asli'),
        array('ethnic' => 'Sikh'),
        array('ethnic' => 'Others'),
    );
}

$msg      = '';
$msg_type = '';

function _raceOptions($races, $selected) {
    $html = '<option value="">-- Select Race --</option>';
    foreach ($races as $r) {
        $sel = ($selected === $r['ethnic']) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($r['ethnic']) . '" ' . $sel . '>' . htmlspecialchars($r['ethnic']) . '</option>';
    }
    return $html;
}

function _outletOptions($outlets, $selected) {
    $html = '<option value="">-- Select Outlet --</option>';
    if ($outlets) {
        while ($o = $outlets->fetch_assoc()) {
            $sel = ($selected == $o['id']) ? 'selected' : '';
            $html .= '<option value="' . (int)$o['id'] . '" ' . $sel . '>' . htmlspecialchars($o['code'] . ' - ' . $o['comp_name']) . '</option>';
        }
    }
    return $html;
}

// ── Handle Tab A (New IC) ──
if (isset($_POST['save_mama_a'])) {
    $mama_name  = trim($_POST['mama_name_a'] ?? '');
    $ic1        = preg_replace('/\D/','',$_POST['ic1'] ?? '');
    $ic2        = preg_replace('/\D/','',$_POST['ic2'] ?? '');
    $ic3        = preg_replace('/\D/','',$_POST['ic3'] ?? '');
    $ic         = $ic1 . $ic2 . $ic3;
    $phone      = preg_replace('/\D/','',trim($_POST['phone_a'] ?? ''));
    $language   = (int)($_POST['language_a'] ?? 0);
    $outlet_id  = (int)($_POST['outlet_id_a'] ?? 0);
    $email      = trim($_POST['email_a'] ?? '');
    $address    = trim($_POST['address_a'] ?? '');
    $race       = trim($_POST['race_a'] ?? '');
    $diagnosis  = trim($_POST['diagnosis_a'] ?? '');
    $allergic   = trim($_POST['allergic_a'] ?? '');
    $c_id_a     = trim(isset($_POST['c_id_a']) ? $_POST['c_id_a'] : '');
    $gender     = 'Female';
    $birth_date = NULL;
    $timestamp  = date('Y-m-d H:i:s');

    // Derive birth date from IC
    if (strlen($ic1) === 6) {
        $yy = substr($ic1,0,2);
        $mm = substr($ic1,2,2);
        $dd = substr($ic1,4,2);
        $yr = ((int)$yy <= (int)date('y')) ? '20'.$yy : '19'.$yy;
        $birth_date = $yr.'-'.$mm.'-'.$dd;
    }

    if (!$mama_name) {
        $msg = 'Please fill in the Full Name.'; $msg_type = 'danger';
    } elseif (strlen($ic) < 12) {
        $msg = 'IC must be 12 digits. Entered: ' . strlen($ic) . ' digits (' . htmlspecialchars($ic1.'-'.$ic2.'-'.$ic3) . ').'; $msg_type = 'danger';
    } elseif (!$outlet_id) {
        $msg = 'Please select an Outlet.'; $msg_type = 'danger';
    } elseif ((int)substr($ic1,2,2) > 12 || (int)substr($ic1,4,2) > 31) {
        $msg = 'Invalid IC number – check the month/day digits.'; $msg_type = 'danger';
    } else {
        // Check duplicate in mamabe_mama
        $react = mamabe_check_ic_reactivation($conn, $ic, isset($nama_staff) ? $nama_staff : '');
        if ($react && $react['status'] === 'active') {
            $msg = 'This mama (IC: '.htmlspecialchars($ic).') is already registered.'; $msg_type = 'warn';
        } elseif ($react && $react['status'] === 'reactivated') {
            header('Location: mamabe_mama_detail.php?id='.$react['id'].'&reactivated=1');
            exit;
        } else {
            // Auto-detect gender from last digit of ic3
            $gender_digit = (int)substr($ic3, -1);
            $gender = ($gender_digit % 2 === 0) ? 'Female' : 'Male';

            // Check duplicate in customer table
            $customer_id = 0;
            $chk2 = $conn->prepare("SELECT id FROM customer WHERE ic=? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param("s",$ic); $chk2->execute(); $chk2->store_result();
                if ($chk2->num_rows > 0) {
                    $chk2->bind_result($customer_id); $chk2->fetch();
                }
                $chk2->close();
            }

            // Insert into customer table if not already there
            if (!$customer_id) {
                $op = isset($id_user) ? (int)$id_user : 0;
                $conn->query("INSERT INTO customer (c_id,date,customer_name,ic,gender,birth_date,allergic,diagnosis,language,phone,email,c_addr,operator,race) VALUES ('".mysqli_real_escape_string($conn,$c_id_a)."',NOW(),'".mysqli_real_escape_string($conn,$mama_name)."','".mysqli_real_escape_string($conn,$ic)."','".mysqli_real_escape_string($conn,$gender)."','".mysqli_real_escape_string($conn,(string)$birth_date)."','".mysqli_real_escape_string($conn,$allergic)."','".mysqli_real_escape_string($conn,$diagnosis)."','".mysqli_real_escape_string($conn,$language)."','".mysqli_real_escape_string($conn,$phone)."','".mysqli_real_escape_string($conn,$email)."','".mysqli_real_escape_string($conn,$address)."','$op','".mysqli_real_escape_string($conn,$race)."')");
                $customer_id = $conn->insert_id;
            } elseif ($c_id_a) {
                $conn->query("UPDATE customer SET c_id='".mysqli_real_escape_string($conn,$c_id_a)."' WHERE id=$customer_id AND (c_id='' OR c_id IS NULL)");
            }

            // Insert into mamabe_mama
            $reg_by = isset($nama_staff) ? $nama_staff : '';
            $_empty_mismatch = '';
            $stmt = $conn->prepare("INSERT INTO mamabe_mama (customer_id,mama_name,ic,phone,address,language,outlet_id,email,gender,birth_date,race,diagnosis,allergic,receipt_price_mismatch,recycle,timestamp,registered_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)");
            $stmt->bind_param("issssiisssssssss", $customer_id,$mama_name,$ic,$phone,$address,$language,$outlet_id,$email,$gender,$birth_date,$race,$diagnosis,$allergic,$_empty_mismatch,$timestamp,$reg_by);
            if ($stmt->execute()) { $new_id=$stmt->insert_id; $stmt->close(); header('Location: mamabe_mama_detail.php?id='.$new_id.'&added=1'); exit; }
            $msg = 'Database error: '.$conn->error; $msg_type = 'danger';
            $stmt->close();
        }
    }
}

// ── Handle Tab B (Old IC / Passport) ──
if (isset($_POST['save_mama_b'])) {
    $mama_name  = trim($_POST['mama_name_b'] ?? '');
    $ic         = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['ic_b'] ?? ''));
    $birth_date = trim($_POST['birth_date_b'] ?? '');
    $gender     = trim($_POST['gender_b'] ?? '');
    $phone      = preg_replace('/\D/','',trim($_POST['phone_b'] ?? ''));
    $language   = (int)($_POST['language_b'] ?? 0);
    $outlet_id  = (int)($_POST['outlet_id_b'] ?? 0);
    $email      = trim($_POST['email_b'] ?? '');
    $address    = trim($_POST['address_b'] ?? '');
    $race       = trim($_POST['race_b'] ?? '');
    $diagnosis  = trim($_POST['diagnosis_b'] ?? '');
    $allergic   = trim($_POST['allergic_b'] ?? '');
    $nationality_sel = trim($_POST['nationality_b'] ?? '');
    $nationality = ($nationality_sel === 'OTHERS') ? trim($_POST['nationality_other_b'] ?? '') : $nationality_sel;
    $c_id_b     = trim(isset($_POST['c_id_b']) ? $_POST['c_id_b'] : '');
    $birth_val  = ($birth_date !== '') ? $birth_date : NULL;
    $timestamp  = date('Y-m-d H:i:s');

    if (!$mama_name) {
        $msg = 'Please fill in the Full Name.'; $msg_type = 'danger';
    } elseif (!$ic) {
        $msg = 'Please fill in the IC / Passport number.'; $msg_type = 'danger';
    } elseif (!$outlet_id) {
        $msg = 'Please select an Outlet.'; $msg_type = 'danger';
    } else {
        $react = mamabe_check_ic_reactivation($conn, $ic, isset($nama_staff) ? $nama_staff : '');
        if ($react && $react['status'] === 'active') {
            $msg = 'This mama (IC: '.htmlspecialchars($ic).') is already registered.'; $msg_type = 'warn';
        } elseif ($react && $react['status'] === 'reactivated') {
            header('Location: mamabe_mama_detail.php?id='.$react['id'].'&reactivated=1');
            exit;
        } else {
            // Check / insert into customer table
            $customer_id = 0;
            $chk2 = $conn->prepare("SELECT id FROM customer WHERE ic=? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param("s",$ic); $chk2->execute(); $chk2->store_result();
                if ($chk2->num_rows > 0) { $chk2->bind_result($customer_id); $chk2->fetch(); }
                $chk2->close();
            }
            if (!$customer_id) {
                $op = isset($id_user) ? (int)$id_user : 0;
                $bv = $birth_val ? $birth_val : '';
                $conn->query("INSERT INTO customer (c_id,date,customer_name,ic,gender,birth_date,allergic,diagnosis,language,phone,email,c_addr,operator,race) VALUES ('".mysqli_real_escape_string($conn,$c_id_b)."',NOW(),'".mysqli_real_escape_string($conn,$mama_name)."','".mysqli_real_escape_string($conn,$ic)."','".mysqli_real_escape_string($conn,$gender)."','".mysqli_real_escape_string($conn,$bv)."','".mysqli_real_escape_string($conn,$allergic)."','".mysqli_real_escape_string($conn,$diagnosis)."','".mysqli_real_escape_string($conn,$language)."','".mysqli_real_escape_string($conn,$phone)."','".mysqli_real_escape_string($conn,$email)."','".mysqli_real_escape_string($conn,$address)."','$op','".mysqli_real_escape_string($conn,$race)."')");
                $customer_id = $conn->insert_id;
            } elseif ($c_id_b) {
                $conn->query("UPDATE customer SET c_id='".mysqli_real_escape_string($conn,$c_id_b)."' WHERE id=$customer_id AND (c_id='' OR c_id IS NULL)");
            }

            // Insert into mamabe_mama
            $reg_by = isset($nama_staff) ? $nama_staff : '';
            $_empty_mismatch = '';
            if ($birth_val !== NULL) {
                $stmt = $conn->prepare("INSERT INTO mamabe_mama (customer_id,mama_name,ic,phone,address,language,outlet_id,email,gender,birth_date,race,nationality,diagnosis,allergic,receipt_price_mismatch,recycle,timestamp,registered_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)");
                $stmt->bind_param("issssiissssssssss", $customer_id,$mama_name,$ic,$phone,$address,$language,$outlet_id,$email,$gender,$birth_val,$race,$nationality,$diagnosis,$allergic,$_empty_mismatch,$timestamp,$reg_by);
            } else {
                $stmt = $conn->prepare("INSERT INTO mamabe_mama (customer_id,mama_name,ic,phone,address,language,outlet_id,email,gender,race,nationality,diagnosis,allergic,receipt_price_mismatch,recycle,timestamp,registered_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)");
                $stmt->bind_param("issssiisssssssss", $customer_id,$mama_name,$ic,$phone,$address,$language,$outlet_id,$email,$gender,$race,$nationality,$diagnosis,$allergic,$_empty_mismatch,$timestamp,$reg_by);
            }
            if ($stmt->execute()) { $new_id=$stmt->insert_id; $stmt->close(); header('Location: mamabe_mama_detail.php?id='.$new_id.'&added=1'); exit; }
            $msg = 'Database error: '.$conn->error; $msg_type = 'danger';
            $stmt->close();
        }
    }
}

function _v($k) { return htmlspecialchars(isset($_POST[$k]) ? $_POST[$k] : ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Register Mama</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<link rel="stylesheet" type="text/css" href="../common/css/jquery-ui.css">
<link rel="stylesheet" href="../common/css/jquery.autocomplete.css">
<script src="../common/js/jquery-1.5.1.js"></script>
<script src="../common/js/jquery.autocomplete.js"></script>
<script src="../common/js/tab.js"></script>
<script src="../common/js/jquery.ui.core.js"></script>
<script src="../common/js/jquery.ui.datepicker.js"></script>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
$(function() {
    $("#birth_date_b").datepicker({ dateFormat: 'yy-mm-dd', changeMonth: true, changeYear: true });
});
function toggleNationality(sel, otherId) {
    var otherInput = document.getElementById(otherId);
    if (!otherInput) return;
    if (sel.value === 'OTHERS') {
        otherInput.style.display = '';
    } else {
        otherInput.style.display = 'none';
        otherInput.value = '';
    }
}

// ── Inactive-IC reactivation: live check + pre-fill + lock ──
var mamabeNationalityFixedOptions = ['MALAYSIA','SINGAPORE','INDONESIA','BRUNEI','PHILIPPINES','THAILAND'];

function mamabeSetOutletField(suffix, outletId, locked) {
    var sel = document.getElementById('outlet_id_' + suffix + '_select');
    if (!sel) return;
    if (sel.tomselect) {
        if (outletId) { sel.tomselect.setValue(String(outletId)); }
        if (locked) { sel.tomselect.disable(); } else { sel.tomselect.enable(); }
    } else {
        if (outletId) { sel.value = outletId; }
        sel.disabled = !!locked;
    }
}

function mamabeApplyReactivateFields(suffix, mama) {
    function setField(name, value, lockAs) {
        var el = document.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = value || '';
        if (lockAs === 'readonly') { el.readOnly = true; }
        else if (lockAs === 'disabled') { el.disabled = true; }
        el.style.background = '#f5f5f5';
    }

    setField('mama_name_' + suffix, mama.mama_name, 'readonly');
    setField('phone_' + suffix, mama.phone, 'readonly');
    setField('email_' + suffix, mama.email, 'readonly');
    setField('address_' + suffix, mama.address, 'readonly');
    setField('race_' + suffix, mama.race, 'disabled');
    setField('diagnosis_' + suffix, mama.diagnosis, 'readonly');
    setField('allergic_' + suffix, mama.allergic, 'readonly');
    setField('language_' + suffix, mama.language, 'disabled');
    mamabeSetOutletField(suffix, mama.outlet_id, true);

    if (suffix === 'b') {
        var bd = document.getElementById('birth_date_b');
        if (bd) { bd.value = mama.birth_date || ''; bd.readOnly = true; bd.style.background = '#f5f5f5'; }

        document.querySelectorAll('[name="gender_b"]').forEach(function(r) {
            r.checked = (r.value === mama.gender);
            r.disabled = true;
        });

        var natSel = document.getElementById('uc_nationality_sel');
        var natOther = document.getElementById('nationality_other_uc');
        if (natSel) {
            if (mamabeNationalityFixedOptions.indexOf(mama.nationality) !== -1) {
                natSel.value = mama.nationality;
                if (natOther) { natOther.style.display = 'none'; natOther.value = ''; }
            } else if (mama.nationality) {
                natSel.value = 'OTHERS';
                if (natOther) {
                    natOther.style.display = '';
                    natOther.value = mama.nationality;
                    natOther.readOnly = true;
                    natOther.style.background = '#f5f5f5';
                }
            }
            natSel.disabled = true;
        }
    }

    var btn = document.querySelector('[name="save_mama_' + suffix + '"]');
    if (btn) { btn.value = 'Activate Mama'; }

    var since = document.getElementById('reactivate_since_' + suffix);
    if (since) { since.textContent = mama.timestamp; }
    var note = document.getElementById('reactivate_note_' + suffix);
    if (note) { note.style.display = ''; }
    var activeNote = document.getElementById('active_note_' + suffix);
    if (activeNote) { activeNote.style.display = 'none'; }
}

function mamabeResetReactivateFields(suffix) {
    ['mama_name_', 'phone_', 'email_', 'address_', 'race_', 'diagnosis_', 'allergic_', 'language_'].forEach(function(prefix) {
        var el = document.querySelector('[name="' + prefix + suffix + '"]');
        if (!el) return;
        el.readOnly = false;
        el.disabled = false;
        el.style.background = '';
    });
    mamabeSetOutletField(suffix, null, false);

    if (suffix === 'b') {
        var bd = document.getElementById('birth_date_b');
        if (bd) { bd.readOnly = false; bd.style.background = ''; }
        document.querySelectorAll('[name="gender_b"]').forEach(function(r) { r.disabled = false; });
        var natSel = document.getElementById('uc_nationality_sel');
        if (natSel) { natSel.disabled = false; }
        var natOther = document.getElementById('nationality_other_uc');
        if (natOther) { natOther.readOnly = false; natOther.style.background = ''; }
    }

    var btn = document.querySelector('[name="save_mama_' + suffix + '"]');
    if (btn) { btn.value = 'Save Mama'; }
    var note = document.getElementById('reactivate_note_' + suffix);
    if (note) { note.style.display = 'none'; }
    var activeNote = document.getElementById('active_note_' + suffix);
    if (activeNote) { activeNote.style.display = 'none'; }
}

var mamabeIcCheckTimer = null;
function mamabeCheckIc(ic, suffix) {
    clearTimeout(mamabeIcCheckTimer);
    if (!ic) { mamabeResetReactivateFields(suffix); return; }
    mamabeIcCheckTimer = setTimeout(function() {
        fetch('mamabe_check_ic_ajax.php?ic=' + encodeURIComponent(ic))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.found) { mamabeResetReactivateFields(suffix); return; }
                if (data.status === 'inactive') {
                    mamabeApplyReactivateFields(suffix, data.mama);
                } else {
                    mamabeResetReactivateFields(suffix);
                    var activeNote = document.getElementById('active_note_' + suffix);
                    if (activeNote) { activeNote.style.display = ''; }
                }
            })
            .catch(function() {});
    }, 400);
}

function mamabeCheckIcA() {
    var ic1 = document.getElementById('ic1').value.trim();
    var ic2 = document.getElementById('ic2').value.trim();
    var ic3 = document.getElementById('ic3').value.trim();
    var ic = ic1 + ic2 + ic3;
    if (ic.length === 12) { mamabeCheckIc(ic, 'a'); }
    else { mamabeResetReactivateFields('a'); }
}

document.addEventListener('DOMContentLoaded', function() {
    ['ic1', 'ic2', 'ic3'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', mamabeCheckIcA);
            el.addEventListener('blur', mamabeCheckIcA);
        }
    });
    var icB = document.getElementById('ic_b');
    if (icB) {
        icB.addEventListener('blur', function() { mamabeCheckIc(icB.value.trim(), 'b'); });
    }
});
</script>
<style>
.new-badge { display:inline-block; background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7;
             border-radius:4px; font-size:0.82em; font-weight:bold; padding:2px 10px; margin-left:8px; vertical-align:middle; }
.form-table td { padding:6px 4px; vertical-align:top; }
.form-table td:first-child { width:180px; text-align:right; padding-right:10px; font-weight:600; font-size:0.9em; color:#444; white-space:nowrap; }
.form-table input[type=text], .form-table input[type=email],
.form-table select, .form-table textarea { width:100%; padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.9em; box-sizing:border-box; }
.form-table textarea { resize:vertical; }
.form-table input[type=text],
.form-table input[type=email],
.form-table select {
    height: 42px;   /* adjust this value (e.g. 40px, 45px) */
}
.ic-wrap { display:flex; align-items:center; gap:4px; }
.ic-wrap input { font-family:monospace; text-align:center; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mom.png"> MamaBe – Register Mama </h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>" style="margin:8px 0"><?php echo $msg; ?></div>
<?php endif; ?>



<fieldset>
<div class="wrapper">
  <ul class="navigationTabs">
    <li><a href="#(a) New IC:" rel="(a) New IC:">(a) New IC :</a></li>
    <li><a href="#(b) Old IC / Passport:" rel="(b) Old IC / Passport:">(b) Old IC / Passport :</a></li>
  </ul>
</div>

<div class="tabsContent">

<!-- ══════════ TAB A – NEW IC ══════════ -->
<div class="tab">
<form method="post" onsubmit="return validateFormA()">
<input type="hidden" name="c_id_a" id="c_id_a" value="<?php echo _v('c_id_a'); ?>">
<div id="reactivate_note_a" style="display:none; margin-bottom:10px; padding:10px 14px; background:#fff3e0; border:1px solid #e0c090; border-radius:5px; font-size:0.88em; color:#8a5a00;">
  &#8635; This IC belongs to an <strong>existing customer</strong> (Inactive — last registered <span id="reactivate_since_a"></span>). Her details are shown below. Submitting will <strong>reactivate</strong> her existing account, not create a new one.
</div>
<div id="active_note_a" style="display:none; margin-bottom:10px; padding:10px 14px; background:#fff3f3; border:1px solid #ef9a9a; border-radius:5px; font-size:0.88em; color:#c62828;">
  &#9888; This IC is already registered and active. You cannot register her again.
</div>
<table class="form-table" width="100%" cellspacing="2" cellpadding="0">
  <tr>
    <td>Alpro VIP Member</td>
    <td>
      <div style="display:flex;align-items:center;gap:6px;">
        <input type="text" id="vip_lookup_a" placeholder="Enter member code" style="width:180px;flex:none;">
        <button type="button" id="vip_search_a" class="alpro-btn alpro-btn-grey" style="width:auto;padding:5px 12px;">
          <img src="../common/img/search.png" style="width:14px;vertical-align:middle"> Search
        </button>
        <span id="vip_status_a" style="font-size:0.85em;color:#888;"></span>
      </div>
    </td>
  </tr>
  <tr>
    <td>Full Name as per IC <font color="red">*</font></td>
    <td>
      <input type="text" name="mama_name_a" id="mama_name_a_input"
             value="<?php echo _v('mama_name_a'); ?>" maxlength="100" required
             placeholder="Type name or IC to search existing customer..." autocomplete="off">
    </td>
  </tr>
  <tr>
    <td>I/C <font color="red">*</font></td>
    <td>
      <div class="ic-wrap">
        <input type="text" name="ic1" id="ic1" value="<?php echo _v('ic1'); ?>" maxlength="6" style="width:70px" required placeholder="XXXXXX">
        <span>–</span>
        <input type="text" name="ic2" id="ic2" value="<?php echo _v('ic2'); ?>" maxlength="2" style="width:40px" required placeholder="XX">
        <span>–</span>
        <input type="text" name="ic3" id="ic3" value="<?php echo _v('ic3'); ?>" maxlength="4" style="width:55px" required placeholder="XXXX">
      </div>
    </td>
  </tr>
  <tr>
    <td>Phone <font color="red">*</font></td>
    <td><input type="text" name="phone_a" id="phone_a" value="<?php echo _v('phone_a'); ?>" maxlength="15" placeholder="e.g. 0123456789" required></td>
  </tr>
  <tr>
    <td>Language <font color="red">*</font></td>
    <td>
      <select name="language_a">
        <?php foreach ($lang_map as $k => $v): ?>
        <option value="<?php echo $k; ?>" <?php echo ((isset($_POST['language_a']) ? (int)$_POST['language_a'] : 1) == $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr>
    <td>Outlet <font color="red">*</font></td>
    <td><select name="outlet_id_a" id="outlet_id_a_select" style="width:100%"><?php echo _outletOptions($outlets, isset($_POST['outlet_id_a']) ? $_POST['outlet_id_a'] : ''); ?></select></td>
  </tr>
  <tr>
    <td>E-mail</td>
    <td><input type="email" name="email_a" value="<?php echo _v('email_a'); ?>" maxlength="100" placeholder="example@email.com"></td>
  </tr>
  <tr>
    <td>Address</td>
    <td><textarea name="address_a" rows="3"><?php echo _v('address_a'); ?></textarea></td>
  </tr>
  <tr>
    <td>Race</td>
    <td><select name="race_a"><?php echo _raceOptions($races, isset($_POST['race_a']) ? $_POST['race_a'] : ''); ?></select></td>
  </tr>
  <tr>
    <td>Diagnosis</td>
    <td><textarea name="diagnosis_a" rows="4" placeholder="Medical diagnosis / conditions..."><?php echo _v('diagnosis_a'); ?></textarea></td>
  </tr>
  <tr>
    <td>Allergic Status</td>
    <td><textarea name="allergic_a" rows="2" placeholder="Known allergies..."><?php echo _v('allergic_a'); ?></textarea></td>
  </tr>
  <tr>
    <td></td>
    <td>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input class="alpro-btn alpro-btn-blue" type="submit" name="save_mama_a" value="Save Mama"
               style="width:auto;" onclick="return confirm('Confirm to save this mama record?');">
        <a class="alpro-btn alpro-btn-grey" href="mamabe_mama_list.php" style="width:auto;">Back to List</a>
      </div>
    </td>
  </tr>
</table>
</form>
</div>

<!-- ══════════ TAB B – OLD IC / PASSPORT ══════════ -->
<div class="tab">
<form method="post" onsubmit="return validateFormB()">
<input type="hidden" name="c_id_b" id="c_id_b" value="<?php echo _v('c_id_b'); ?>">
<div id="reactivate_note_b" style="display:none; margin-bottom:10px; padding:10px 14px; background:#fff3e0; border:1px solid #e0c090; border-radius:5px; font-size:0.88em; color:#8a5a00;">
  &#8635; This IC belongs to an <strong>existing customer</strong> (Inactive — last registered <span id="reactivate_since_b"></span>). Her details are shown below. Submitting will <strong>reactivate</strong> her existing account, not create a new one.
</div>
<div id="active_note_b" style="display:none; margin-bottom:10px; padding:10px 14px; background:#fff3f3; border:1px solid #ef9a9a; border-radius:5px; font-size:0.88em; color:#c62828;">
  &#9888; This IC is already registered and active. You cannot register her again.
</div>
<table class="form-table" width="100%" cellspacing="2" cellpadding="0">
  <tr>
    <td>Alpro VIP Member</td>
    <td>
      <div style="display:flex;align-items:center;gap:6px;">
        <input type="text" id="vip_lookup_b" placeholder="Enter member code" style="width:180px;flex:none;">
        <button type="button" id="vip_search_b" class="alpro-btn alpro-btn-grey" style="width:auto;padding:5px 12px;">
          <img src="../common/img/search.png" style="width:14px;vertical-align:middle"> Search
        </button>
        <span id="vip_status_b" style="font-size:0.85em;color:#888;"></span>
      </div>
    </td>
  </tr>
  <tr>
    <td>Full Name <font color="red">*</font></td>
    <td>
      <input type="text" name="mama_name_b" id="mama_name_b_input"
             value="<?php echo _v('mama_name_b'); ?>" maxlength="100" required
             placeholder="Type name or IC to search existing customer..." autocomplete="off">
    </td>
  </tr>
  <tr>
    <td>Old IC / Passport <font color="red">*</font></td>
    <td><input type="text" name="ic_b" id="ic_b" value="<?php echo _v('ic_b'); ?>" maxlength="30" placeholder="Old IC or Passport No." required></td>
  </tr>
  <tr>
    <td>Nationality</td>
    <td>
      <?php $_nat_b = isset($_POST['nationality_b']) ? $_POST['nationality_b'] : ''; ?>
      <select id="uc_nationality_sel" name="nationality_b" class="alpro-input" onchange="toggleNationality(this, 'nationality_other_uc')">
        <option value="">Pick One</option>
        <?php foreach (array('MALAYSIA','SINGAPORE','INDONESIA','BRUNEI','PHILIPPINES','THAILAND','OTHERS') as $_nat_opt): ?>
        <option value="<?php echo $_nat_opt; ?>" <?php echo ($_nat_b === $_nat_opt) ? 'selected' : ''; ?>><?php echo $_nat_opt; ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="nationality_other_b" id="nationality_other_uc"
             value="<?php echo _v('nationality_other_b'); ?>" maxlength="100" placeholder="Please specify nationality"
             style="margin-top:6px; <?php echo ($_nat_b === 'OTHERS') ? '' : 'display:none;'; ?>">
    </td>
  </tr>
  <tr>
    <td>Date of Birth <font color="red">*</font></td>
    <td><input type="text" name="birth_date_b" id="birth_date_b" value="<?php echo _v('birth_date_b') ? _v('birth_date_b') : date('Y-m-d'); ?>" maxlength="10"></td>
  </tr>
  <tr>
    <td>Gender <font color="red">*</font></td>
    <td>
      <label><input type="radio" name="gender_b" value="Female" <?php echo (_v('gender_b') !== 'Male') ? 'checked' : ''; ?>> Female</label>&nbsp;&nbsp;
      <label><input type="radio" name="gender_b" value="Male"   <?php echo (_v('gender_b') === 'Male')  ? 'checked' : ''; ?>> Male</label>
    </td>
  </tr>
  <tr>
    <td>Phone <font color="red">*</font></td>
    <td><input type="text" name="phone_b" id="phone_b" value="<?php echo _v('phone_b'); ?>" maxlength="15" placeholder="e.g. 0123456789" required></td>
  </tr>
  <tr>
    <td>Language <font color="red">*</font></td>
    <td>
      <select name="language_b">
        <?php foreach ($lang_map as $k => $v): ?>
        <option value="<?php echo $k; ?>" <?php echo ((isset($_POST['language_b']) ? (int)$_POST['language_b'] : 1) == $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr>
    <td>Outlet <font color="red">*</font></td>
    <td><select name="outlet_id_b" id="outlet_id_b_select" style="width:100%"><?php echo _outletOptions($outlets2, isset($_POST['outlet_id_b']) ? $_POST['outlet_id_b'] : ''); ?></select></td>
  </tr>
  <tr>
    <td>E-mail</td>
    <td><input type="email" name="email_b" value="<?php echo _v('email_b'); ?>" maxlength="100" placeholder="example@email.com"></td>
  </tr>
  <tr>
    <td>Address</td>
    <td><textarea name="address_b" rows="3"><?php echo _v('address_b'); ?></textarea></td>
  </tr>
  <tr>
    <td>Race</td>
    <td><select name="race_b"><?php echo _raceOptions($races, isset($_POST['race_b']) ? $_POST['race_b'] : ''); ?></select></td>
  </tr>
  <tr>
    <td>Diagnosis</td>
    <td><textarea name="diagnosis_b" rows="4" placeholder="Medical diagnosis / conditions..."><?php echo _v('diagnosis_b'); ?></textarea></td>
  </tr>
  <tr>
    <td>Allergic Status</td>
    <td><textarea name="allergic_b" rows="2" placeholder="Known allergies..."><?php echo _v('allergic_b'); ?></textarea></td>
  </tr>
  <tr>
    <td></td>
    <td>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input class="alpro-btn alpro-btn-blue" type="submit" name="save_mama_b" value="Save Mama"
               style="width:auto;" onclick="return confirm('Confirm to save this mama record?');">
        <a class="alpro-btn alpro-btn-grey" href="mamabe_mama_list.php" style="width:auto;">Back to List</a>
      </div>
    </td>
  </tr>
</table>
</form>
</div>

</div><!-- end tabsContent -->
</fieldset>

<fieldset><b>Fields marked with an asterisk <font color="red">*</font> are compulsory.</b></fieldset>

<script>
// Auto-tab IC fields
document.getElementById('ic1').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'');
    if (this.value.length >= 6) document.getElementById('ic2').focus();
});
document.getElementById('ic2').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'');
    if (this.value.length >= 2) document.getElementById('ic3').focus();
});
document.getElementById('ic3').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'');
});
document.getElementById('phone_a').addEventListener('input', function() { this.value = this.value.replace(/\D/g,''); });
document.getElementById('phone_b').addEventListener('input', function() { this.value = this.value.replace(/\D/g,''); });

function validateFormA() {
    var p = document.querySelector('[name=phone_a]').value;
    if (p.length < 10 || p.length > 14) { alert('Please enter a valid phone number.'); return false; }
    var ic1 = document.getElementById('ic1').value;
    if (ic1.length === 6) {
        var mm = parseInt(ic1.substr(2,2)), dd = parseInt(ic1.substr(4,2));
        if (mm > 12 || mm === 0 || dd > 31 || dd === 0) { alert('Invalid IC number.'); return false; }
    }
    return true;
}

function validateFormB() {
    var p = document.querySelector('[name=phone_b]').value;
    if (p.length < 10 || p.length > 14) { alert('Please enter a valid phone number.'); return false; }
    return true;
}

// VIP member lookup
function vipLookup(cId, tab) {
    var statusEl = document.getElementById('vip_status_' + tab);
    statusEl.style.color = '#888';
    statusEl.textContent = 'Searching...';
    $.ajax({
        url: '../customer/ajax.php',
        type: 'POST',
        data: { id: cId },
        success: function(data) {
            try {
                var r = JSON.parse(data);
                if (r['data']['clients'].length > 0) {
                    var c      = r['data']['clients'][0];
                    var cName  = c['name'] || '';
                    if (cName.substr(0,3).toLowerCase() == '(s)' || cName.substr(0,3).toLowerCase() == '(f)') {
                        cName = cName.substr(3);
                    }
                    var cIC    = c['ic']             || '';
                    var cPhone = c['mobile']         || '';
                    var cEmail = c['email']          || '';
                    var cAddr  = c['billingAddress'] || '';
                    var cCode  = c['code']           || '';
                    var cLang  = c['customFieldValue1'] || '';
                    var cRace  = c['race'] || '';
                    var langVal = 1;
                    if (cLang == 'BM') { langVal = 2; }
                    else if (cLang == 'Mandarin') { langVal = 3; }
                    var raceMap = {'MALAY':'Malay','CHINESE':'Chinese','INDIAN':'Indian',
                                   'SABAH ETHNIC':'Bumiputera Sabah','SARAWAK ETHNIC':'Bumiputera Sarawak','OTHERS':'Others'};
                    var raceTxt = raceMap[cRace] || '';

                    if (tab == 'a') {
                        document.getElementById('c_id_a').value = cCode;
                        document.querySelector('[name=mama_name_a]').value = cName;
                        document.querySelector('[name=phone_a]').value     = cPhone;
                        document.querySelector('[name=email_a]').value     = cEmail;
                        document.querySelector('[name=address_a]').value   = cAddr;
                        document.querySelector('[name=language_a]').value  = langVal;
                        document.querySelector('[name=race_a]').value      = raceTxt;
                        if (cIC.length == 12 && !isNaN(cIC)) {
                            document.getElementById('ic1').value = cIC.substr(0,6);
                            document.getElementById('ic2').value = cIC.substr(6,2);
                            document.getElementById('ic3').value = cIC.substr(8,4);
                        }
                    } else {
                        document.getElementById('c_id_b').value = cCode;
                        document.querySelector('[name=mama_name_b]').value  = cName;
                        document.querySelector('[name=phone_b]').value      = cPhone;
                        document.querySelector('[name=email_b]').value      = cEmail;
                        document.querySelector('[name=address_b]').value    = cAddr;
                        document.querySelector('[name=language_b]').value   = langVal;
                        document.querySelector('[name=race_b]').value       = raceTxt;
                        if (cIC.length == 12 && !isNaN(cIC)) {
                            document.querySelector('[name=ic_b]').value = cIC;
                        }
                        var cDob = (c['dob'] || '').substr(0,10);
                        if (cDob) { document.getElementById('birth_date_b').value = cDob; }
                        var gnd = c['gender'] || '';
                        if (gnd == 'male') {
                            document.querySelector('[name=gender_b][value=Male]').checked = true;
                        } else {
                            document.querySelector('[name=gender_b][value=Female]').checked = true;
                        }
                    }
                    statusEl.style.color = '#155724';
                    statusEl.textContent = 'Found: ' + cName;
                } else {
                    statusEl.style.color = '#c82333';
                    statusEl.textContent = 'Member not found.';
                }
            } catch(e) {
                statusEl.style.color = '#c82333';
                statusEl.textContent = 'Error reading response.';
            }
        },
        error: function() {
            statusEl.style.color = '#c82333';
            statusEl.textContent = 'Connection error.';
        }
    });
}

function getXMLHTTP() {
    var x = false;
    try { x = new XMLHttpRequest(); } catch(e) {
        try { x = new ActiveXObject("Microsoft.XMLHTTP"); } catch(e) {
            try { x = new ActiveXObject("Msxml2.XMLHTTP"); } catch(e1) { x = false; }
        }
    }
    return x;
}

function fillFromCustomer(ic, name, targetTab) {
    var xhr = getXMLHTTP();
    if (!xhr) return;
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            var parts = xhr.responseText.split('|');
            // parts: tick | ic | name | addr | language | id | phone
            if (parts.length < 6) return;
            var retIC    = parts[1];
            var retName  = parts[2];
            var retAddr  = parts[3];
            var retLang  = parts[4];
            var retPhone = parts[6] || '';

            if (targetTab == 'a') {
                document.querySelector('[name=mama_name_a]').value = retName;
                document.querySelector('[name=phone_a]').value     = retPhone;
                document.querySelector('[name=address_a]').value   = retAddr;
                if (retLang) document.querySelector('[name=language_a]').value = retLang;
                if (retIC.length == 12 && !isNaN(retIC)) {
                    document.getElementById('ic1').value = retIC.substr(0,6);
                    document.getElementById('ic2').value = retIC.substr(6,2);
                    document.getElementById('ic3').value = retIC.substr(8,4);
                }
            } else {
                document.querySelector('[name=mama_name_b]').value = retName;
                document.querySelector('[name=phone_b]').value     = retPhone;
                document.querySelector('[name=address_b]').value   = retAddr;
                document.querySelector('[name=ic_b]').value        = retIC;
                if (retLang) document.querySelector('[name=language_b]').value = retLang;
            }
        }
    };
    xhr.open("GET", "mamabe_ajax.php?ic=" + encodeURIComponent(ic) + "&name=" + encodeURIComponent(name), true);
    xhr.send();
}

$(document).ready(function() {
    // Autocomplete for Tab A name field
    $("#mama_name_a_input").autocomplete("mamabe_search_customer.php", {
        width: 460, matchContains: true, selectFirst: false, delay: 10
    });
    $("#mama_name_a_input").result(function(event, data, formatted) {
        var val  = document.getElementById("mama_name_a_input").value;
        var info = val.split(" : ");
        document.querySelector('[name=mama_name_a]').value = info[1] || info[0];
        fillFromCustomer(info[0], info[1] || info[0], 'a');
    });

    // Autocomplete for Tab B name field
    $("#mama_name_b_input").autocomplete("mamabe_search_customer.php", {
        width: 460, matchContains: true, selectFirst: false, delay: 10
    });
    $("#mama_name_b_input").result(function(event, data, formatted) {
        var val  = document.getElementById("mama_name_b_input").value;
        var info = val.split(" : ");
        document.querySelector('[name=mama_name_b]').value = info[1] || info[0];
        fillFromCustomer(info[0], info[1] || info[0], 'b');
    });

    $('#vip_search_a').click(function() {
        var cId = $('#vip_lookup_a').val().trim();
        if (!cId) { alert('Please enter a member code.'); return; }
        vipLookup(cId, 'a');
    });
    $('#vip_lookup_a').keydown(function(e) {
        if (e.which == 13) { e.preventDefault(); $('#vip_search_a').click(); }
    });
    $('#vip_search_b').click(function() {
        var cId = $('#vip_lookup_b').val().trim();
        if (!cId) { alert('Please enter a member code.'); return; }
        vipLookup(cId, 'b');
    });
    $('#vip_lookup_b').keydown(function(e) {
        if (e.which == 13) { e.preventDefault(); $('#vip_search_b').click(); }
    });

});
</script>

<script>
window.addEventListener('load', function() {
    if (document.getElementById('outlet_id_a_select')) {
        new TomSelect('#outlet_id_a_select', {
            create: false, sortField: { field: 'text', direction: 'asc' },
            maxOptions: null, plugins: ['dropdown_input']
        });
    }
    if (document.getElementById('outlet_id_b_select')) {
        new TomSelect('#outlet_id_b_select', {
            create: false, sortField: { field: 'text', direction: 'asc' },
            maxOptions: null, plugins: ['dropdown_input']
        });
    }
});
</script>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
