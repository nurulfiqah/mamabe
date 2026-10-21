<?php
require_once('../lock_adv.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

require_once(__DIR__ . '/nas_config.php');

// Upload a pink_book / baby_health_book file straight to the corporate NAS
// (under CORP_NAS_FOLDER/$subfolder), using PHP's own upload tmp_name –
// no local uploads/ folder involved. Returns the NAS path on success, or
// '' if there was no file / the upload failed.
function mamabe_nas_upload_file($fieldName, $subfolder, $filenamePrefix) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] != 0) {
        return '';
    }
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'pdf'))) {
        return '';
    }
    $filename = $filenamePrefix . '_' . time() . '.' . $ext;
    try {
        $nas = corpNasConnect();
        $remoteFolder = rtrim(CORP_NAS_FOLDER, '/') . '/' . $subfolder;
        $nasPath = $nas->upload($_FILES[$fieldName]['tmp_name'], $remoteFolder, $filename);
        return $nasPath ? $nasPath : '';
    } catch (Exception $e) {
        return '';
    }
}

// Delete a file previously stored on the NAS (safe no-op if $nasPath is
// empty or doesn't look like a mamabe NAS path).
function mamabe_nas_delete_file($nasPath) {
    if (empty($nasPath) || strpos($nasPath, CORP_NAS_FOLDER) !== 0) {
        return;
    }
    try {
        $nas = corpNasConnect();
        $nas->delete($nasPath);
    } catch (Exception $e) {
        // best-effort; ignore failures so a delete never blocks the page
    }
}

// Resolve a stored pink_book/baby_health_book path into a browser-servable
// URL: legacy local paths (uploads/...) are served as-is, NAS paths go
// through the streaming proxy since the NAS itself isn't a public URL.
function mamabe_file_url($path) {
    if (empty($path)) { return ''; }
    if (strpos($path, 'uploads/') === 0) { return $path; }
    return 'mamabe_view_file.php?path=' . urlencode($path);
}

// Admin Override Mode – super-admin only, toggled on mamabe_admin_control.php.
// Admin Override Mode (mamabe_admin_control.php's session toggle to bypass the 24h benefit/photo-reupload lock)
// was removed - always false now, so every "$_admin_override ? false : (...)" lock check below behaves exactly
// as if the toggle was permanently off. Kept as a named constant rather than editing every one of those checks
// individually, so the 24h-lock logic itself stays exactly as written.
$_admin_override = false;

$lang_map = array(1 => 'English', 2 => 'Bahasa Malaysia', 3 => 'Chinese', 4 => 'Tamil');

// Auto-add benefit columns if missing
$_need = array(
    'pink_book'             => "VARCHAR(255) NOT NULL DEFAULT ''",
    'benefit_mama_book'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'benefit_vitamin_d'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'benefit_50_off'        => "TINYINT(1) NOT NULL DEFAULT 0",
    'receipt_no'            => "VARCHAR(100) NOT NULL DEFAULT ''",
    'benefit_mama_book_at'  => "DATETIME NULL DEFAULT NULL",
    'benefit_vitamin_d_at'  => "DATETIME NULL DEFAULT NULL",
    'benefit_50_off_at'     => "DATETIME NULL DEFAULT NULL",
    'benefit_50_off_items'       => "VARCHAR(500) NOT NULL DEFAULT ''",
    'receipt_price_mismatch'     => "TEXT NOT NULL DEFAULT ''",
    'registered_by'              => "VARCHAR(150) NOT NULL DEFAULT ''",
);
foreach ($_need as $_col => $_def) {
    $_chk = $conn->query("SHOW COLUMNS FROM mamabe_mama LIKE '$_col'");
    if ($_chk && $_chk->num_rows === 0) {
        $conn->query("ALTER TABLE mamabe_mama ADD COLUMN $_col $_def");
    }
}

// Auto-add baby delivery benefit columns if missing
$_baby_need = array(
    'baby_health_book'          => "VARCHAR(255) NOT NULL DEFAULT ''",
    'benefit_baby_book'         => "TINYINT(1) NOT NULL DEFAULT 0",
    'benefit_free_diapers'      => "TINYINT(1) NOT NULL DEFAULT 0",
    'benefit_free_diapers2'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'benefit_50_off_baby'       => "TINYINT(1) NOT NULL DEFAULT 0",
    'benefit_baby_book_at'      => "DATETIME NULL DEFAULT NULL",
    'benefit_free_diapers_at'   => "DATETIME NULL DEFAULT NULL",
    'benefit_free_diapers2_at'  => "DATETIME NULL DEFAULT NULL",
    'benefit_50_off_baby_at'    => "DATETIME NULL DEFAULT NULL",
    'benefit_50_off_baby_items' => "VARCHAR(500) NOT NULL DEFAULT ''",
    'baby_receipt_no'                => "VARCHAR(100) NOT NULL DEFAULT ''",
    'vd_receipt_no'                  => "VARCHAR(100) NOT NULL DEFAULT ''",
    'receipt_price_mismatch'         => "TEXT NOT NULL DEFAULT ''",
    'baby_receipt_price_mismatch'    => "TEXT NOT NULL DEFAULT ''",
    'twin_id'                        => "INT NOT NULL DEFAULT 0",
    'preg_filled_by'                 => "VARCHAR(150) NOT NULL DEFAULT ''",
    'preg_filled_at'                 => "DATETIME NULL DEFAULT NULL",
    'baby_filled_by'                 => "VARCHAR(150) NOT NULL DEFAULT ''",
    'baby_filled_at'                 => "DATETIME NULL DEFAULT NULL",
    'benefit_free_items'              => "TEXT NOT NULL",
    'benefit_free_items_at'           => "DATETIME NULL DEFAULT NULL",
    'benefit_free_items_baby'         => "TEXT NOT NULL",
    'benefit_free_items_baby_at'      => "DATETIME NULL DEFAULT NULL",
);
$_free_items_just_added = false;
foreach ($_baby_need as $_col => $_def) {
    $_chk = $conn->query("SHOW COLUMNS FROM mamabe_baby LIKE '$_col'");
    if ($_chk && $_chk->num_rows === 0) {
        $conn->query("ALTER TABLE mamabe_baby ADD COLUMN $_col $_def");
        if ($_col === 'benefit_free_items') { $_free_items_just_added = true; }
    }
}

// Free item catalog (editable via mamabe_sku_master.php) – a flexible,
// admin-managed list of receipt-matched free items, replacing the old
// 3-fixed-slot design (baby diapers x2, mama vitamin D x1).
$conn->query("CREATE TABLE IF NOT EXISTS mamabe_free_item_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(10) NOT NULL DEFAULT 'baby',
    item_code VARCHAR(50) NOT NULL DEFAULT '',
    item_name VARCHAR(255) NOT NULL DEFAULT '',
    recycle TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed the catalog once, from the old fixed-slot config if it exists
// (preserves any edits already made there), else from the original defaults.
$_seed_diaper1 = array('code' => '100648468', 'name' => 'Diapers');
$_seed_diaper2 = array('code' => '100657196', 'name' => '(S) BZU BZU Velvet Breeze Baby Diapers Tape S 3pcs');
$_seed_vitd    = array('code' => '100624444', 'name' => 'Free Vitamin D Test');
$_old_cfg_res = $conn->query("SHOW TABLES LIKE 'mamabe_receipt_benefit_config'");
if ($_old_cfg_res && $_old_cfg_res->num_rows > 0) {
    $_old_cfg = $conn->query("SELECT slot, item_code, item_name FROM mamabe_receipt_benefit_config");
    if ($_old_cfg) {
        while ($_ocr = $_old_cfg->fetch_assoc()) {
            $_pair = array('code' => $_ocr['item_code'], 'name' => $_ocr['item_name']);
            if ((int)$_ocr['slot'] === 1) { $_seed_diaper1 = $_pair; }
            if ((int)$_ocr['slot'] === 2) { $_seed_diaper2 = $_pair; }
            if ((int)$_ocr['slot'] === 3) { $_seed_vitd = $_pair; }
        }
    }
}
$_cat_chk = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_free_item_catalog");
if ($_cat_chk && (int)$_cat_chk->fetch_assoc()['cnt'] === 0) {
    $stmt = $conn->prepare("INSERT INTO mamabe_free_item_catalog (scope, item_code, item_name) VALUES (?, ?, ?)");
    foreach (array(
        array('baby', $_seed_diaper1['code'], $_seed_diaper1['name']),
        array('baby', $_seed_diaper2['code'], $_seed_diaper2['name']),
        array('mama', $_seed_vitd['code'], $_seed_vitd['name']),
    ) as $_seed_row) {
        $stmt->bind_param("sss", $_seed_row[0], $_seed_row[1], $_seed_row[2]);
        $stmt->execute();
    }
    $stmt->close();
}

// One-time migration: fold the old fixed vitamin_d / free_diapers x2 columns
// into the new flexible "free items" lists, the first time those new columns
// are created. Old columns are left untouched (historical/audit record).
if ($_free_items_just_added) {
    $_mig_vitd_code    = $conn->real_escape_string($_seed_vitd['code']);
    $_mig_diaper1_code = $conn->real_escape_string($_seed_diaper1['code']);
    $_mig_diaper2_code = $conn->real_escape_string($_seed_diaper2['code']);
    $conn->query("
        UPDATE mamabe_baby
        SET benefit_free_items = '{$_mig_vitd_code}',
            benefit_free_items_at = benefit_vitamin_d_at
        WHERE benefit_vitamin_d = 1 AND (benefit_vitamin_d_at IS NOT NULL AND CAST(benefit_vitamin_d_at AS CHAR) != '0000-00-00 00:00:00')
    ");
    $conn->query("
        UPDATE mamabe_baby
        SET benefit_free_items_baby = TRIM(BOTH ',' FROM CONCAT(
                IF(benefit_free_diapers=1, '{$_mig_diaper1_code}', ''), ',',
                IF(benefit_free_diapers2=1, '{$_mig_diaper2_code}', '')
            )),
            benefit_free_items_baby_at = LEAST(
                IFNULL(NULLIF(CAST(benefit_free_diapers_at AS CHAR), '0000-00-00 00:00:00'), '9999-12-31'),
                IFNULL(NULLIF(CAST(benefit_free_diapers2_at AS CHAR), '0000-00-00 00:00:00'), '9999-12-31')
            )
        WHERE benefit_free_diapers = 1 OR benefit_free_diapers2 = 1
    ");
}

// Load active free-item catalog, split by scope
$free_items_mama = array(); // code => name
$free_items_baby = array(); // code => name
$_fic_res = $conn->query("SELECT scope, item_code, item_name FROM mamabe_free_item_catalog WHERE recycle=0 ORDER BY item_name");
if ($_fic_res) {
    while ($_fic_row = $_fic_res->fetch_assoc()) {
        if ($_fic_row['scope'] === 'mama') { $free_items_mama[$_fic_row['item_code']] = $_fic_row['item_name']; }
        else { $free_items_baby[$_fic_row['item_code']] = $_fic_row['item_name']; }
    }
}
// Ensure combined SKU master table exists (type: 1=mama, 2=baby)
$conn->query("CREATE TABLE IF NOT EXISTS mamabe_sku_master (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(50) NOT NULL, item_name VARCHAR(255) NOT NULL DEFAULT '', price DECIMAL(10,2) NOT NULL DEFAULT 0.00, type TINYINT(1) NOT NULL DEFAULT 1, recycle TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$_chk = $conn->query("SHOW COLUMNS FROM mamabe_sku_master LIKE 'price'");
if ($_chk && $_chk->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_sku_master ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER item_name");
}

// Fetch Master SKUs from combined table (name + price)
$master_skus  = array('mama' => array(), 'baby' => array());
$master_prices = array('mama' => array(), 'baby' => array());
$_ms = $conn->query("SELECT sku, item_name, price, type FROM mamabe_sku_master WHERE recycle=0 ORDER BY item_name");
if ($_ms) { while ($_r = $_ms->fetch_assoc()) { $t = ($_r['type'] == 1) ? 'mama' : 'baby'; $master_skus[$t][$_r['sku']] = $_r['item_name']; $master_prices[$t][$_r['sku']] = (float)$_r['price']; } }

// Auto-insert new SKUs into master table when items are saved from a receipt
// $type: 1 = mama, 2 = baby
function mamabe_auto_insert_skus($conn, $type, $skus_arr, $names_arr) {
    if (empty($skus_arr)) return;
    foreach ($skus_arr as $i => $sku) {
        $sku = trim($sku);
        if (!$sku) continue;
        $name = isset($names_arr[$i]) ? trim($names_arr[$i]) : $sku;
        if (!$name) $name = $sku;
        $chk = $conn->prepare("SELECT id FROM mamabe_sku_master WHERE sku=? AND type=? AND recycle=0 LIMIT 1");
        $chk->bind_param("si", $sku, $type);
        $chk->execute();
        $exists = ($chk->get_result()->num_rows > 0);
        $chk->close();
        if (!$exists) {
            $ins = $conn->prepare("INSERT INTO mamabe_sku_master (sku, item_name, type) VALUES (?, ?, ?)");
            $ins->bind_param("ssi", $sku, $name, $type);
            $ins->execute();
            $ins->close();
        }
    }
}

// Merge a submitted list of free-item codes with the existing claim, honouring
// the shared 24h lock: once locked, the stored list/timestamp never changes;
// while unlocked, the submitted list replaces the stored one and the "first
// claimed at" timestamp is preserved if one already exists, else set to now.
function mamabe_merge_free_items($post_field, $existing_csv, $existing_at, $now_ts, $locked) {
    if ($locked) {
        return array($existing_csv, $existing_at);
    }
    $arr = (isset($_POST[$post_field]) && is_array($_POST[$post_field])) ? array_filter(array_map('trim', $_POST[$post_field])) : array();
    $arr = array_values(array_unique($arr));
    if (empty($arr)) {
        return array('', null);
    }
    $at = (!empty($existing_at) && $existing_at !== '0000-00-00 00:00:00') ? $existing_at : $now_ts;
    return array(implode(',', $arr), $at);
}

// Render the "Free Items" auto-receipt-matched benefit block: a flexible
// list (replacing the old fixed vitamin_d / free_diapers x2 single-item
// slots) with one shared lock/claim timestamp for the whole group.
function mamabe_render_free_items($bid, $field_prefix, $claimed_csv, $claimed_at, $catalog, $locked) {
    $codes = array_values(array_filter(array_map('trim', explode(',', (string)$claimed_csv))));
    $checked_map = array_flip($codes);
    $has_items = !empty($codes);
    $lock_style = $locked ? 'opacity:0.7; pointer-events:none;' : '';
    ob_start();
    ?>
    <div id="<?php echo $field_prefix; ?>_display_<?php echo $bid; ?>" style="margin-bottom:6px; padding:6px 10px; border-radius:6px; border:1px solid <?php echo $has_items ? '#2a7a4a' : '#e0e0e0'; ?>; background:<?php echo $has_items ? '#f0fff5' : '#fff'; ?>; <?php echo $lock_style; ?>">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; padding-bottom:6px; border-bottom:1px dashed <?php echo $has_items ? '#c8e6c9' : '#eee'; ?>;">
        <span style="font-size:0.86em; color:#333; font-weight:bold;">Free Items</span>
        <?php if ($has_items && $claimed_at): ?>
        <span style="text-align:right; line-height:1.3;">
          <span style="display:block; font-size:0.71em; font-weight:bold; color:#2a7a4a;">&#10003; Done</span>
          <span style="display:block; font-size:0.68em; color:#999;"><?php echo htmlspecialchars($claimed_at); ?></span>
        </span>
        <?php else: ?>
        <span class="auto-receipt-hint" style="font-size:0.72em; color:#aaa; font-style:italic;">Auto from receipt</span>
        <?php endif; ?>
      </div>
      <div id="<?php echo $field_prefix; ?>_container_<?php echo $bid; ?>">
        <?php if (empty($catalog)): ?>
        <div style="font-size:0.78em; color:#aaa; font-style:italic;">No free items configured.</div>
        <?php endif; ?>
        <?php foreach ($catalog as $_code => $_name):
          $_is_checked = isset($checked_map[$_code]);
        ?>
        <label data-sku="<?php echo htmlspecialchars($_code); ?>" style="display:flex; align-items:center; gap:8px; margin-bottom:3px; font-size:0.8em; color:<?php echo $_is_checked ? '#333' : '#aaa'; ?>;">
          <input type="checkbox" name="<?php echo $field_prefix; ?>[]" value="<?php echo htmlspecialchars($_code); ?>" <?php echo $_is_checked ? 'checked' : ''; ?>
                 onclick="return false;" onkeydown="return false;" tabindex="-1"
                 style="width:14px; height:14px; accent-color:#2a7a4a; cursor:default;">
          <span style="color:#aaa; font-family:monospace; font-size:0.85em; min-width:82px;"><?php echo htmlspecialchars($_code); ?></span>
          <span style="flex:1;"><?php echo htmlspecialchars($_name); ?></span>
          <?php if ($_is_checked): ?><span style="color:#2a7a4a; font-weight:bold;">&#10003;</span><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: mamabe_mama_list.php'); exit; } //tukar 14/4

// Load mama
$stmt = $conn->prepare("
    SELECT m.*, o.comp_name AS outlet_name
    FROM mamabe_mama m
    LEFT JOIN outlet o ON m.outlet_id = o.id
    WHERE m.id = ? AND m.recycle = 0
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$mama = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$mama) {
    echo '<p style="padding:20px">Mama record not found.</p>';
    $connect = 0; include('../common/index_adv.php'); exit;
}

// Access control: outlet staff can only view their outlet's records
// Exception: ALPRO BABY outlet records are visible to ALL outlet staff
$_is_admin = (isset($mamab_autho) && in_array($mamab_autho, array('1', '3'), true));
if (!$_is_admin && !empty($outlet_autho)) {
    $_allowed = array_filter(array_map('intval', explode(',', $outlet_autho)));
    $_mama_is_alpro_baby = (stripos(isset($mama['outlet_name']) ? $mama['outlet_name'] : '', 'ALPRO BABY') !== false);
    if (!$_mama_is_alpro_baby && !in_array((int)$mama['outlet_id'], $_allowed)) {
        header('Location: mamabe_mama_list.php');
        exit;
    }
}


$msg      = '';
$msg_type = '';

/*
SQL – add once:
ALTER TABLE mamabe_mama
    ADD COLUMN pink_book             VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN benefit_mama_book     TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN benefit_vitamin_d     TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN benefit_50_off        TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN receipt_no            VARCHAR(100) NOT NULL DEFAULT '',
    ADD COLUMN benefit_mama_book_at  DATETIME     NULL DEFAULT NULL,
    ADD COLUMN benefit_vitamin_d_at  DATETIME     NULL DEFAULT NULL,
    ADD COLUMN benefit_50_off_at     DATETIME     NULL DEFAULT NULL;

SQL – add once to mamabe_baby:
ALTER TABLE mamabe_baby
    ADD COLUMN pink_book             VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN benefit_mama_book     TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN benefit_vitamin_d     TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN benefit_50_off        TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN benefit_mama_book_at  DATETIME     NULL DEFAULT NULL,
    ADD COLUMN benefit_vitamin_d_at  DATETIME     NULL DEFAULT NULL,
    ADD COLUMN benefit_50_off_at     DATETIME     NULL DEFAULT NULL,
    ADD COLUMN benefit_50_off_items  TEXT         NOT NULL DEFAULT '',
    ADD COLUMN receipt_no            VARCHAR(100) NOT NULL DEFAULT '';
*/

// --- Update Mama ---
if (isset($_POST['update_mama'])) {
    $mama_name = trim($_POST['mama_name'] ?? '');
    $ic        = trim($_POST['ic'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $language  = (int)($_POST['language'] ?? 0);
    $outlet_id = (int)($_POST['outlet_id'] ?? 0);

    if (!$mama_name || !$ic || !$address || !$outlet_id) {
        $msg      = 'Please fill in all required fields.';
        $msg_type = 'danger';
    } else {
        $stmt = $conn->prepare("
            UPDATE mamabe_mama SET mama_name=?, ic=?, phone=?, address=?, language=?, outlet_id=?
            WHERE id=?
        ");
        $stmt->bind_param("ssssiii", $mama_name, $ic, $phone, $address, $language, $outlet_id, $id);
        if ($stmt->execute()) {
            $msg      = 'Mama details updated.';
            $msg_type = 'success';
            $r = $conn->prepare("SELECT m.*, o.comp_name AS outlet_name FROM mamabe_mama m LEFT JOIN outlet o ON m.outlet_id=o.id WHERE m.id=? AND m.recycle=0 LIMIT 1");
            $r->bind_param("i", $id);
            $r->execute();
            $mama = $r->get_result()->fetch_assoc();
            $r->close();
        } else {
            $msg      = 'Database error: ' . $conn->error;
            $msg_type = 'danger';
        }
        $stmt->close();
    }
}

// --- Delete Mama ---
if (isset($_POST['delete_mama'])) {
    // Delete mama's pink book file
    $mf = $conn->prepare("SELECT pink_book FROM mamabe_mama WHERE id=? LIMIT 1");
    $mf->bind_param("i", $id);
    $mf->execute();
    $mf_row = $mf->get_result()->fetch_assoc();
    $mf->close();
    if ($mf_row && !empty($mf_row['pink_book'])) {
        if (strpos($mf_row['pink_book'], 'uploads/') === 0 && file_exists($mf_row['pink_book'])) {
            unlink($mf_row['pink_book']);
        } else {
            mamabe_nas_delete_file($mf_row['pink_book']);
        }
    }
    // Delete all baby files (pink_book + baby_health_book) belonging to this mama
    $bf = $conn->query("SELECT pink_book, baby_health_book FROM mamabe_baby WHERE mama_id=" . (int)$id . " AND recycle=0");
    if ($bf) {
        while ($bf_row = $bf->fetch_assoc()) {
            foreach (array('pink_book', 'baby_health_book') as $fcol) {
                if (empty($bf_row[$fcol])) { continue; }
                if (strpos($bf_row[$fcol], 'uploads/') === 0 && file_exists($bf_row[$fcol])) {
                    unlink($bf_row[$fcol]);
                } else {
                    mamabe_nas_delete_file($bf_row[$fcol]);
                }
            }
        }
    }
    $conn->query("UPDATE mamabe_baby SET recycle=1 WHERE mama_id=" . (int)$id);
    $stmt = $conn->prepare("UPDATE mamabe_mama SET recycle=1 WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header('Location: mamabe_mama_list.php');   // tukar 14/4
    exit;
}

// --- Add Baby ---
if (isset($_POST['add_baby'])) {
    $lmp             = trim($_POST['lmp'] ?? '');
    $edd             = trim($_POST['edd'] ?? '');
    $baby_name       = isset($_POST['baby_name'])       ? trim($_POST['baby_name'])       : '';
    $mykid           = isset($_POST['mykid'])           ? trim($_POST['mykid'])           : '';
    $dob_raw         = isset($_POST['dob'])             ? trim($_POST['dob'])             : '';
    $gender          = isset($_POST['gender'])          ? trim($_POST['gender'])          : '';
    $remarks         = isset($_POST['remarks'])         ? trim($_POST['remarks'])         : '';
    $is_delivered    = isset($_POST['is_delivered'])   ? (int)$_POST['is_delivered']      : 0;
    $dob_val         = ($dob_raw != '') ? $dob_raw : null;
    $timestamp       = date('Y-m-d H:i:s');

    $benefit_mama_book    = isset($_POST['benefit_mama_book']) ? 1 : 0;
    $benefit_50_off       = isset($_POST['benefit_50_off'])    ? 1 : 0;
    $receipt_no                  = isset($_POST['receipt_no']) ? trim($_POST['receipt_no']) : '';
    $receipt_price_mismatch      = isset($_POST['receipt_price_mismatch']) ? trim($_POST['receipt_price_mismatch']) : '';
    $vd_receipt_no               = isset($_POST['vd_receipt_no']) ? trim($_POST['vd_receipt_no']) : '';
    $_50off_arr   = (isset($_POST['benefit_50_off_items']) && is_array($_POST['benefit_50_off_items'])) ? array_map('trim', $_POST['benefit_50_off_items']) : array();
    $_50off_names = (isset($_POST['benefit_50_off_names']) && is_array($_POST['benefit_50_off_names'])) ? $_POST['benefit_50_off_names'] : array();
    mamabe_auto_insert_skus($conn, 1, $_50off_arr, $_50off_names);
    $benefit_50_off_items = implode(',', $_50off_arr);
    $now = date('Y-m-d H:i:s');
    $benefit_mama_book_at = $benefit_mama_book ? $now : null;
    $benefit_50_off_at    = $benefit_50_off    ? $now : null;
    list($benefit_free_items, $benefit_free_items_at) = mamabe_merge_free_items('benefit_free_items', '', null, $now, false);
    $pink_book_path = mamabe_nas_upload_file('pink_book', 'pink_book', 'baby_' . $id);

    $new_preg_filled_by = isset($nama_staff) ? $nama_staff : '';
    $new_preg_filled_at = date('Y-m-d H:i:s');

    if (!$lmp || !$edd) {
        $msg      = 'First Menstrual Date and EDD are required.';
        $msg_type = 'danger';
    } else {
        $_empty_baby_mismatch = '';
        $_empty_baby_free_items = '';
        $stmt = $conn->prepare("
            INSERT INTO mamabe_baby (mama_id, lmp, edd, baby_name, mykid, dob, gender, remarks,
                                    is_delivered, recycle, timestamp,
                                    pink_book, benefit_mama_book, benefit_50_off,
                                    benefit_mama_book_at, benefit_50_off_at,
                                    benefit_50_off_items, receipt_no, receipt_price_mismatch, vd_receipt_no,
                                    benefit_free_items, benefit_free_items_at,
                                    benefit_free_items_baby,
                                    baby_receipt_price_mismatch, mismatch_remark, baby_mismatch_remark,
                                    preg_filled_by, preg_filled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssssissiissssssssssssss",
            $id, $lmp, $edd, $baby_name, $mykid, $dob_val, $gender, $remarks,
            $is_delivered, $timestamp,
            $pink_book_path, $benefit_mama_book, $benefit_50_off,
            $benefit_mama_book_at, $benefit_50_off_at,
            $benefit_50_off_items, $receipt_no, $receipt_price_mismatch, $vd_receipt_no,
            $benefit_free_items, $benefit_free_items_at,
            $_empty_baby_free_items,
            $_empty_baby_mismatch,
            $_empty_baby_mismatch, $_empty_baby_mismatch,
            $new_preg_filled_by, $new_preg_filled_at
        );
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: mamabe_mama_detail.php?id=' . $id . '&added=1');
            exit;
        } else {
            $msg      = 'Database error. Please try again.';
            $msg_type = 'danger';
        }
        $stmt->close();
    }
}

// --- Update Baby ---
if (isset($_POST['update_baby'])) {
    $baby_id         = (int)($_POST['baby_id'] ?? 0);
    $lmp             = trim($_POST['lmp'] ?? '');
    $edd             = trim($_POST['edd'] ?? '');
    $baby_name       = trim($_POST['baby_name'] ?? '');
    $mykid           = trim($_POST['mykid'] ?? '');
    $dob             = trim($_POST['dob'] ?? '');
    $gender          = trim($_POST['gender'] ?? '');
    $remarks         = trim($_POST['remarks'] ?? '');
    $is_delivered    = isset($_POST['is_delivered']) ? (int)$_POST['is_delivered'] : 0;
    $miscarriage_cause = isset($_POST['miscarriage_cause']) ? trim($_POST['miscarriage_cause']) : '';
    $miscarriage_date  = isset($_POST['miscarriage_date']) ? trim($_POST['miscarriage_date']) : '';
    $miscarriage_time  = isset($_POST['miscarriage_time']) ? trim($_POST['miscarriage_time']) : '';
    $sensitivity       = (isset($_POST['sensitivity']) && is_array($_POST['sensitivity']))
                         ? implode(',', array_map('trim', $_POST['sensitivity'])) : '';

    // Benefits fields (Pregnancy)
    $upd_benefit_mama_book    = isset($_POST['benefit_mama_book']) ? 1 : 0;
    $upd_benefit_50_off       = isset($_POST['benefit_50_off'])    ? 1 : 0;
    $upd_receipt_no                = isset($_POST['receipt_no']) ? trim($_POST['receipt_no']) : '';
    $upd_receipt_price_mismatch    = isset($_POST['receipt_price_mismatch']) ? trim($_POST['receipt_price_mismatch']) : '';
    $upd_vd_receipt_no             = isset($_POST['vd_receipt_no']) ? trim($_POST['vd_receipt_no']) : '';
    $_upd_50off_arr   = (isset($_POST['benefit_50_off_items']) && is_array($_POST['benefit_50_off_items'])) ? array_map('trim', $_POST['benefit_50_off_items']) : array();
    $_upd_50off_names = (isset($_POST['benefit_50_off_names']) && is_array($_POST['benefit_50_off_names'])) ? $_POST['benefit_50_off_names'] : array();
    mamabe_auto_insert_skus($conn, 1, $_upd_50off_arr, $_upd_50off_names);
    $upd_benefit_50_off_items = implode(',', $_upd_50off_arr);

    // Benefits fields (Baby)
    $upd_benefit_baby_book         = isset($_POST['benefit_baby_book']) ? 1 : 0;
    $upd_benefit_50_off_baby       = isset($_POST['benefit_50_off_baby']) ? 1 : 0;
    $upd_baby_receipt_no           = isset($_POST['baby_receipt_no']) ? trim($_POST['baby_receipt_no']) : '';
    $_upd_baby_arr   = (isset($_POST['benefit_50_off_baby_items']) && is_array($_POST['benefit_50_off_baby_items'])) ? array_map('trim', $_POST['benefit_50_off_baby_items']) : array();
    $_upd_baby_names = (isset($_POST['benefit_50_off_baby_names']) && is_array($_POST['benefit_50_off_baby_names'])) ? $_POST['benefit_50_off_baby_names'] : array();
    mamabe_auto_insert_skus($conn, 2, $_upd_baby_arr, $_upd_baby_names);
    $upd_benefit_50_off_baby_items = implode(',', $_upd_baby_arr);

    // Load existing baby row to preserve benefit timestamps
    $bx = $conn->prepare("SELECT * FROM mamabe_baby WHERE id=? AND mama_id=? LIMIT 1");
    $bx->bind_param("ii", $baby_id, $id);
    $bx->execute();
    $bxrow = $bx->get_result()->fetch_assoc();
    $bx->close();
    $now_ts = date('Y-m-d H:i:s');
    $upd_mama_book_at  = $upd_benefit_mama_book
        ? ((!empty($bxrow['benefit_mama_book_at']) && $bxrow['benefit_mama_book_at'] !== '0000-00-00 00:00:00') ? $bxrow['benefit_mama_book_at'] : $now_ts) : null;
    $upd_50_off_at     = $upd_benefit_50_off
        ? ((!empty($bxrow['benefit_50_off_at']) && $bxrow['benefit_50_off_at'] !== '0000-00-00 00:00:00') ? $bxrow['benefit_50_off_at'] : $now_ts) : null;

    $lck_free_mama = $_admin_override ? false : (!empty($bxrow['benefit_free_items_at']) && $bxrow['benefit_free_items_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($bxrow['benefit_free_items_at'])) >= 86400);
    list($upd_benefit_free_items, $upd_free_items_at) = mamabe_merge_free_items('benefit_free_items', isset($bxrow['benefit_free_items']) ? $bxrow['benefit_free_items'] : '', isset($bxrow['benefit_free_items_at']) ? $bxrow['benefit_free_items_at'] : null, $now_ts, $lck_free_mama);

    $upd_baby_book_at  = $upd_benefit_baby_book
        ? ((!empty($bxrow['benefit_baby_book_at']) && $bxrow['benefit_baby_book_at'] !== '0000-00-00 00:00:00') ? $bxrow['benefit_baby_book_at'] : $now_ts) : null;
    $lck_free_baby = $_admin_override ? false : (!empty($bxrow['benefit_free_items_baby_at']) && $bxrow['benefit_free_items_baby_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($bxrow['benefit_free_items_baby_at'])) >= 86400);
    list($upd_benefit_free_items_baby, $upd_free_items_baby_at) = mamabe_merge_free_items('benefit_free_items_baby', isset($bxrow['benefit_free_items_baby']) ? $bxrow['benefit_free_items_baby'] : '', isset($bxrow['benefit_free_items_baby_at']) ? $bxrow['benefit_free_items_baby_at'] : null, $now_ts, $lck_free_baby);
    $upd_50_off_baby_at     = $upd_benefit_50_off_baby
        ? ((!empty($bxrow['benefit_50_off_baby_at']) && $bxrow['benefit_50_off_baby_at'] !== '0000-00-00 00:00:00') ? $bxrow['benefit_50_off_baby_at'] : $now_ts) : null;

    // Track who first filled pregnancy section — preserve if already set (must run after $bxrow loaded)
    $lmp_val = trim(isset($_POST['lmp']) ? $_POST['lmp'] : '');
    $edd_val = trim(isset($_POST['edd']) ? $_POST['edd'] : '');
    if (!empty($bxrow['preg_filled_by'])) {
        $preg_filled_by = $bxrow['preg_filled_by'];
        $preg_filled_at = $bxrow['preg_filled_at'];
    } elseif ($lmp_val !== '' || $edd_val !== '') {
        $preg_filled_by = isset($nama_staff) ? $nama_staff : '';
        $preg_filled_at = $now_ts;
    } else {
        $preg_filled_by = '';
        $preg_filled_at = null;
    }

    // Track who first filled baby status section — preserve if already set
    $_baby_name_val = trim(isset($_POST['baby_name']) ? $_POST['baby_name'] : '');
    $_is_del_val    = isset($_POST['is_delivered']) ? (int)$_POST['is_delivered'] : 0;
    if (!empty($bxrow['baby_filled_by'])) {
        $baby_update_filled_by = $bxrow['baby_filled_by'];
        $baby_update_filled_at = $bxrow['baby_filled_at'];
    } elseif ($_is_del_val > 0 || $_baby_name_val !== '') {
        $baby_update_filled_by = isset($nama_staff) ? $nama_staff : '';
        $baby_update_filled_at = $now_ts;
    } else {
        $baby_update_filled_by = '';
        $baby_update_filled_at = null;
    }

    $lck_pb = $_admin_override ? false : (!empty($bxrow['benefit_mama_book_at']) && $bxrow['benefit_mama_book_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($bxrow['benefit_mama_book_at'])) >= 86400);
    $upd_pink_book = isset($bxrow['pink_book']) ? $bxrow['pink_book'] : '';
    if (!$lck_pb) {
        $_new_pb = mamabe_nas_upload_file('pink_book', 'pink_book', 'baby_' . $baby_id);
        if ($_new_pb !== '') { $upd_pink_book = $_new_pb; }
    }

    $lck_bhb = $_admin_override ? false : (!empty($bxrow['benefit_baby_book_at']) && $bxrow['benefit_baby_book_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($bxrow['benefit_baby_book_at'])) >= 86400);
    $upd_baby_health_book = isset($bxrow['baby_health_book']) ? $bxrow['baby_health_book'] : '';
    if (!$lck_bhb) {
        $_new_bhb = mamabe_nas_upload_file('baby_health_book', 'baby_health_book', 'bhb_' . $baby_id);
        if ($_new_bhb !== '') { $upd_baby_health_book = $_new_bhb; }
    }

    $dob_val = ($dob !== '') ? $dob : NULL;

    // Prepare with nullable dob handling
    if ($dob_val !== NULL) {
        $stmt = $conn->prepare("
            UPDATE mamabe_baby
            SET lmp=?, edd=?, baby_name=?, mykid=?, dob=?, gender=?, remarks=?,
                is_delivered=?, miscarriage_cause=?, miscarriage_date=?, miscarriage_time=?,
                sensitivity=?,
                pink_book=?, benefit_mama_book=?, benefit_50_off=?,
                benefit_mama_book_at=?, benefit_50_off_at=?,
                benefit_50_off_items=?, receipt_no=?, vd_receipt_no=?,
                benefit_free_items=?, benefit_free_items_at=?,
                baby_health_book=?, benefit_baby_book=?, benefit_50_off_baby=?,
                benefit_baby_book_at=?, benefit_50_off_baby_at=?,
                benefit_free_items_baby=?, benefit_free_items_baby_at=?,
                benefit_50_off_baby_items=?, baby_receipt_no=?,
                preg_filled_by=?, preg_filled_at=?,
                baby_filled_by=?, baby_filled_at=?
            WHERE id=? AND mama_id=?
        ");
        $stmt->bind_param("sssssssisssssiissssssssiissssssssssii",
            $lmp, $edd, $baby_name, $mykid, $dob_val, $gender, $remarks,
            $is_delivered, $miscarriage_cause, $miscarriage_date, $miscarriage_time,
            $sensitivity,
            $upd_pink_book, $upd_benefit_mama_book, $upd_benefit_50_off,
            $upd_mama_book_at, $upd_50_off_at,
            $upd_benefit_50_off_items, $upd_receipt_no, $upd_vd_receipt_no,
            $upd_benefit_free_items, $upd_free_items_at,
            $upd_baby_health_book, $upd_benefit_baby_book, $upd_benefit_50_off_baby,
            $upd_baby_book_at, $upd_50_off_baby_at,
            $upd_benefit_free_items_baby, $upd_free_items_baby_at,
            $upd_benefit_50_off_baby_items, $upd_baby_receipt_no,
            $preg_filled_by, $preg_filled_at,
            $baby_update_filled_by, $baby_update_filled_at,
            $baby_id, $id
        );
    } else {
        $stmt = $conn->prepare("
            UPDATE mamabe_baby
            SET lmp=?, edd=?, baby_name=?, mykid=?, dob=NULL, gender=?, remarks=?,
                is_delivered=?, miscarriage_cause=?, miscarriage_date=?, miscarriage_time=?,
                sensitivity=?,
                pink_book=?, benefit_mama_book=?, benefit_50_off=?,
                benefit_mama_book_at=?, benefit_50_off_at=?,
                benefit_50_off_items=?, receipt_no=?, vd_receipt_no=?,
                benefit_free_items=?, benefit_free_items_at=?,
                baby_health_book=?, benefit_baby_book=?, benefit_50_off_baby=?,
                benefit_baby_book_at=?, benefit_50_off_baby_at=?,
                benefit_free_items_baby=?, benefit_free_items_baby_at=?,
                benefit_50_off_baby_items=?, baby_receipt_no=?,
                preg_filled_by=?, preg_filled_at=?,
                baby_filled_by=?, baby_filled_at=?
            WHERE id=? AND mama_id=?
        ");
        $stmt->bind_param("ssssssisssssiissssssssiissssssssssii",
            $lmp, $edd, $baby_name, $mykid, $gender, $remarks,
            $is_delivered, $miscarriage_cause, $miscarriage_date, $miscarriage_time,
            $sensitivity,
            $upd_pink_book, $upd_benefit_mama_book, $upd_benefit_50_off,
            $upd_mama_book_at, $upd_50_off_at,
            $upd_benefit_50_off_items, $upd_receipt_no, $upd_vd_receipt_no,
            $upd_benefit_free_items, $upd_free_items_at,
            $upd_baby_health_book, $upd_benefit_baby_book, $upd_benefit_50_off_baby,
            $upd_baby_book_at, $upd_50_off_baby_at,
            $upd_benefit_free_items_baby, $upd_free_items_baby_at,
            $upd_benefit_50_off_baby_items, $upd_baby_receipt_no,
            $preg_filled_by, $preg_filled_at,
            $baby_update_filled_by, $baby_update_filled_at,
            $baby_id, $id
        );
    }

    if ($stmt->execute()) {
        $stmt->close();

        // --- Insert or Update twin ---
        if (isset($_POST['add_twin']) && $_POST['add_twin'] == '1') {
            $tw_name      = isset($_POST['twin_baby_name'])        ? trim($_POST['twin_baby_name'])       : '';
            $tw_mykid     = isset($_POST['twin_mykid'])            ? trim($_POST['twin_mykid'])           : '';
            $tw_dob_raw   = isset($_POST['twin_dob'])              ? trim($_POST['twin_dob'])             : '';
            $tw_gender    = isset($_POST['twin_gender'])           ? trim($_POST['twin_gender'])          : '';
            $tw_remarks   = isset($_POST['twin_remarks'])          ? trim($_POST['twin_remarks'])         : '';
            $tw_delivered = isset($_POST['twin_is_delivered'])     ? (int)$_POST['twin_is_delivered']     : 0;
            $tw_dob       = ($tw_dob_raw != '') ? $tw_dob_raw : null;
            $tw_misc_dt   = isset($_POST['twin_miscarriage_date']) ? trim($_POST['twin_miscarriage_date']) : '';
            $tw_misc_tm   = isset($_POST['twin_miscarriage_time']) ? trim($_POST['twin_miscarriage_time']) : '';
            $tw_sens      = (isset($_POST['twin_sensitivity']) && is_array($_POST['twin_sensitivity'])) ? implode(',', array_map('trim', $_POST['twin_sensitivity'])) : '';

            $tw_benefit_baby_book         = isset($_POST['twin_benefit_baby_book'])    ? 1 : 0;
            $tw_benefit_50_off_baby       = isset($_POST['twin_benefit_50_off_baby'])  ? 1 : 0;
            $_tw_baby_arr   = (isset($_POST['twin_benefit_50_off_baby_items']) && is_array($_POST['twin_benefit_50_off_baby_items'])) ? array_map('trim', $_POST['twin_benefit_50_off_baby_items']) : array();
            $_tw_baby_names = (isset($_POST['twin_benefit_50_off_baby_names']) && is_array($_POST['twin_benefit_50_off_baby_names'])) ? $_POST['twin_benefit_50_off_baby_names'] : array();
            mamabe_auto_insert_skus($conn, 2, $_tw_baby_arr, $_tw_baby_names);
            $tw_benefit_50_off_baby_items = implode(',', $_tw_baby_arr);
            $tw_baby_receipt_no              = isset($_POST['twin_baby_receipt_no']) ? trim($_POST['twin_baby_receipt_no']) : '';
            $tw_baby_receipt_price_mismatch  = isset($_POST['twin_baby_receipt_price_mismatch']) ? trim($_POST['twin_baby_receipt_price_mismatch']) : '';

            // Check if baby already has an existing twin
            $tw_ex_res = $conn->query("SELECT twin_id FROM mamabe_baby WHERE id=" . (int)$baby_id . " LIMIT 1");
            $tw_ex_row = $tw_ex_res ? $tw_ex_res->fetch_assoc() : null;
            $existing_twin_id = ($tw_ex_row && !empty($tw_ex_row['twin_id'])) ? (int)$tw_ex_row['twin_id'] : 0;

            if ($existing_twin_id > 0) {
                // --- UPDATE existing twin ---
                $tw_save_id = $existing_twin_id;

                // Handle health book upload
                $tw_bhb_res = $conn->query("SELECT baby_health_book FROM mamabe_baby WHERE id=$tw_save_id LIMIT 1");
                $tw_bhb_row = $tw_bhb_res ? $tw_bhb_res->fetch_assoc() : null;
                $tw_baby_health_book = ($tw_bhb_row && !empty($tw_bhb_row['baby_health_book'])) ? $tw_bhb_row['baby_health_book'] : '';
                $_new_tw_bhb = mamabe_nas_upload_file('twin_baby_health_book', 'baby_health_book', 'bhb_tw_' . $tw_save_id);
                if ($_new_tw_bhb !== '') { $tw_baby_health_book = $_new_tw_bhb; }

                // Preserve benefit timestamps
                $tw_cur = $conn->query("SELECT benefit_baby_book_at, benefit_free_items_baby, benefit_free_items_baby_at, benefit_50_off_baby_at FROM mamabe_baby WHERE id=$tw_save_id LIMIT 1");
                $tw_cur_row = $tw_cur ? $tw_cur->fetch_assoc() : null;
                $tw_baby_book_at     = $tw_benefit_baby_book     ? ((!empty($tw_cur_row['benefit_baby_book_at'])    && $tw_cur_row['benefit_baby_book_at']    !== '0000-00-00 00:00:00') ? $tw_cur_row['benefit_baby_book_at']    : $now_ts) : null;
                $tw_50_off_baby_at   = $tw_benefit_50_off_baby   ? ((!empty($tw_cur_row['benefit_50_off_baby_at'])  && $tw_cur_row['benefit_50_off_baby_at']   !== '0000-00-00 00:00:00') ? $tw_cur_row['benefit_50_off_baby_at']   : $now_ts) : null;
                $lck_tw_free_baby = $_admin_override ? false : (!empty($tw_cur_row['benefit_free_items_baby_at']) && $tw_cur_row['benefit_free_items_baby_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($tw_cur_row['benefit_free_items_baby_at'])) >= 86400);
                list($tw_benefit_free_items_baby, $tw_free_items_baby_at) = mamabe_merge_free_items('twin_benefit_free_items_baby', isset($tw_cur_row['benefit_free_items_baby']) ? $tw_cur_row['benefit_free_items_baby'] : '', isset($tw_cur_row['benefit_free_items_baby_at']) ? $tw_cur_row['benefit_free_items_baby_at'] : null, $now_ts, $lck_tw_free_baby);

                if ($tw_dob !== null) {
                    $tu = $conn->prepare("UPDATE mamabe_baby SET lmp=?, edd=?, pink_book=?, benefit_mama_book=?, benefit_50_off=?, benefit_mama_book_at=?, benefit_50_off_at=?, benefit_50_off_items=?, receipt_no=?, receipt_price_mismatch=?, benefit_free_items=?, benefit_free_items_at=?, baby_name=?, mykid=?, dob=?, gender=?, remarks=?, is_delivered=?, miscarriage_date=?, miscarriage_time=?, sensitivity=?, baby_health_book=?, benefit_baby_book=?, benefit_50_off_baby=?, benefit_baby_book_at=?, benefit_50_off_baby_at=?, benefit_free_items_baby=?, benefit_free_items_baby_at=?, benefit_50_off_baby_items=?, baby_receipt_no=?, baby_receipt_price_mismatch=? WHERE id=? AND mama_id=?");
                    $tu->bind_param("sssiissssssssssssissssiisssssssii", $lmp, $edd, $upd_pink_book, $upd_benefit_mama_book, $upd_benefit_50_off, $upd_mama_book_at, $upd_50_off_at, $upd_benefit_50_off_items, $upd_receipt_no, $upd_receipt_price_mismatch, $upd_benefit_free_items, $upd_free_items_at, $tw_name, $tw_mykid, $tw_dob, $tw_gender, $tw_remarks, $tw_delivered, $tw_misc_dt, $tw_misc_tm, $tw_sens, $tw_baby_health_book, $tw_benefit_baby_book, $tw_benefit_50_off_baby, $tw_baby_book_at, $tw_50_off_baby_at, $tw_benefit_free_items_baby, $tw_free_items_baby_at, $tw_benefit_50_off_baby_items, $tw_baby_receipt_no, $tw_baby_receipt_price_mismatch, $tw_save_id, $id);
                } else {
                    $tu = $conn->prepare("UPDATE mamabe_baby SET lmp=?, edd=?, pink_book=?, benefit_mama_book=?, benefit_50_off=?, benefit_mama_book_at=?, benefit_50_off_at=?, benefit_50_off_items=?, receipt_no=?, receipt_price_mismatch=?, benefit_free_items=?, benefit_free_items_at=?, baby_name=?, mykid=?, dob=NULL, gender=?, remarks=?, is_delivered=?, miscarriage_date=?, miscarriage_time=?, sensitivity=?, baby_health_book=?, benefit_baby_book=?, benefit_50_off_baby=?, benefit_baby_book_at=?, benefit_50_off_baby_at=?, benefit_free_items_baby=?, benefit_free_items_baby_at=?, benefit_50_off_baby_items=?, baby_receipt_no=?, baby_receipt_price_mismatch=? WHERE id=? AND mama_id=?");
                    $tu->bind_param("sssiisssssssssssissssiisssssssii", $lmp, $edd, $upd_pink_book, $upd_benefit_mama_book, $upd_benefit_50_off, $upd_mama_book_at, $upd_50_off_at, $upd_benefit_50_off_items, $upd_receipt_no, $upd_receipt_price_mismatch, $upd_benefit_free_items, $upd_free_items_at, $tw_name, $tw_mykid, $tw_gender, $tw_remarks, $tw_delivered, $tw_misc_dt, $tw_misc_tm, $tw_sens, $tw_baby_health_book, $tw_benefit_baby_book, $tw_benefit_50_off_baby, $tw_baby_book_at, $tw_50_off_baby_at, $tw_benefit_free_items_baby, $tw_free_items_baby_at, $tw_benefit_50_off_baby_items, $tw_baby_receipt_no, $tw_baby_receipt_price_mismatch, $tw_save_id, $id);
                }
                $tu->execute();
                $tu->close();

            } else {
                // --- INSERT new twin ---
                $tw_ts = date('Y-m-d H:i:s');
                $tw_baby_book_at    = $tw_benefit_baby_book    ? $now_ts : null;
                $tw_50_off_baby_at   = $tw_benefit_50_off_baby   ? $now_ts : null;
                list($tw_benefit_free_items_baby, $tw_free_items_baby_at) = mamabe_merge_free_items('twin_benefit_free_items_baby', '', null, $now_ts, false);
                $tw_baby_health_book = '';

                // We need to insert first to get the ID for the health book upload name
                // So we'll run the INSERT without the actual file, then UPDATE if there is a file.
                $_empty_tw_mama_mismatch = '';
                $_empty_tw_baby_mismatch = '';
                $ts = $conn->prepare("
                    INSERT INTO mamabe_baby (mama_id, lmp, edd, baby_name, mykid, dob, gender, remarks,
                                            is_delivered, miscarriage_date, miscarriage_time, sensitivity, recycle, timestamp,
                                            pink_book, benefit_mama_book, benefit_50_off,
                                            benefit_mama_book_at, benefit_50_off_at,
                                            benefit_50_off_items, receipt_no, receipt_price_mismatch,
                                            benefit_free_items, benefit_free_items_at,
                                            baby_health_book, benefit_baby_book, benefit_50_off_baby,
                                            benefit_baby_book_at, benefit_50_off_baby_at,
                                            benefit_free_items_baby, benefit_free_items_baby_at,
                                            benefit_50_off_baby_items, baby_receipt_no, baby_receipt_price_mismatch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ts->bind_param("isssssssisssssiissssssssiisssssss", $id, $lmp, $edd, $tw_name, $tw_mykid, $tw_dob, $tw_gender, $tw_remarks, $tw_delivered, $tw_misc_dt, $tw_misc_tm, $tw_sens, $tw_ts, $upd_pink_book, $upd_benefit_mama_book, $upd_benefit_50_off, $upd_mama_book_at, $upd_50_off_at, $upd_benefit_50_off_items, $upd_receipt_no, $_empty_tw_mama_mismatch, $upd_benefit_free_items, $upd_free_items_at, $tw_baby_health_book, $tw_benefit_baby_book, $tw_benefit_50_off_baby, $tw_baby_book_at, $tw_50_off_baby_at, $tw_benefit_free_items_baby, $tw_free_items_baby_at, $tw_benefit_50_off_baby_items, $tw_baby_receipt_no, $_empty_tw_baby_mismatch);
                $ts->execute();
                $tw_new_id = $conn->insert_id;
                $ts->close();

                // Link both babies to each other
                if ($tw_new_id) {
                    $conn->query("UPDATE mamabe_baby SET twin_id=" . (int)$tw_new_id . " WHERE id=" . (int)$baby_id);
                    $conn->query("UPDATE mamabe_baby SET twin_id=" . (int)$baby_id   . " WHERE id=" . (int)$tw_new_id);
                }

                // Upload health book if provided
                $_new_tw_bhb2 = mamabe_nas_upload_file('twin_baby_health_book', 'baby_health_book', 'bhb_tw_' . $tw_new_id);
                if ($_new_tw_bhb2 !== '') { $tw_baby_health_book = $_new_tw_bhb2; }
                if ($tw_new_id) {
                    $tb = $conn->prepare("
                        UPDATE mamabe_baby
                        SET baby_health_book=?, benefit_baby_book=?, benefit_50_off_baby=?,
                            benefit_baby_book_at=?, benefit_50_off_baby_at=?, benefit_free_items_baby=?, benefit_free_items_baby_at=?, benefit_50_off_baby_items=?, baby_receipt_no=?
                        WHERE id=? AND mama_id=?
                    ");
                    $tb->bind_param("siissssssii",
                        $tw_baby_health_book, $tw_benefit_baby_book, $tw_benefit_50_off_baby,
                        $tw_baby_book_at, $tw_50_off_baby_at, $tw_benefit_free_items_baby, $tw_free_items_baby_at, $tw_benefit_50_off_baby_items, $tw_baby_receipt_no,
                        $tw_new_id, $id
                    );
                    $tb->execute();
                    $tb->close();
                }
            }
        }

        // Save benefit fields (redundant re-save after the main UPDATE above;
        // reuses the same already-computed merge results, not recomputed)
        $bs = $conn->prepare("
            UPDATE mamabe_baby
            SET benefit_mama_book=?, benefit_50_off=?,
                benefit_mama_book_at=?, benefit_50_off_at=?,
                benefit_50_off_items=?, receipt_no=?, receipt_price_mismatch=?, pink_book=?,
                benefit_free_items=?, benefit_free_items_at=?
            WHERE id=? AND mama_id=?
        ");
        $bs->bind_param("iissssssssii",
            $upd_benefit_mama_book, $upd_benefit_50_off,
            $upd_mama_book_at, $upd_50_off_at,
            $upd_benefit_50_off_items, $upd_receipt_no, $upd_receipt_price_mismatch, $upd_pink_book,
            $upd_benefit_free_items, $upd_free_items_at,
            $baby_id, $id
        );
        $bs->execute();
        $bs->close();

        // Save baby delivery benefit fields
        $upd_benefit_baby_book          = isset($_POST['benefit_baby_book'])    ? 1 : 0;
        $upd_benefit_50_off_baby        = isset($_POST['benefit_50_off_baby'])  ? 1 : 0;
        $upd_baby_receipt_no            = isset($_POST['baby_receipt_no']) ? trim($_POST['baby_receipt_no']) : '';
        $upd_baby_receipt_price_mismatch = isset($_POST['baby_receipt_price_mismatch']) ? trim($_POST['baby_receipt_price_mismatch']) : '';
        $upd_baby_book_at = $upd_benefit_baby_book
            ? ((!empty($bxrow['benefit_baby_book_at']) && $bxrow['benefit_baby_book_at'] !== '0000-00-00 00:00:00') ? $bxrow['benefit_baby_book_at'] : $now_ts) : null;
        $upd_50_off_baby_at = $upd_benefit_50_off_baby
            ? ((!empty($bxrow['benefit_50_off_baby_at']) && $bxrow['benefit_50_off_baby_at'] !== '0000-00-00 00:00:00') ? $bxrow['benefit_50_off_baby_at'] : $now_ts) : null;
        $bd = $conn->prepare("
            UPDATE mamabe_baby
            SET baby_health_book=?, benefit_baby_book=?, benefit_50_off_baby=?,
                benefit_baby_book_at=?, benefit_50_off_baby_at=?, baby_receipt_no=?, baby_receipt_price_mismatch=?,
                benefit_free_items_baby=?, benefit_free_items_baby_at=?
            WHERE id=? AND mama_id=?
        ");
        $bd->bind_param("siissssssii",
            $upd_baby_health_book, $upd_benefit_baby_book, $upd_benefit_50_off_baby,
            $upd_baby_book_at, $upd_50_off_baby_at, $upd_baby_receipt_no, $upd_baby_receipt_price_mismatch,
            $upd_benefit_free_items_baby, $upd_free_items_baby_at,
            $baby_id, $id
        );
        $bd->execute();
        $bd->close();

        header('Location: mamabe_mama_detail.php?id=' . $id . '&updated=1');
        exit;
    } else {
        $msg      = 'Database error: ' . $conn->error;
        $msg_type = 'danger';
        $stmt->close();
    }
}

// --- Save Benefits ---
if (isset($_POST['save_benefits'])) {
    $benefit_mama_book   = isset($_POST['benefit_mama_book']) ? 1 : 0;
    $benefit_vitamin_d   = (isset($_POST['benefit_vitamin_d']) && $_POST['benefit_vitamin_d'] == '1') ? 1 : 0;
    $benefit_50_off      = isset($_POST['benefit_50_off'])    ? 1 : 0;
    $receipt_no             = trim($_POST['receipt_no'] ?? '');
    $receipt_price_mismatch = isset($_POST['receipt_price_mismatch']) ? trim($_POST['receipt_price_mismatch']) : '';
    $_sb_arr   = (isset($_POST['benefit_50_off_items']) && is_array($_POST['benefit_50_off_items'])) ? array_map('trim', $_POST['benefit_50_off_items']) : array();
    $_sb_names = (isset($_POST['benefit_50_off_names']) && is_array($_POST['benefit_50_off_names'])) ? $_POST['benefit_50_off_names'] : array();
    mamabe_auto_insert_skus($conn, 1, $_sb_arr, $_sb_names);
    $benefit_50_off_items = implode(',', $_sb_arr);
    $pink_book_path    = isset($mama['pink_book']) ? $mama['pink_book'] : '';

    if (isset($_FILES['pink_book']) && $_FILES['pink_book']['error'] == 0) {
        $_new_pb2 = mamabe_nas_upload_file('pink_book', 'pink_book', 'mama_' . $id);
        if ($_new_pb2 !== '') {
            $pink_book_path = $_new_pb2;
        } else {
            $msg      = 'Only JPG, PNG or PDF allowed for pink book.';
            $msg_type = 'danger';
        }
    }

    if ($msg_type !== 'danger') {
        $now = date('Y-m-d H:i:s');
        $benefit_mama_book_at = $benefit_mama_book ? (empty($mama['benefit_mama_book_at']) ? $now : $mama['benefit_mama_book_at']) : null;
        $benefit_vitamin_d_at = $benefit_vitamin_d ? (empty($mama['benefit_vitamin_d_at']) ? $now : $mama['benefit_vitamin_d_at']) : null;
        $benefit_50_off_at    = $benefit_50_off    ? (empty($mama['benefit_50_off_at'])    ? $now : $mama['benefit_50_off_at'])    : null;

        $stmt = $conn->prepare("UPDATE mamabe_mama SET benefit_mama_book=?, benefit_vitamin_d=?, benefit_50_off=?, receipt_no=?, receipt_price_mismatch=?, pink_book=?, benefit_mama_book_at=?, benefit_vitamin_d_at=?, benefit_50_off_at=?, benefit_50_off_items=? WHERE id=?");
        $stmt->bind_param("iiisssssssi", $benefit_mama_book, $benefit_vitamin_d, $benefit_50_off, $receipt_no, $receipt_price_mismatch, $pink_book_path, $benefit_mama_book_at, $benefit_vitamin_d_at, $benefit_50_off_at, $benefit_50_off_items, $id);
        if ($stmt->execute()) {
            $msg      = 'Benefits saved.';
            $msg_type = 'success';
            $r = $conn->prepare("SELECT m.*, o.comp_name AS outlet_name FROM mamabe_mama m LEFT JOIN outlet o ON m.outlet_id=o.id WHERE m.id=? AND m.recycle=0 LIMIT 1");
            $r->bind_param("i", $id);
            $r->execute();
            $mama = $r->get_result()->fetch_assoc();
            $r->close();
        } else {
            $msg      = 'Error: ' . $conn->error;
            $msg_type = 'danger';
        }
        $stmt->close();
    }
}

// --- Save Baby Benefits ---
if (isset($_POST['save_baby_benefits'])) {
    $baby_id = (int)$_POST['baby_id'];

    // Load current baby row to preserve existing timestamps and check 24h lock
    $br2 = $conn->prepare("SELECT * FROM mamabe_baby WHERE id=? AND mama_id=? LIMIT 1");
    $br2->bind_param("ii", $baby_id, $id);
    $br2->execute();
    $brow = $br2->get_result()->fetch_assoc();
    $br2->close();

    $now_time = time();
    $lck_mb = $_admin_override ? false : (!empty($brow['benefit_mama_book_at']) && $brow['benefit_mama_book_at'] !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow['benefit_mama_book_at'])) >= 86400);
    $lck_vd = $_admin_override ? false : (!empty($brow['benefit_vitamin_d_at']) && $brow['benefit_vitamin_d_at'] !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow['benefit_vitamin_d_at'])) >= 86400);
    $lck_50 = $_admin_override ? false : (!empty($brow['benefit_50_off_at'])    && $brow['benefit_50_off_at']    !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow['benefit_50_off_at']))    >= 86400);

    $benefit_mama_book    = $lck_mb ? $brow['benefit_mama_book'] : (isset($_POST['benefit_mama_book']) ? 1 : 0);
    $benefit_vitamin_d    = $lck_vd ? $brow['benefit_vitamin_d'] : ((isset($_POST['benefit_vitamin_d']) && $_POST['benefit_vitamin_d'] == '1') ? 1 : 0);
    $benefit_50_off       = $lck_50 ? $brow['benefit_50_off']    : (isset($_POST['benefit_50_off'])    ? 1 : 0);
    $receipt_no             = trim($_POST['receipt_no'] ?? '');
    $receipt_price_mismatch = isset($_POST['receipt_price_mismatch']) ? trim($_POST['receipt_price_mismatch']) : '';
    $vd_receipt_no          = isset($_POST['vd_receipt_no']) ? trim($_POST['vd_receipt_no']) : (isset($brow['vd_receipt_no']) ? $brow['vd_receipt_no'] : '');
    if (!$lck_50 && isset($_POST['benefit_50_off_items']) && is_array($_POST['benefit_50_off_items'])) {
        $_sb2_arr   = array_map('trim', $_POST['benefit_50_off_items']);
        $_sb2_names = (isset($_POST['benefit_50_off_names']) && is_array($_POST['benefit_50_off_names'])) ? $_POST['benefit_50_off_names'] : array();
        mamabe_auto_insert_skus($conn, 1, $_sb2_arr, $_sb2_names);
        $benefit_50_off_items = implode(',', $_sb2_arr);
    } else {
        $benefit_50_off_items = $lck_50 ? $brow['benefit_50_off_items'] : '';
    }

    $now = date('Y-m-d H:i:s');
    $benefit_mama_book_at = $benefit_mama_book
        ? ((!empty($brow['benefit_mama_book_at']) && $brow['benefit_mama_book_at'] !== '0000-00-00 00:00:00') ? $brow['benefit_mama_book_at'] : $now)
        : null;
    $benefit_vitamin_d_at = $benefit_vitamin_d
        ? ((!empty($brow['benefit_vitamin_d_at']) && $brow['benefit_vitamin_d_at'] !== '0000-00-00 00:00:00') ? $brow['benefit_vitamin_d_at'] : $now)
        : null;
    $benefit_50_off_at = $benefit_50_off
        ? ((!empty($brow['benefit_50_off_at']) && $brow['benefit_50_off_at'] !== '0000-00-00 00:00:00') ? $brow['benefit_50_off_at'] : $now)
        : null;

    $pink_book_path = isset($brow['pink_book']) ? $brow['pink_book'] : '';
    if (isset($_FILES['pink_book']) && $_FILES['pink_book']['error'] == 0) {
        $_new_pb3 = mamabe_nas_upload_file('pink_book', 'pink_book', 'baby_' . $baby_id);
        if ($_new_pb3 !== '') {
            $pink_book_path = $_new_pb3;
        } else {
            $msg      = 'Only JPG, PNG or PDF allowed for pink book.';
            $msg_type = 'danger';
        }
    }

    if ($msg_type !== 'danger') {
        $stmt = $conn->prepare("
            UPDATE mamabe_baby
            SET benefit_mama_book=?, benefit_vitamin_d=?, benefit_50_off=?,
                benefit_mama_book_at=?, benefit_vitamin_d_at=?, benefit_50_off_at=?,
                benefit_50_off_items=?, receipt_no=?, receipt_price_mismatch=?, pink_book=?,
                vd_receipt_no=?
            WHERE id=? AND mama_id=?
        ");
        $stmt->bind_param("iiissssssssii",
            $benefit_mama_book, $benefit_vitamin_d, $benefit_50_off,
            $benefit_mama_book_at, $benefit_vitamin_d_at, $benefit_50_off_at,
            $benefit_50_off_items, $receipt_no, $receipt_price_mismatch, $pink_book_path,
            $vd_receipt_no,
            $baby_id, $id
        );
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: mamabe_mama_detail.php?id=' . $id . '&benefits=' . $baby_id);
            exit;
        } else {
            $msg      = 'Error: ' . $conn->error;
            $msg_type = 'danger';
        }
        $stmt->close();
    }
}

// --- Save Baby Delivery Benefits ---
if (isset($_POST['save_baby_delivery_benefits'])) {
    $baby_id = (int)$_POST['baby_id'];

    $br3 = $conn->prepare("SELECT * FROM mamabe_baby WHERE id=? AND mama_id=? LIMIT 1");
    $br3->bind_param("ii", $baby_id, $id);
    $br3->execute();
    $brow3 = $br3->get_result()->fetch_assoc();
    $br3->close();

    $now_time = time();
    $lck_bb = $_admin_override ? false : (!empty($brow3['benefit_baby_book_at'])    && $brow3['benefit_baby_book_at']    !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow3['benefit_baby_book_at']))    >= 86400);
    $lck_fd  = $_admin_override ? false : (!empty($brow3['benefit_free_diapers_at'])  && $brow3['benefit_free_diapers_at']  !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow3['benefit_free_diapers_at']))  >= 86400);
    $lck_fd2 = $_admin_override ? false : (!empty($brow3['benefit_free_diapers2_at']) && $brow3['benefit_free_diapers2_at'] !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow3['benefit_free_diapers2_at'])) >= 86400);
    $lck_50  = $_admin_override ? false : (!empty($brow3['benefit_50_off_baby_at'])   && $brow3['benefit_50_off_baby_at']   !== '0000-00-00 00:00:00' && ($now_time - strtotime($brow3['benefit_50_off_baby_at']))   >= 86400);

    $benefit_baby_book     = $lck_bb  ? $brow3['benefit_baby_book']     : (isset($_POST['benefit_baby_book'])    ? 1 : 0);
    $benefit_free_diapers  = $lck_fd  ? $brow3['benefit_free_diapers']  : ((isset($_POST['benefit_free_diapers'])  && $_POST['benefit_free_diapers']  == '1') ? 1 : 0);
    $benefit_free_diapers2 = $lck_fd2 ? $brow3['benefit_free_diapers2'] : ((isset($_POST['benefit_free_diapers2']) && $_POST['benefit_free_diapers2'] == '1') ? 1 : 0);
    $benefit_50_off_baby   = $lck_50  ? $brow3['benefit_50_off_baby']   : (isset($_POST['benefit_50_off_baby'])  ? 1 : 0);
    $baby_receipt_no              = trim($_POST['baby_receipt_no'] ?? '');
    $baby_receipt_price_mismatch  = isset($_POST['baby_receipt_price_mismatch']) ? trim($_POST['baby_receipt_price_mismatch']) : '';
    if (!$lck_50 && isset($_POST['benefit_50_off_baby_items']) && is_array($_POST['benefit_50_off_baby_items'])) {
        $_sb3_arr   = array_map('trim', $_POST['benefit_50_off_baby_items']);
        $_sb3_names = (isset($_POST['benefit_50_off_baby_names']) && is_array($_POST['benefit_50_off_baby_names'])) ? $_POST['benefit_50_off_baby_names'] : array();
        mamabe_auto_insert_skus($conn, 2, $_sb3_arr, $_sb3_names);
        $benefit_50_off_baby_items = implode(',', $_sb3_arr);
    } else {
        $benefit_50_off_baby_items = $lck_50 ? $brow3['benefit_50_off_baby_items'] : '';
    }

    $now = date('Y-m-d H:i:s');
    $benefit_baby_book_at = $benefit_baby_book
        ? ((!empty($brow3['benefit_baby_book_at']) && $brow3['benefit_baby_book_at'] !== '0000-00-00 00:00:00') ? $brow3['benefit_baby_book_at'] : $now)
        : null;
    $benefit_free_diapers_at = $benefit_free_diapers
        ? ((!empty($brow3['benefit_free_diapers_at']) && $brow3['benefit_free_diapers_at'] !== '0000-00-00 00:00:00') ? $brow3['benefit_free_diapers_at'] : $now)
        : null;
    $benefit_free_diapers2_at = $benefit_free_diapers2
        ? ((!empty($brow3['benefit_free_diapers2_at']) && $brow3['benefit_free_diapers2_at'] !== '0000-00-00 00:00:00') ? $brow3['benefit_free_diapers2_at'] : $now)
        : null;
    $benefit_50_off_baby_at = $benefit_50_off_baby
        ? ((!empty($brow3['benefit_50_off_baby_at']) && $brow3['benefit_50_off_baby_at'] !== '0000-00-00 00:00:00') ? $brow3['benefit_50_off_baby_at'] : $now)
        : null;

    $baby_health_book_path = isset($brow3['baby_health_book']) ? $brow3['baby_health_book'] : '';
    $_new_bhb3 = mamabe_nas_upload_file('baby_health_book', 'baby_health_book', 'bhb_' . $baby_id);
    if ($_new_bhb3 !== '') { $baby_health_book_path = $_new_bhb3; }

    if (!empty($brow3['baby_filled_by'])) {
        $baby_filled_by = $brow3['baby_filled_by'];
        $baby_filled_at = $brow3['baby_filled_at'];
    } else {
        $baby_filled_by = isset($nama_staff) ? $nama_staff : '';
        $baby_filled_at = date('Y-m-d H:i:s');
    }
    $stmt = $conn->prepare("
        UPDATE mamabe_baby
        SET baby_health_book=?, benefit_baby_book=?, benefit_free_diapers=?, benefit_free_diapers2=?, benefit_50_off_baby=?,
            benefit_baby_book_at=?, benefit_free_diapers_at=?, benefit_free_diapers2_at=?, benefit_50_off_baby_at=?,
            benefit_50_off_baby_items=?, baby_receipt_no=?, baby_receipt_price_mismatch=?,
            baby_filled_by=?, baby_filled_at=?
        WHERE id=? AND mama_id=?
    ");
    $stmt->bind_param("siiiissssssssii",
        $baby_health_book_path, $benefit_baby_book, $benefit_free_diapers, $benefit_free_diapers2, $benefit_50_off_baby,
        $benefit_baby_book_at, $benefit_free_diapers_at, $benefit_free_diapers2_at, $benefit_50_off_baby_at,
        $benefit_50_off_baby_items, $baby_receipt_no, $baby_receipt_price_mismatch,
        $baby_filled_by, $baby_filled_at,
        $baby_id, $id
    );
    if ($stmt->execute()) {
        $stmt->close();
        header('Location: mamabe_mama_detail.php?id=' . $id . '&baby_benefits=' . $baby_id);
        exit;
    } else {
        $msg      = 'Error: ' . $conn->error;
        $msg_type = 'danger';
        $stmt->close();
    }
}

// --- Delete Baby --- mamab_autho 1 only (not the general $_is_admin, which also allows 3 - deliberately narrower
// than the rest of this page). Any status (Expected, Delivered, Miscarriage) is deletable. Soft delete only
// (recycle=1) - the row stays in the database, just hidden, same as every other soft-delete in this app.
if (isset($_POST['delete_baby']) && isset($mamab_autho) && $mamab_autho == '1') {
    $baby_id = (int)$_POST['baby_id'];
    // Delete baby's uploaded files before soft-deleting
    $bff = $conn->prepare("SELECT pink_book, baby_health_book FROM mamabe_baby WHERE id=? AND mama_id=? LIMIT 1");
    $bff->bind_param("ii", $baby_id, $id);
    $bff->execute();
    $bff_row = $bff->get_result()->fetch_assoc();
    $bff->close();
    if (!$bff_row) {
        $msg      = 'Baby record not found.';
        $msg_type = 'danger';
    } else {
        foreach (array('pink_book', 'baby_health_book') as $fcol) {
            if (empty($bff_row[$fcol])) { continue; }
            if (strpos($bff_row[$fcol], 'uploads/') === 0 && file_exists($bff_row[$fcol])) {
                unlink($bff_row[$fcol]);
            } else {
                mamabe_nas_delete_file($bff_row[$fcol]);
            }
        }
        $stmt = $conn->prepare("UPDATE mamabe_baby SET recycle=1 WHERE id=? AND mama_id=?");
        $stmt->bind_param("ii", $baby_id, $id);
        $stmt->execute();
        $stmt->close();
        $msg      = 'Baby record removed.';
        $msg_type = 'warn';
    }
}

// --- Delete one uploaded photo (Pregnancy Record Book / Baby Health Book, including a twin's own) --- mamab_autho
// 1 only, same as Delete Baby above. $photo_baby_id is whichever mamabe_baby row the photo sits on - a twin's
// baby_health_book lives on the twin's own row, not the first baby's, so this isn't always $id's row directly, but
// it must still belong to this mama (mama_id = $id) so one mama's page can't touch another's photo.
if (isset($_POST['delete_photo']) && isset($mamab_autho) && $mamab_autho == '1') {
    $photo_baby_id = (int)($_POST['photo_baby_id'] ?? 0);
    $photo_field   = isset($_POST['photo_field']) ? (string)$_POST['photo_field'] : '';
    if ($photo_baby_id > 0 && in_array($photo_field, array('pink_book', 'baby_health_book'), true)) {
        $pf = $conn->prepare("SELECT $photo_field AS val FROM mamabe_baby WHERE id=? AND mama_id=? LIMIT 1");
        $pf->bind_param("ii", $photo_baby_id, $id);
        $pf->execute();
        $pf_row = $pf->get_result()->fetch_assoc();
        $pf->close();
        if ($pf_row && !empty($pf_row['val'])) {
            if (strpos($pf_row['val'], 'uploads/') === 0 && file_exists($pf_row['val'])) {
                unlink($pf_row['val']);
            } else {
                mamabe_nas_delete_file($pf_row['val']);
            }
            $stmt = $conn->prepare("UPDATE mamabe_baby SET $photo_field = '' WHERE id=? AND mama_id=?");
            $stmt->bind_param("ii", $photo_baby_id, $id);
            $stmt->execute();
            $stmt->close();
            $msg      = 'Photo removed.';
            $msg_type = 'warn';
        }
    }
}

// Load babies
$babies = array();
$br = $conn->query("SELECT * FROM mamabe_baby WHERE mama_id=$id AND recycle=0 ORDER BY timestamp ASC");
if ($br) { while ($row = $br->fetch_assoc()) { $babies[] = $row; } }

// Voucher codes to show as a reminder next to each baby row (mama_voucher_id / baby_voucher_id, set by
// registration/ajax_approve.php when staff approve a registration - read-only here, no assign action on this page).
$voucher_codes = array();
$voucher_ids = array();
foreach ($babies as $b) {
    if (!empty($b['mama_voucher_id'])) $voucher_ids[] = (int)$b['mama_voucher_id'];
    if (!empty($b['baby_voucher_id'])) $voucher_ids[] = (int)$b['baby_voucher_id'];
}
if ($voucher_ids) {
    $voucher_ids = array_unique($voucher_ids);
    $in = implode(',', $voucher_ids);
    $vr = $conn->query("SELECT id, voucher_code FROM mamabe_voucher WHERE id IN ($in)");
    if ($vr) { while ($vrow = $vr->fetch_assoc()) { $voucher_codes[(int)$vrow['id']] = $vrow['voucher_code']; } }
}

// Check if any baby is still Expected (is_delivered=0) – block adding new baby
$has_expected = false;
$expected_baby_id = 0;
foreach ($babies as $b) {
    if ((int)$b['is_delivered'] === 0) {
        $has_expected = true;
        $expected_baby_id = (int)$b['id'];
        break;
    }
}

$added = isset($_GET['added']) ? 1 : 0;
$reactivated = isset($_GET['reactivated']) ? 1 : 0;
$is_alpro_baby_outlet = (stripos(isset($mama['outlet_name']) ? $mama['outlet_name'] : '', 'ALPRO BABY') !== false);

// Reactivation history (if this mama was ever inactive and re-registered)
$reactivation_log = array();
$_rl_chk = $conn->query("SHOW TABLES LIKE 'mamabe_reactivation_log'");
if ($_rl_chk && $_rl_chk->num_rows > 0) {
    $_rl = $conn->prepare("SELECT staff_name, old_timestamp, new_timestamp, reactivated_at FROM mamabe_reactivation_log WHERE mama_id=? ORDER BY id DESC");
    $_rl->bind_param("i", $id);
    $_rl->execute();
    $_rl_res = $_rl->get_result();
    while ($_rl_row = $_rl_res->fetch_assoc()) { $reactivation_log[] = $_rl_row; }
    $_rl->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – <?php echo htmlspecialchars($mama['mama_name']); ?></title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
/* ── Detail card ── */
.detail-layout { display:flex; gap:20px; flex-wrap:wrap; }
.detail-left, .detail-right { flex:1 1 0; min-width:240px; }
.detail-section-title { font-weight:bold; font-size:1em; color:#2a6496; text-align:left;
    border-bottom:2px solid #d0dfe8; padding-bottom:5px; margin-bottom:12px; }
.detail-row { display:flex; gap:0; margin-bottom:10px; text-align:left; }
.detail-label { font-weight:600; font-size:0.95em; color:#666; width:110px; flex-shrink:0; padding-top:2px; text-align:left; }
.detail-value { font-size:1em; color:#222; flex:1; text-align:left; }
.detail-actions { margin-top:18px; padding-top:14px; border-top:1px solid #e8e8e8;
    display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

/* ── Edit form ── */
.edit-row td { background:#f0f6ff; padding:14px 16px; }
.edit-grid { display:flex; flex-wrap:wrap; gap:10px; width:100%; box-sizing:border-box; }
.edit-field { display:flex; flex-direction:column; min-width:160px; box-sizing:border-box; }
.edit-field label { font-size:0.82em; color:#555; margin-bottom:3px; font-weight:bold; }
.edit-field input, .edit-field select, .edit-field textarea {
    padding:5px 8px; border:1px solid #ccc; border-radius:3px; font-size:0.9em; width:100%; box-sizing:border-box;
}
.edit-field.full { flex:0 0 100%; width:100%; max-width:100%; box-sizing:border-box; }
.section-title { font-size:0.8em; font-weight:bold; color:#2a6496; text-transform:uppercase;
    letter-spacing:1px; border-bottom:1px solid #ccd; padding-bottom:4px;
    margin:10px 0 8px; flex:1 1 100%; }
#add-baby-section { display:none; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mom.png"> MamaBe – Mama Detail</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<?php if ($added): ?>
<div class="alpro-alert alpro-success">Mama registered successfully.</div>
<?php endif; ?>
<?php if ($reactivated): ?>
<div class="alpro-alert alpro-success">This IC was previously Inactive (2+ years since registration) — the existing record has been reactivated instead of creating a duplicate.</div>
<?php endif; ?>
<?php if (!empty($reactivation_log)): ?>
<div class="alpro-alert" style="background:#f5f8fb; border:1px solid #d0dfe8; color:#2a6496; font-size:0.85em;">
  <strong>&#8635; Reactivation history:</strong>
  <?php foreach ($reactivation_log as $_rl_row): ?>
  <div style="margin-top:4px;">Reactivated by <strong><?php echo htmlspecialchars($_rl_row['staff_name']); ?></strong> on <?php echo htmlspecialchars($_rl_row['reactivated_at']); ?> (was registered/last active on <?php echo htmlspecialchars($_rl_row['old_timestamp']); ?>).</div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<!-- ===== MAMA DETAILS (item master) ===== -->
<div class="alpro-box">

  <!-- View mode -->
  <div id="mama-view">
<div style="margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:15px; text-align:left;">
  <a class="alpro-btn alpro-btn-grey" href="mamabe_mama_list.php">&larr; Back to List</a>
</div>

    <div class="detail-layout">
      
      <!-- Left: Personal Info -->
      <div class="detail-left">
        <div class="detail-section-title">Personal Information</div>

        <div class="detail-row">
          <div class="detail-label">Mama Name</div>
          <div class="detail-value"><strong><?php echo htmlspecialchars($mama['mama_name']); ?></strong></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">IC Number</div>
          <div class="detail-value alpro-mono"><?php echo htmlspecialchars($mama['ic']); ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Phone</div>
          <div class="detail-value">
            <?php if ($mama['phone'] != ''):
              $wa_phone = preg_replace('/\D/', '', $mama['phone']);
              if (substr($wa_phone, 0, 3) === '601') { }
              elseif (substr($wa_phone, 0, 2) === '01') { $wa_phone = '6' . $wa_phone; }
              elseif (substr($wa_phone, 0, 1) === '1')  { $wa_phone = '6' . $wa_phone; }
            ?>
            <a href="javascript:void(0);"
               onclick="window.open('https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&language=en','wa','width=1,height=1,left=1,top=1,toolbar=no,scrollbars=no,menubar=no,resizable=no');"
               style="color:#128C7E;font-weight:bold;">
              <img src="images/chat.png" width="18"> <?php echo htmlspecialchars($mama['phone']); ?>
            </a>
            <?php else: ?><span class="alpro-muted">–</span><?php endif; ?>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Email</div>
          <div class="detail-value"><?php echo htmlspecialchars($mama['email'] != '' ? $mama['email'] : '–'); ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Address</div>
          <div class="detail-value" style="line-height:1.6"><?php echo nl2br(htmlspecialchars($mama['address'] != '' ? $mama['address'] : '–')); ?></div>
        </div>
      </div>

      <!-- Right: Registration Info -->
      <div class="detail-right">
        <div class="detail-section-title">Registration Details</div>

        <div class="detail-row">
          <div class="detail-label">Language</div>
          <div class="detail-value"><?php echo htmlspecialchars(isset($lang_map[$mama['language']]) ? $lang_map[$mama['language']] : '–'); ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Outlet</div>
          <div class="detail-value"><?php echo htmlspecialchars(isset($mama['outlet_name']) ? $mama['outlet_name'] : '–'); ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Registered</div>
          <div class="detail-value alpro-muted"><?php echo htmlspecialchars($mama['timestamp']); ?></div>
        </div>
        <?php if (!empty($mama['registered_by'])): ?>
        <div class="detail-row">
          <div class="detail-label">Registered By</div>
          <div class="detail-value"><?php echo htmlspecialchars($mama['registered_by']); ?></div>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end detail-layout -->

    <div class="detail-actions">
      <?php if ($has_expected): ?>
      <button class="alpro-btn alpro-btn-grey" type="button"
              onclick="alert('Please complete the previous baby record (status: Expected) before adding a new one.');"
              title="Complete previous baby details first">+ Add Baby</button>
      <?php else: ?>
      <button class="alpro-btn alpro-btn-blue" type="button" onclick="toggleAddBaby()">+ Add Baby</button>
      <?php endif; ?>
      <button class="alpro-btn alpro-btn-grey" type="button" onclick="toggleMamaEdit(true)">Edit Details</button>
    </div>
  </div>

  <!-- Edit mode -->
  <div id="mama-edit" style="display:none">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
      <span style="font-weight:bold; color:#2a6496; font-size:1.05em;">Edit Mama Details</span>
      <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Remove this mama record?');">
        <input class="alpro-btn alpro-btn-orange" type="submit" name="delete_mama" value="Delete Mama">
      </form>
    </div>
    <?php
    $outlets_edit = $conn->query("SELECT id, code, comp_name FROM outlet WHERE recycle=0 ORDER BY comp_name");
    ?>
    <form method="post">
      <div class="alpro-grid">

        <div class="alpro-field">
          <label>Mama Name <span style="color:red">*</span></label>
          <input class="alpro-input" type="text" name="mama_name"
                 value="<?php echo htmlspecialchars($mama['mama_name']); ?>" required>
        </div>

        <div class="alpro-field">
          <label>IC Number <span style="color:red">*</span></label>
          <input class="alpro-input alpro-mono" type="text" name="ic"
                 value="<?php echo htmlspecialchars($mama['ic']); ?>" required>
        </div>

        <div class="alpro-field">
          <label>Phone Number</label>
          <input class="alpro-input" type="text" name="phone"
                 value="<?php echo htmlspecialchars($mama['phone']); ?>">
        </div>

        <div class="alpro-field" style="flex:1 1 100%">
          <label>Address <span style="color:red">*</span></label>
          <textarea class="alpro-input" name="address" rows="3" required
                    style="width:100%;resize:vertical"><?php echo htmlspecialchars($mama['address']); ?></textarea>
        </div>

        <div class="alpro-field">
          <label>Language</label>
          <select class="alpro-input" name="language">
            <?php foreach ($lang_map as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo ($mama['language'] == $k) ? 'selected' : ''; ?>>
              <?php echo $v; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="alpro-field">
          <label>Outlet <span style="color:red">*</span></label>
          <select class="alpro-input" name="outlet_id">
            <option value="">-- Select Outlet --</option>
            <?php if ($outlets_edit): while ($o = $outlets_edit->fetch_assoc()): ?>
            <option value="<?php echo (int)$o['id']; ?>"
              <?php echo ($mama['outlet_id'] == $o['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($o['code'] . ' - ' . $o['comp_name']); ?>
            </option>
            <?php endwhile; endif; ?>
          </select>
        </div>

        <div class="alpro-actions" style="flex:1 1 100%">
          <input class="alpro-btn alpro-btn-blue" type="submit" name="update_mama" value="Update">
          <button class="alpro-btn alpro-btn-grey" type="button" onclick="toggleMamaEdit(false)">Cancel</button>
        </div>

      </div>
    </form>
  </div>

</div>

<!-- ===== ADD BABY ===== -->
<div id="add-baby-section">
  <fieldset style="background-color:#f0f6ff; border:2px solid #b8d0f0; border-radius:6px; padding:15px; margin-bottom:20px;">
    <legend style="color:#2a6496; font-weight:bold; padding:3px 4px;">&#43; Add Baby</legend>
    <form method="post" enctype="multipart/form-data" onsubmit="return checkVerifyBeforeSubmit('new')">
      <div style="display:flex; flex-direction:row; flex-wrap:wrap; gap:10px; align-items:flex-start;">

        <!-- Row 1: Pregnancy + Registration Benefits (Add Baby) -->
        <div style="flex:1 1 100%; background:#fff; border:1px solid #d0dff0; border-radius:5px; margin-bottom:0; overflow:hidden;">
          <div style="background:#2a6496; color:#fff; font-size:0.78em; font-weight:bold; text-transform:uppercase; letter-spacing:1px; padding:5px 12px;">Pregnancy | Registration Benefits</div>
          <div style="display:flex; flex-wrap:wrap; gap:0; align-items:stretch;">

            <!-- ── SECTION 1: Pregnancy ── -->
            <div style="flex:1 1 300px; padding:12px 14px; display:flex; flex-wrap:wrap; gap:12px;">
              <div style="flex:1 1 180px;">
                <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">First Menstrual Date (LMP) <span style="color:red">*</span></label>
                <input class="alpro-input" type="date" name="lmp" id="lmp_input" onchange="calcEDD()" required style="width:100%;">
              </div>
              <div style="flex:1 1 180px;">
                <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">Estimated Due Date (EDD) <span style="color:red">*</span></label>
                <input class="alpro-input" type="date" name="edd" id="edd_input" onchange="calcLMP()" required style="width:100%;">
              </div>
            </div>

            <!-- ── SECTION 2: Registration Benefits ── -->
            <div style="flex:1 1 300px; display:flex; flex-wrap:wrap; gap:0; align-items:stretch; border-left:1px solid #d0dff0;">

              <!-- Pink Book -->
              <div style="flex:0 0 160px; background:#fff5f7; border-right:1px solid #f0d0d8; padding:12px; text-align:center;">
                <div style="font-size:0.74em; font-weight:bold; color:#c0637a; text-transform:uppercase; letter-spacing:1px; margin-bottom:7px;">Pregnancy Record Book</div>
                <?php
                $mama_pb = isset($mama['pink_book']) ? $mama['pink_book'] : '';
                if ($mama_pb):
                  $ext_pb = strtolower(pathinfo($mama_pb, PATHINFO_EXTENSION));
                ?>
                <div id="preview-pb-mama" style="width:120px; height:100px; border:2px dashed #e0b0be; border-radius:8px; overflow:hidden; margin:0 auto 7px; background:#fff; display:flex; align-items:center; justify-content:center;">
                  <?php if (in_array($ext_pb, array('jpg','jpeg','png'))): ?>
                    <img src="<?php echo htmlspecialchars(mamabe_file_url($mama_pb)); ?>" style="width:100%; height:100%; object-fit:cover; border-radius:6px; display:block;">
                  <?php else: ?>
                    <div style="padding:7px; background:#e8f0fe; border:1px solid #aac4f0; border-radius:6px; color:#2a6496; font-size:0.8em; max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars(basename($mama_pb)); ?>">&#128196; <?php echo htmlspecialchars(basename($mama_pb)); ?></div>
                  <?php endif; ?>
                </div>
                <?php else: ?>
                <div id="preview-pb-mama" style="width:120px; height:100px; border:2px dashed #e0b0be; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#dda0b0; font-size:0.76em; margin:0 auto 7px; background:#fff;">
                  <span style="font-size:1.5em; margin-bottom:3px;">&#128247;</span>
                  No file yet
                </div>
                <?php endif; ?>
                <label style="display:inline-block; padding:4px 10px; background:#c0637a; color:#fff; border-radius:4px; font-size:0.74em; cursor:pointer; font-weight:bold;">
                  &#8593; Upload
                  <input type="file" name="pink_book" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handleFileUpload(this, 'benefit_mama_book', 'preview-pb-mama')">
                </label>
              </div>

              <!-- Benefits checkboxes -->
              <div style="flex:1; min-width:190px; padding:12px 14px; background:#fafafa;">
                <div style="font-size:0.74em; font-weight:bold; color:#2a6496; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; border-bottom:1px solid #e0e8f0; padding-bottom:4px;">Benefits Given</div>
                <?php
                $new_benefits = array(
                  'benefit_mama_book' => array('label' => 'MamaBe Mama Book (Pregnancy Guide)',           'color' => '#c0637a', 'bg' => '#fff0f3'),
                  'benefit_vitamin_d' => array('label' => 'Free Items', 'color' => '#2a7a4a', 'bg' => '#f0fff5'),
                  'benefit_50_off'    => array('label' => "50% Off Register-Day Purchase",  'color' => '#d07a00', 'bg' => '#fffbf0'),
                );
                foreach ($new_benefits as $fname => $bdata):
                ?>
                <?php if ($fname === 'benefit_50_off'): ?>
                <div style="margin-bottom:6px; border:1px solid #e0e0e0; border-radius:6px; background:#fff;" id="wrap_benefit_50_off_new">
                  <div style="padding:6px 10px; font-weight:bold; color:#333; font-size:0.86em; border-bottom:1px dashed #f0d090;">
                    <?php echo htmlspecialchars($bdata['label']); ?>
                    <input type="checkbox" name="benefit_50_off" value="1" style="display:none;">
                  </div>
                  <div id="sku_items_new" style="display:block; padding:8px 10px;">
                    <div style="font-size:0.71em; color:#888; margin-bottom:4px; font-weight:bold; text-transform:uppercase;">Items Purchased (After 50%)</div>
                    <div id="sku_items_new_container">
                      <!-- Populated via Verify Receipt -->
                    </div>
                  </div>
                </div>
                <?php elseif ($fname === 'benefit_vitamin_d'): ?>
                <?php if (!$is_alpro_baby_outlet): ?>
                <?php echo mamabe_render_free_items('new', 'benefit_free_items', '', null, $free_items_mama, false); ?>
                <?php endif; ?>
                <?php else: ?>
                <label style="display:flex; align-items:center; gap:9px; margin-bottom:6px; cursor:pointer; padding:6px 10px; border-radius:6px; border:1px solid #e0e0e0; background:#fff;"
                       onchange="this.style.borderColor=this.querySelector('input').checked?'<?php echo $bdata['color']; ?>':'#e0e0e0'; this.style.background=this.querySelector('input').checked?'<?php echo $bdata['bg']; ?>':'#fff';">
                  <input type="checkbox" name="<?php echo $fname; ?>" value="1"
                         style="width:14px; height:14px; accent-color:<?php echo $bdata['color']; ?>; cursor:pointer;">
                  <span style="font-size:0.86em; color:#333;"><?php echo htmlspecialchars($bdata['label']); ?></span>
                </label>
                <?php endif; ?>
                <?php endforeach; ?>
                <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                  <div style="flex:1;">
                    <div style="font-size:0.74em; font-weight:bold; color:#555; margin-bottom:3px;">Receipt No. (50% Off)</div>
                    <input type="text" name="receipt_no" id="receipt_no_new" placeholder="e.g. RCP-00123"
                           style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.86em; width:100%; box-sizing:border-box;">
                    <input type="hidden" name="receipt_price_mismatch" id="receipt_price_mismatch_new" value="">
                  </div>
                  <div style="margin-top:14px;">
                    <button type="button" class="alpro-btn alpro-btn-blue" onclick="verifyReceipt(this, 'new', 'mama')" style="padding:4px 10px; font-size:0.82em;">Verify</button>
                  </div>
                </div>
                <?php if ($is_alpro_baby_outlet): ?>
                <div style="margin-top:8px; padding:8px 10px; border:1px dashed #7c3db5; border-radius:5px; background:#fdf5ff;">
                  <div style="font-size:0.71em; background:#f3e8ff; border:1px solid #d0b0e8; border-radius:4px; padding:5px 8px; margin-bottom:8px; color:#5a2a80; line-height:1.5;">
                    ALPRO BABY is unable to perform Vitamin D Test due to outlet policy.<br>
                    ALPRO BABY kindly guide the customer to the nearest <b>ALPRO Pharmacy</b> to complete the Free Vitamin D Test.<br><br>
                    &#9989; ALPRO Pharmacy is required to upload another receipt for the Vitamin D Test verification.<br>
                    &#9989; ALPRO Pharmacy may persuade customer to purchase Vitamin D supplement if the register-day purchase from ALPRO BABY has not fulfilled.
                  </div>
                  <?php echo mamabe_render_free_items('new', 'benefit_free_items', '', null, $free_items_mama, false); ?>
                  <div style="margin-bottom:6px; border:1px solid #e0e0e0; border-radius:6px; background:#fff;">
                    <div style="padding:6px 10px; font-weight:bold; color:#333; font-size:0.86em; border-bottom:1px dashed #f0d090;">
                      50% Off Register-Day Purchase
                    </div>
                    <div style="padding:8px 10px;">
                      <div style="font-size:0.71em; color:#888; margin-bottom:4px; font-weight:bold; text-transform:uppercase;">Items Purchased (After 50%)</div>
                      <div id="sku_items_vd_new_container"></div>
                    </div>
                  </div>
                  <div style="display:flex; align-items:flex-end; gap:8px;">
                    <div style="flex:1;">
                      <div style="font-size:0.74em; font-weight:bold; color:#555; margin-bottom:3px;">Receipt No.</div>
                      <input type="text" name="vd_receipt_no" id="vd_receipt_no_new" placeholder="e.g. RCP-00123"
                             style="padding:4px 8px; border:1px solid #d0b0e8; border-radius:4px; font-size:0.86em; width:100%; box-sizing:border-box;">
                    </div>
                    <button type="button" class="alpro-btn alpro-btn-blue" onclick="verifyVdReceipt(this, 'new')" style="padding:4px 10px; font-size:0.82em;">Verify</button>
                  </div>
                </div>
                <?php endif; ?>
              </div>

            </div>

          </div>
        </div>

        <input type="hidden" name="is_delivered" value="0">

        <div style="display:flex; gap:8px; flex:1 1 100%;">
          <input class="alpro-btn alpro-btn-blue" type="submit" name="add_baby" value="Save">
          <button class="alpro-btn alpro-btn-grey" type="button" onclick="toggleAddBaby()">Cancel</button>
        </div>

      </div>
    </form>
  </fieldset>
</div>

<!-- ===== BABY LIST ===== -->
<fieldset>
  <legend><b>Baby Records – <?php echo htmlspecialchars($mama['mama_name']); ?> (<?php echo count($babies); ?>)</b></legend>
  <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
      <th>#</th>
      <th>Baby Name</th>
      <th>First Menstrual Date (LMP)</th>
      <th>Estimated Due Date (EDD)</th>
      <th>Date of Birth</th>
      <th>Gender</th>
      <th>Status</th>
      <th>Voucher</th>
      <th>Action</th>
    </tr>

    <?php if (count($babies) > 0):
      $i = 1;
      $rendered_twins = array();
      foreach ($babies as $b):
        $bid          = (int)$b['id'];
        if (in_array($bid, $rendered_twins)) continue;

        $is_delivered = (int)$b['is_delivered'];
        $is_misc_style = ($is_delivered == 2) ? 'color:#c82333; background-color:#ffebeb;' : '';
        $muted_class = ($is_delivered == 2) ? '' : 'alpro-muted';
        $display_name = ($b['baby_name'] !== '') ? htmlspecialchars($b['baby_name']) : '<span class="' . $muted_class . '">–</span>';
        $display_dob  = ($b['dob'] && $b['dob'] !== '0000-00-00') ? htmlspecialchars($b['dob']) : '<span class="' . $muted_class . '">–</span>';
        $display_gender = ($b['gender'] !== '') ? htmlspecialchars($b['gender']) : '<span class="' . $muted_class . '">–</span>';

        $status_html = '';
        if ($is_delivered == 1) $status_html = '<b style="display:inline-block; color:#155724; background:#d4edda; padding:4px 10px; border-radius:12px; font-size:0.85em; margin-bottom:2px;">Delivered</b>';
        elseif ($is_delivered == 2) $status_html = '<b style="display:inline-block; color:#721c24; background:#f8d7da; padding:4px 10px; border-radius:12px; font-size:0.85em; margin-bottom:2px;">Miscarriage</b>';
        else $status_html = '<b style="display:inline-block; color:#856404; background:#fff3cd; padding:4px 10px; border-radius:12px; font-size:0.85em; margin-bottom:2px;">Expected</b>';

        $twin = null;
        if (!empty($b['twin_id']) && $b['twin_id'] > 0) {
            foreach ($babies as $t) {
                if ($t['id'] == $b['twin_id']) {
                    $twin = $t;
                    $rendered_twins[] = $t['id'];
                    break;
                }
            }
        }

        if ($twin) {
            $t_muted = ($twin['is_delivered'] == 2) ? '' : 'alpro-muted';
            $t_name = ($twin['baby_name'] !== '') ? htmlspecialchars($twin['baby_name']) : '<span class="' . $t_muted . '">–</span>';
            $display_name .= '<br><span style="color:#2a6496;font-size:0.85em;font-weight:bold;">&amp;</span> ' . $t_name;
            
            $t_dob = ($twin['dob'] && $twin['dob'] !== '0000-00-00') ? htmlspecialchars($twin['dob']) : '<span class="' . $t_muted . '">–</span>';
            if ($t_dob !== $display_dob) {
                $display_dob .= '<br><span style="color:#2a6496;font-size:0.85em;font-weight:bold;">&amp;</span> ' . $t_dob;
            } else {
                $display_dob .= '<br><span style="color:#2a6496;font-size:0.85em;font-weight:bold;">&amp;</span> ' . $t_dob;
            }
            
            $t_gender = ($twin['gender'] !== '') ? htmlspecialchars($twin['gender']) : '<span class="' . $t_muted . '">–</span>';
            $display_gender .= '<br><span style="color:#2a6496;font-size:0.85em;font-weight:bold;">&amp;</span> ' . $t_gender;
            
            $t_status_html = '';
            if ($twin['is_delivered'] == 1) $t_status_html = '<b style="display:inline-block; color:#155724; background:#d4edda; padding:4px 10px; border-radius:12px; font-size:0.85em; margin-bottom:2px;">Delivered</b>';
            elseif ($twin['is_delivered'] == 2) $t_status_html = '<b style="display:inline-block; color:#721c24; background:#f8d7da; padding:4px 10px; border-radius:12px; font-size:0.85em; margin-bottom:2px;">Miscarriage</b>';
            else $t_status_html = '<b style="display:inline-block; color:#856404; background:#fff3cd; padding:4px 10px; border-radius:12px; font-size:0.85em; margin-bottom:2px;">Expected</b>';
            
            $status_html .= '<br>' . $t_status_html;
        }

        // Voucher reminder - read-only, just so staff see at a glance that this mama/baby already got a voucher
        // (assigned via registration/index.php's Approve button, mamabe_baby.mama_voucher_id / baby_voucher_id).
        $voucher_badge = function ($label, $code) {
            return '<div><span class="alpro-muted" style="font-size:0.8em;">' . $label . '</span> '
                 . '<b style="color:#155724;">' . htmlspecialchars($code) . '</b></div>';
        };
        $voucher_html = '';
        if (!empty($b['mama_voucher_id']) && isset($voucher_codes[(int)$b['mama_voucher_id']])) {
            $voucher_html .= $voucher_badge('Mama', $voucher_codes[(int)$b['mama_voucher_id']]);
        }
        if (!empty($b['baby_voucher_id']) && isset($voucher_codes[(int)$b['baby_voucher_id']])) {
            $voucher_html .= $voucher_badge('Baby', $voucher_codes[(int)$b['baby_voucher_id']]);
        }
        if ($twin) {
            if (!empty($twin['baby_voucher_id']) && isset($voucher_codes[(int)$twin['baby_voucher_id']])) {
                $voucher_html .= $voucher_badge('Baby (twin)', $voucher_codes[(int)$twin['baby_voucher_id']]);
            }
        }
        if ($voucher_html === '') $voucher_html = '<span class="alpro-muted">–</span>';
    ?>
    <!-- Baby row -->
    <tr style="<?php echo $is_misc_style; ?>">
      <td class="alpro-center" style="vertical-align:middle;"><?php echo $i++; ?></td>
      <td style="vertical-align:middle; line-height:1.5;"><?php echo $display_name; ?></td>
      <td style="vertical-align:middle;"><?php echo htmlspecialchars($b['lmp']); ?></td>
      <td style="vertical-align:middle;"><?php echo htmlspecialchars($b['edd']); ?></td>
      <td style="vertical-align:middle; line-height:1.5;"><?php echo $display_dob; ?></td>
      <td style="vertical-align:middle; line-height:1.5;"><?php echo $display_gender; ?></td>
      <td class="alpro-center" style="vertical-align:middle; line-height:1.5;">
        <?php echo $status_html; ?>
      </td>
      <td style="vertical-align:middle; line-height:1.5;">
        <?php echo $voucher_html; ?>
      </td>
      <td class="alpro-center" style="white-space:nowrap; vertical-align:middle;">
        <button type="button" title="Edit"
                onclick="toggleEdit(<?php echo $bid; ?>)" style="background:none; border:none; cursor:pointer; padding:0;">
          <img src="images/edit.png" style="width:20px;vertical-align:middle;" title="Edit">
        </button>
        <?php if (isset($mamab_autho) && $mamab_autho == '1'): ?>
        <button type="button" title="Delete" onclick="deleteBaby(<?php echo $bid; ?>)"
                style="background:none; border:none; cursor:pointer; padding:0; margin-left:6px;">
          <img src="images/delete.png" style="width:18px;vertical-align:middle;" title="Delete">
        </button>
        <?php endif; ?>
      </td>
    </tr>

    <!-- Inline edit row — 3-section layout matching Add Baby form -->
    <tr class="edit-row" id="edit-row-<?php echo $bid; ?>" style="display:none">
      <td colspan="9" style="padding:10px 6px; background:#f4f8ff;">
        <form method="post" enctype="multipart/form-data" onsubmit="return checkVerifyBeforeSubmit(<?php echo $bid; ?>)">
          <input type="hidden" name="baby_id" value="<?php echo $bid; ?>">
          <div style="display:flex; flex-direction:column; gap:10px;">

            <!-- Row 1: Pregnancy + Registration Benefits (Edit Baby) -->
            <div style="flex:1 1 100%; background:#fff; border:1px solid #d0dff0; border-radius:5px; margin-bottom:10px; overflow:hidden;">
              <div style="background:#2a6496; color:#fff; font-size:0.78em; font-weight:bold; text-transform:uppercase; letter-spacing:1px; padding:5px 12px;">1. Pregnancy | Registration Benefits</div>
              <div style="display:flex; flex-wrap:wrap; gap:0; align-items:stretch;">

                <!-- ── SECTION 1: Pregnancy ── -->
                <div style="flex:1 1 300px; padding:12px 14px; display:flex; flex-wrap:wrap; gap:12px;">
                  <div style="flex:1 1 160px;">
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">First Menstrual Date (LMP)</label>
                    <input class="alpro-input" type="date" name="lmp" id="lmp_edit_<?php echo $bid; ?>"
                           value="<?php echo htmlspecialchars($b['lmp']); ?>"
                           onchange="calcEditEDD(<?php echo $bid; ?>)" style="width:100%;">
                  </div>
                  <div style="flex:1 1 160px;">
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">Estimated Due Date (EDD)</label>
                    <input class="alpro-input" type="date" name="edd" id="edd_edit_<?php echo $bid; ?>"
                           value="<?php echo htmlspecialchars($b['edd']); ?>"
                           onchange="calcEditLMP(<?php echo $bid; ?>)" style="width:100%;">
                  </div>
                </div>

                <!-- ── SECTION 2: Registration Benefits ── -->
                <?php
                  $bpb     = isset($b['pink_book']) ? $b['pink_book'] : '';
                  $bpb_ext = $bpb ? strtolower(pathinfo($bpb, PATHINFO_EXTENSION)) : '';
                  $eb_benefits = array(
                    'benefit_mama_book' => array('label' => 'MamaBe Mama Book (Pregnancy Guide)',           'color' => '#c0637a', 'bg' => '#fff0f3', 'at' => 'benefit_mama_book_at'),
                    'benefit_vitamin_d' => array('label' => 'Free Items', 'color' => '#2a7a4a', 'bg' => '#f0fff5', 'at' => 'benefit_free_items_at'),
                    'benefit_50_off'    => array('label' => "50% Off Register-Day Purchase",  'color' => '#d07a00', 'bg' => '#fffbf0', 'at' => 'benefit_50_off_at'),
                  );

                  $eb_saved_skus = isset($b['benefit_50_off_items']) ? array_map('trim', explode(',', $b['benefit_50_off_items'])) : array();
                  $eb_free_locked = $_admin_override ? false : (!empty($b['benefit_free_items_at']) && $b['benefit_free_items_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($b['benefit_free_items_at'])) >= 86400);
                ?>
                <div style="flex:1 1 300px; display:flex; flex-wrap:wrap; gap:0; align-items:stretch; border-left:1px solid #d0dff0;">

                  <!-- Pink Book -->
                  <div style="flex:0 0 160px; background:#fff5f7; border-right:1px solid #f0d0d8; padding:12px; text-align:center;">
                    <div style="font-size:0.74em; font-weight:bold; color:#c0637a; text-transform:uppercase; letter-spacing:1px; margin-bottom:7px;">Pregnancy Record Book</div>
                    <div id="preview-pb-<?php echo $bid; ?>" style="position:relative; display:inline-block; vertical-align:top;">
                    <?php if ($bpb && $bpb_ext !== 'pdf'): ?>
                      <a href="<?php echo htmlspecialchars(mamabe_file_url($bpb)); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars(mamabe_file_url($bpb)); ?>" style="max-width:120px; max-height:120px; border:1px solid #e0b0be; border-radius:6px; display:block; margin:0 auto 7px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                      </a>
                    <?php elseif ($bpb && $bpb_ext === 'pdf'): ?>
                      <a href="<?php echo htmlspecialchars(mamabe_file_url($bpb)); ?>" target="_blank"
                         style="display:inline-block; padding:7px 12px; background:#e8f0fe; border:1px solid #aac4f0; border-radius:6px; color:#2a6496; font-size:0.8em; margin-bottom:7px; text-decoration:none;">
                        &#128196; View PDF
                      </a><br>
                    <?php else: ?>
                      <div style="width:120px; height:100px; border:2px dashed #e0b0be; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#dda0b0; font-size:0.76em; margin:0 auto 7px; background:#fff;">
                        <span style="font-size:1.5em; margin-bottom:3px;">&#128247;</span>
                        No file yet
                      </div>
                    <?php endif; ?>
                    <?php if ($bpb && isset($mamab_autho) && $mamab_autho == '1'): ?>
                    <button type="button" onclick="deletePhoto(<?php echo $bid; ?>, 'pink_book')" title="Delete photo"
                            style="position:absolute; top:-6px; right:-6px; width:22px; height:22px; border-radius:50%; background:#c82333; color:#fff; border:2px solid #fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.35); line-height:1; padding:0;">
                      &times;
                    </button>
                    <?php endif; ?>
                    </div>
                    <br>
                    <?php
                      $pb_locked = $_admin_override ? false : (!empty($b['benefit_mama_book_at']) && $b['benefit_mama_book_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($b['benefit_mama_book_at'])) >= 86400);
                    ?>
                    <?php if (!$pb_locked && !$bpb): ?>
                    <label style="display:inline-block; padding:4px 10px; background:#c0637a; color:#fff; border-radius:4px; font-size:0.74em; cursor:pointer; font-weight:bold;">
                      &#8593; Upload
                      <input type="file" name="pink_book" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handleFileUpload(this, 'benefit_mama_book', 'preview-pb-<?php echo $bid; ?>')">
                    </label>
                    <?php endif; ?>
                    <?php if ($bpb): ?><div style="font-size:0.71em; color:#888; margin-top:4px;"><?php echo htmlspecialchars(basename($bpb)); ?></div><?php endif; ?>
                  </div>

                  <!-- Benefits checkboxes -->
                  <div style="flex:1; min-width:190px; padding:12px 14px; background:#fafafa;">
                    <div style="font-size:0.74em; font-weight:bold; color:#2a6496; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; border-bottom:1px solid #e0e8f0; padding-bottom:4px;">Benefits Given</div>
                    <?php foreach ($eb_benefits as $efname => $efdata):
                      $eb_checked = !empty($b[$efname]);
                      $eb_ts      = (!empty($b[$efdata['at']]) && $b[$efdata['at']] !== '0000-00-00 00:00:00') ? $b[$efdata['at']] : '';
                      $eb_locked  = $_admin_override ? false : ($eb_checked && $eb_ts && (time() - strtotime($eb_ts)) >= 86400);
                    ?>
                    <?php if ($efname === 'benefit_50_off'): ?>
                    <div style="margin-bottom:6px; border:1px solid <?php echo $eb_checked ? '#d07a00' : '#e0e0e0'; ?>; border-radius:6px; background:<?php echo $eb_checked ? '#fffbf0' : '#fff'; ?>; <?php echo $eb_locked ? 'opacity:0.7; pointer-events:none;' : ''; ?>" id="wrap_50off_<?php echo $bid; ?>">
                      <div style="padding:6px 10px; font-weight:bold; color:#333; font-size:0.86em; border-bottom:1px dashed #f0d090; display:flex; justify-content:space-between; align-items:center;">
                        <span><?php echo htmlspecialchars($efdata['label']); ?></span>
                        <input type="checkbox" name="benefit_50_off" value="1" <?php echo $eb_checked ? 'checked' : ''; ?> style="display:none;">
                        <?php if ($eb_checked && $eb_ts): ?>
                        <span style="text-align:right; line-height:1.3;">
                          <span style="display:block; font-size:0.82em; font-weight:bold; color:#d07a00;">&#10003; Done</span>
                          <span style="display:block; font-size:0.75em; color:#999; font-weight:normal;"><?php echo htmlspecialchars($eb_ts); ?></span>
                        </span>
                        <?php endif; ?>
                      </div>
                      <div id="sku_items_<?php echo $bid; ?>" style="display:block; padding:8px 10px;">
                        <div style="font-size:0.71em; color:#888; margin-bottom:4px; font-weight:bold; text-transform:uppercase;">Items Purchased (After 50%)</div>
                        <div id="sku_items_container_<?php echo $bid; ?>">
                        <?php foreach ($eb_saved_skus as $scode):
                          if ($scode === '') continue;
                          $sname  = isset($master_skus['mama'][$scode])  ? $master_skus['mama'][$scode]  : $scode;
                          $sprice = isset($master_prices['mama'][$scode]) ? $master_prices['mama'][$scode] : 0;
                        ?>
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:3px; font-size:0.8em; color:#444;">
                          <input type="hidden" name="benefit_50_off_items[]" value="<?php echo htmlspecialchars($scode); ?>">
                          <span style="color:#aaa; font-family:monospace; font-size:0.88em; min-width:82px;"><?php echo htmlspecialchars($scode); ?></span>
                          <span style="flex:1;"><?php echo htmlspecialchars($sname); ?></span>
                          <span style="color:#888; white-space:nowrap;"><?php echo $sprice > 0 ? 'RM ' . number_format($sprice, 2) : 'RM –'; ?></span>
                        </div>
                        <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                    <?php elseif ($efname === 'benefit_vitamin_d'): ?>
                    <?php if (!$is_alpro_baby_outlet): ?>
                    <?php echo mamabe_render_free_items($bid, 'benefit_free_items', isset($b['benefit_free_items']) ? $b['benefit_free_items'] : '', isset($b['benefit_free_items_at']) ? $b['benefit_free_items_at'] : null, $free_items_mama, $eb_free_locked); ?>
                    <?php endif; ?>
                    <?php else: ?>
                    <label style="display:flex; align-items:center; gap:9px; margin-bottom:6px; cursor:pointer; padding:6px 10px; border-radius:6px; border:1px solid <?php echo $eb_checked ? $efdata['color'] : '#e0e0e0'; ?>; background:<?php echo $eb_checked ? $efdata['bg'] : '#fff'; ?>; <?php echo $eb_locked ? 'opacity:0.7; pointer-events:none;' : ''; ?>">
                      <input type="checkbox" name="<?php echo $efname; ?>" value="1" <?php echo $eb_checked ? 'checked' : ''; ?>
                             style="width:14px; height:14px; accent-color:<?php echo $efdata['color']; ?>; cursor:pointer;"
                             onchange="this.closest('label').style.borderColor=this.checked?'<?php echo $efdata['color']; ?>':'#e0e0e0'; this.closest('label').style.background=this.checked?'<?php echo $efdata['bg']; ?>':'#fff';">
                      <span style="font-size:0.86em; color:#333;"><?php echo htmlspecialchars($efdata['label']); ?></span>
                      <?php if ($eb_checked && $eb_ts): ?>
                      <span style="margin-left:auto; text-align:right; line-height:1.3;">
                        <span style="display:block; font-size:0.71em; font-weight:bold; color:<?php echo $efdata['color']; ?>;">&#10003; Done</span>
                        <span style="display:block; font-size:0.68em; color:#999;"><?php echo htmlspecialchars($eb_ts); ?></span>
                      </span>
                      <?php endif; ?>
                    </label>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <div style="margin-top:8px;">
                      <div style="display:flex; align-items:center; gap:8px;">
                        <div style="flex:1;">
                          <div style="font-size:0.74em; font-weight:bold; color:#555; margin-bottom:3px;">Receipt No.</div>
                          <input type="text" name="receipt_no" id="receipt_no_<?php echo $bid; ?>" placeholder="e.g. RCP-00123"
                                 value="<?php echo htmlspecialchars(isset($b['receipt_no']) ? $b['receipt_no'] : ''); ?>"
                                 <?php if (!empty($b['receipt_no'])): ?>readonly<?php endif; ?>
                                 style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.86em; width:100%; box-sizing:border-box;<?php echo !empty($b['receipt_no']) ? 'background:#f5f5f5; color:#555;' : ''; ?>">
                          <input type="hidden" name="receipt_price_mismatch" id="receipt_price_mismatch_<?php echo $bid; ?>"
                                 value="<?php echo htmlspecialchars(isset($b['receipt_price_mismatch']) ? $b['receipt_price_mismatch'] : ''); ?>">
                        </div>
                        <div style="margin-top:14px; display:flex; gap:5px;">
                          <button type="button" class="alpro-btn alpro-btn-blue" onclick="verifyReceipt(this, <?php echo $bid; ?>, 'mama')" style="padding:4px 10px; font-size:0.82em; <?php echo !empty($b['receipt_no']) ? 'display:none;' : ''; ?>">Verify</button>
                          <?php if (!empty($b['receipt_no'])): ?>
                          <button type="button" class="alpro-btn alpro-btn-grey" onclick="viewReceipt('<?php echo htmlspecialchars(addslashes($b['receipt_no'])); ?>')" style="padding:4px 10px; font-size:0.82em;">&#128196; View</button>
                          <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?>
                          <button type="button" class="alpro-btn alpro-btn-orange" onclick="reVerifyReceipt(this, <?php echo $bid; ?>, 'mama')" style="padding:4px 10px; font-size:0.82em;" title="Admin: Re-verify and repopulate SKU items">&#8635; Re-verify</button>
                          <?php endif; ?>
                          <?php endif; ?>
                        </div>
                      </div>
                      <?php if (!empty($b['receipt_price_mismatch'])): ?>
                      <div id="receipt_mismatch_badge_<?php echo $bid; ?>" style="margin-top:6px; background:#fff3f3; border:1px solid #ef9a9a; border-radius:4px; padding:6px 10px; font-size:0.8em; color:#c62828;">
                        <span style="font-weight:bold;">&#9888; Mismatch / Issue:</span><br>
                        <span style="white-space:pre-line;"><?php echo htmlspecialchars($b['receipt_price_mismatch']); ?></span>
                      </div>
                      <?php endif; ?>
                      <?php if ($is_alpro_baby_outlet): ?>
                      <?php $vd_rno = isset($b['vd_receipt_no']) ? $b['vd_receipt_no'] : ''; ?>
                      <div style="margin-top:8px; padding:8px 10px; border:1px dashed #7c3db5; border-radius:5px; background:#fdf5ff;">
                        <div style="font-size:0.74em; font-weight:bold; color:#7c3db5; margin-bottom:5px;">&#128204; Special Case – ALPRO BABY Outlet</div>
                        <div style="font-size:0.71em; background:#f3e8ff; border:1px solid #d0b0e8; border-radius:4px; padding:5px 8px; margin-bottom:8px; color:#5a2a80; line-height:1.5;">
                          ALPRO BABY is unable to perform Vitamin D Test due to outlet policy.<br>
                          ALPRO BABY kindly guide the customer to the nearest <b>ALPRO Pharmacy</b> to complete the Free Vitamin D Test.<br><br>
                          &#9989; ALPRO Pharmacy is required to upload another receipt for the Vitamin D Test verification.<br>
                          &#9989; ALPRO Pharmacy may persuade customer to purchase Vitamin D supplement if the register-day purchase from ALPRO BABY has not fulfilled.
                        </div>
                        <?php echo mamabe_render_free_items($bid, 'benefit_free_items', isset($b['benefit_free_items']) ? $b['benefit_free_items'] : '', isset($b['benefit_free_items_at']) ? $b['benefit_free_items_at'] : null, $free_items_mama, $eb_free_locked); ?>
                        <div style="margin-bottom:6px; border:1px solid #e0e0e0; border-radius:6px; background:#fff;">
                          <div style="padding:6px 10px; font-weight:bold; color:#333; font-size:0.86em; border-bottom:1px dashed #f0d090;">
                            50% Off Register-Day Purchase
                          </div>
                          <div style="padding:8px 10px;">
                            <div style="font-size:0.71em; color:#888; margin-bottom:4px; font-weight:bold; text-transform:uppercase;">Items Purchased (After 50%)</div>
                            <div id="sku_items_vd_<?php echo $bid; ?>_container"></div>
                          </div>
                        </div>
                        <div style="display:flex; align-items:flex-end; gap:8px;">
                          <div style="flex:1;">
                            <div style="font-size:0.74em; font-weight:bold; color:#555; margin-bottom:3px;">Receipt No.</div>
                            <input type="text" name="vd_receipt_no" id="vd_receipt_no_<?php echo $bid; ?>" placeholder="e.g. RCP-00123"
                                   value="<?php echo htmlspecialchars($vd_rno); ?>"
                                   <?php if (!empty($vd_rno)): ?>readonly<?php endif; ?>
                                   style="padding:4px 8px; border:1px solid #d0b0e8; border-radius:4px; font-size:0.86em; width:100%; box-sizing:border-box;<?php echo !empty($vd_rno) ? 'background:#f5f5f5; color:#555;' : ''; ?>">
                          </div>
                          <?php if (!empty($vd_rno)): ?>
                          <button type="button" class="alpro-btn alpro-btn-grey" onclick="viewReceipt('<?php echo htmlspecialchars(addslashes($vd_rno)); ?>')" style="padding:4px 10px; font-size:0.82em;">&#128196; View</button>
                          <?php else: ?>
                          <button type="button" class="alpro-btn alpro-btn-blue" onclick="verifyVdReceipt(this, <?php echo $bid; ?>)" style="padding:4px 10px; font-size:0.82em;">Verify</button>
                          <?php endif; ?>
                        </div>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>

                </div>

              </div>
              <?php if (!empty($b['preg_filled_by'])): ?>
              <div style="padding:4px 12px 6px; font-size:0.75em; color:#888; border-top:1px dashed #e0e0e0; text-align:right;">
                &#9998; Pregnancy section filled by <strong><?php echo htmlspecialchars($b['preg_filled_by']); ?></strong>
                <?php if (!empty($b['preg_filled_at']) && $b['preg_filled_at'] !== '0000-00-00 00:00:00'): ?>
                on <?php echo htmlspecialchars($b['preg_filled_at']); ?>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div><!-- /row 1 -->

            <!-- Row 2: Baby Status + Baby Registration Benefits -->
            <div style="flex:1 1 100%; background:#fff; border:1px solid #d0dff0; border-radius:5px; overflow:hidden;">
              <div style="background:#5a7a5a; color:#fff; font-size:0.78em; font-weight:bold; text-transform:uppercase; letter-spacing:1px; padding:5px 12px;">Baby Status | Baby Registration Benefits</div>
              <div style="display:flex; flex-wrap:wrap; gap:0; align-items:stretch;">

              <!-- ── SECTION 3: Baby Status ── -->
              <div style="flex:1 1 300px; padding:12px 14px;">
                <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:10px;">
                  <div style="flex:1 1 160px;">
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">Baby Status</label>
                    <select name="is_delivered" onchange="toggleDelivery(<?php echo $bid; ?>, this.value)"
                            style="width:100%; padding:5px; border-radius:3px; border:1px solid #ccc; font-size:0.9em;">
                      <option value="0" <?php echo ($is_delivered == 0) ? 'selected' : ''; ?>>Expected</option>
                      <option value="1" <?php echo ($is_delivered == 1) ? 'selected' : ''; ?>>Safely Delivered</option>
                      <option value="2" <?php echo ($is_delivered == 2) ? 'selected' : ''; ?>>Miscarriage</option>
                    </select>
                  </div>
                  <div style="flex:2 1 260px;">
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">Remarks</label>
                    <textarea name="remarks" rows="2"
                              style="width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:3px; font-size:0.88em; resize:vertical; box-sizing:border-box;"><?php echo htmlspecialchars($b['remarks'] ?? ''); ?></textarea>
                  </div>
                </div>

                <!-- Baby Info (shows when Delivered) -->
                <div id="baby-info-wrapper-<?php echo $bid; ?>" style="display:<?php echo ($is_delivered == 1) ? 'block' : 'none'; ?>;">
                  <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="edit-field">
                      <label>Baby Name <span style="color:red">*</span></label>
                      <input type="text" name="baby_name" value="<?php echo htmlspecialchars($b['baby_name']); ?>">
                    </div>
                    <div class="edit-field">
                      <label>MyKid (IC) <span style="color:red">*</span></label>
                      <input type="text" name="mykid" class="alpro-mono" value="<?php echo htmlspecialchars($b['mykid']); ?>">
                    </div>
                    <div class="edit-field">
                      <label>Date of Birth (Actual) <span style="color:red">*</span></label>
                      <input type="date" name="dob" value="<?php echo ($b['dob'] && $b['dob'] !== '0000-00-00') ? htmlspecialchars($b['dob']) : ''; ?>">
                    </div>
                    <div class="edit-field">
                      <label>Gender <span style="color:red">*</span></label>
                      <select name="gender">
                        <option value="">– Select –</option>
                        <option value="Male"   <?php echo ($b['gender'] === 'Male')   ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($b['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                      </select>
                    </div>
                  </div>
                  <div class="edit-field full" style="margin-top:10px;">
                    <label><b>Baby Sensitivity Conditions</b> <span style="color:red">*</span> <span style="font-weight:normal;color:#888;">(Tick ✓)</span></label>
                    <?php
                    $sens_options = array('Normal','CMPA (Cow\'s Milk Protein Allergy)','Lactose Intolerance','G6PD Deficiency','A1 Intolerance (e.g. frequent vomiting, bloating)','Skin Sensitivity (e.g. eczema-like symptoms)');
                    $sens_saved   = isset($b['sensitivity']) ? array_map('trim', explode(',', $b['sensitivity'])) : array();
                    ?>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px 15px; margin-top:5px;">
                    <?php foreach ($sens_options as $sopt): ?>
                      <label style="font-weight:normal; display:flex; align-items:flex-start; gap:6px; cursor:pointer; line-height:1.2;">
                        <input type="checkbox" name="sensitivity[]" value="<?php echo htmlspecialchars($sopt); ?>"
                          <?php echo in_array($sopt, $sens_saved) ? 'checked' : ''; ?> style="margin-top:2px; width:auto;">
                        <span style="flex:1;"><?php echo htmlspecialchars($sopt); ?></span>
                      </label>
                    <?php endforeach; ?>
                    </div>
                  </div>
                </div>

                <!-- Miscarriage Details (shows when Miscarriage) -->
                <div id="miscarriage-info-wrapper-<?php echo $bid; ?>" style="display:<?php echo ($is_delivered == 2) ? 'flex' : 'none'; ?>;flex-wrap:wrap;gap:10px;">
                  <div style="font-size:0.78em; font-weight:bold; color:#c82333; text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid #f5c6cb; padding-bottom:4px; margin-bottom:8px; flex:1 1 100%;">Miscarriage Details</div>
                  <div class="edit-field">
                    <label>Date</label>
                    <input type="date" name="miscarriage_date" value="<?php echo htmlspecialchars(isset($b['miscarriage_date']) ? $b['miscarriage_date'] : ''); ?>">
                  </div>
                  <div class="edit-field">
                    <label>Time</label>
                    <input type="time" name="miscarriage_time" value="<?php echo htmlspecialchars(isset($b['miscarriage_time']) ? $b['miscarriage_time'] : ''); ?>">
                  </div>
                </div>
              </div>

              <!-- ── SECTION 4: Baby Registration Benefits ── -->
              <?php
                $bhb     = isset($b['baby_health_book']) ? $b['baby_health_book'] : '';
                $bhb_ext = $bhb ? strtolower(pathinfo($bhb, PATHINFO_EXTENSION)) : '';
                $db_bens = array(
                  'benefit_baby_book'    => array('label' => 'MamaBe Baby Book (Parenting Guide)',                    'color' => '#1a7a9a', 'bg' => '#f0f8fc', 'at' => 'benefit_baby_book_at'),
                  'benefit_free_diapers' => array('label' => 'Free Items',                 'color' => '#2a7a4a', 'bg' => '#f0fff5', 'at' => 'benefit_free_items_baby_at'),
                  'benefit_50_off_baby'  => array('label' => '50% Off Register-Day Purchase','color' => '#d07a00', 'bg' => '#fffbf0', 'at' => 'benefit_50_off_baby_at'),
                );

                $db_saved_skus = isset($b['benefit_50_off_baby_items']) ? array_map('trim', explode(',', $b['benefit_50_off_baby_items'])) : array();
                $db_free_locked = $_admin_override ? false : (!empty($b['benefit_free_items_baby_at']) && $b['benefit_free_items_baby_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($b['benefit_free_items_baby_at'])) >= 86400);
              ?>
              <div id="baby-reg-benefits-<?php echo $bid; ?>" style="flex:1 1 300px; display:<?php echo ($is_delivered == 1) ? 'flex' : 'none'; ?>; flex-wrap:wrap; gap:0; align-items:stretch; border-left:1px solid #d0dff0;">
                
                <!-- Baby Health Book -->
                <div style="flex:0 0 160px; background:#f0f8ff; border-right:1px solid #c8ddf0; padding:12px; text-align:center;">
                  <div style="font-size:0.74em; font-weight:bold; color:#1a7a9a; text-transform:uppercase; letter-spacing:1px; margin-bottom:7px;">Baby Health Book</div>
                  <div id="preview-bb-<?php echo $bid; ?>" style="position:relative; display:inline-block; vertical-align:top;">
                  <?php if ($bhb && $bhb_ext !== 'pdf'): ?>
                    <a href="<?php echo htmlspecialchars(mamabe_file_url($bhb)); ?>" target="_blank">
                      <img src="<?php echo htmlspecialchars(mamabe_file_url($bhb)); ?>" style="max-width:120px; max-height:120px; border:1px solid #a0c8d8; border-radius:6px; display:block; margin:0 auto 7px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    </a>
                  <?php elseif ($bhb && $bhb_ext === 'pdf'): ?>
                    <a href="<?php echo htmlspecialchars(mamabe_file_url($bhb)); ?>" target="_blank"
                       style="display:inline-block; padding:7px 12px; background:#e8f4f8; border:1px solid #a0c8d8; border-radius:6px; color:#1a7a9a; font-size:0.8em; margin-bottom:7px; text-decoration:none;">&#128196; View PDF</a><br>
                  <?php else: ?>
                    <div style="width:120px; height:100px; border:2px dashed #a0c8d8; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#80b8c8; font-size:0.76em; margin:0 auto 7px; background:#fff;">
                      <span style="font-size:1.5em; margin-bottom:3px;">&#128247;</span>
                      No file yet
                    </div>
                  <?php endif; ?>
                  <?php if ($bhb && isset($mamab_autho) && $mamab_autho == '1'): ?>
                  <button type="button" onclick="deletePhoto(<?php echo $bid; ?>, 'baby_health_book')" title="Delete photo"
                          style="position:absolute; top:-6px; right:-6px; width:22px; height:22px; border-radius:50%; background:#c82333; color:#fff; border:2px solid #fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.35); line-height:1; padding:0;">
                    &times;
                  </button>
                  <?php endif; ?>
                  </div>
                  <br>
                  <?php
                    $bb_locked = $_admin_override ? false : (!empty($b['benefit_baby_book_at']) && $b['benefit_baby_book_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($b['benefit_baby_book_at'])) >= 86400);
                  ?>
                  <?php if (!$bb_locked && !$bhb): ?>
                  <label style="display:inline-block; padding:4px 10px; background:#1a7a9a; color:#fff; border-radius:4px; font-size:0.74em; cursor:pointer; font-weight:bold;">
                    &#8593; Upload
                    <input type="file" name="baby_health_book" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handleFileUpload(this, 'benefit_baby_book', 'preview-bb-<?php echo $bid; ?>')">
                  </label>
                  <?php endif; ?>
                  <?php if ($bhb): ?><div style="font-size:0.71em; color:#888; margin-top:4px;"><?php echo htmlspecialchars(basename($bhb)); ?></div><?php endif; ?>
                </div>

                <!-- Benefits checkboxes -->
                <div style="flex:1; min-width:190px; padding:12px 14px; background:#fafafa;">
                  <div style="font-size:0.74em; font-weight:bold; color:#2a6496; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; border-bottom:1px solid #b0d8e8; padding-bottom:4px;">Benefits Given</div>
                  <?php foreach ($db_bens as $dfname => $dfdata):
                    $df_checked = !empty($b[$dfname]);
                    $df_ts      = (!empty($b[$dfdata['at']]) && $b[$dfdata['at']] !== '0000-00-00 00:00:00') ? $b[$dfdata['at']] : '';
                    $df_locked  = $_admin_override ? false : ($df_checked && $df_ts && (time() - strtotime($df_ts)) >= 86400);
                  ?>
                  <?php if ($dfname === 'benefit_50_off_baby'): ?>
                  <div style="margin-bottom:6px; border:1px solid <?php echo $df_checked ? $dfdata['color'] : '#e0e0e0'; ?>; border-radius:6px; background:<?php echo $df_checked ? $dfdata['bg'] : '#fff'; ?>; <?php echo $df_locked ? 'opacity:0.7; pointer-events:none;' : ''; ?>" id="wrap_50off_baby_<?php echo $bid; ?>">
                    <div style="padding:6px 10px; font-weight:bold; color:#333; font-size:0.86em; border-bottom:1px dashed #f0d090; display:flex; justify-content:space-between; align-items:center;">
                      <span><?php echo htmlspecialchars($dfdata['label']); ?></span>
                      <input type="checkbox" name="benefit_50_off_baby" value="1" <?php echo $df_checked ? 'checked' : ''; ?> style="display:none;">
                      <?php if ($df_checked && $df_ts): ?>
                      <span style="text-align:right; line-height:1.3;">
                        <span style="display:block; font-size:0.82em; font-weight:bold; color:<?php echo $dfdata['color']; ?>;">&#10003; Done</span>
                        <span style="display:block; font-size:0.75em; color:#999; font-weight:normal;"><?php echo htmlspecialchars($df_ts); ?></span>
                      </span>
                      <?php endif; ?>
                    </div>
                    <div id="sku_items_baby_<?php echo $bid; ?>" style="display:block; padding:8px 10px;">
                      <div style="font-size:0.71em; color:#888; margin-bottom:4px; font-weight:bold; text-transform:uppercase;">Items Purchased (After 50%)</div>
                      <div id="sku_items_baby_container_<?php echo $bid; ?>">
                      <?php foreach ($db_saved_skus as $scode):
                        if ($scode === '') continue;
                        $sname  = isset($master_skus['baby'][$scode])  ? $master_skus['baby'][$scode]  : $scode;
                        $sprice = isset($master_prices['baby'][$scode]) ? $master_prices['baby'][$scode] : 0;
                      ?>
                      <div style="display:flex; align-items:center; gap:8px; margin-bottom:3px; font-size:0.8em; color:#444;">
                        <input type="hidden" name="benefit_50_off_baby_items[]" value="<?php echo htmlspecialchars($scode); ?>">
                        <span style="color:#aaa; font-family:monospace; font-size:0.88em; min-width:82px;"><?php echo htmlspecialchars($scode); ?></span>
                        <span style="flex:1;"><?php echo htmlspecialchars($sname); ?></span>
                        <span style="color:#888; white-space:nowrap;"><?php echo $sprice > 0 ? 'RM ' . number_format($sprice, 2) : 'RM –'; ?></span>
                      </div>
                      <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                  <?php elseif ($dfname === 'benefit_free_diapers'): ?>
                  <?php echo mamabe_render_free_items($bid, 'benefit_free_items_baby', isset($b['benefit_free_items_baby']) ? $b['benefit_free_items_baby'] : '', isset($b['benefit_free_items_baby_at']) ? $b['benefit_free_items_baby_at'] : null, $free_items_baby, $db_free_locked); ?>
                  <?php else: ?>
                  <label style="display:flex; align-items:center; gap:9px; margin-bottom:6px; cursor:pointer; padding:6px 10px; border-radius:6px; border:1px solid <?php echo $df_checked ? $dfdata['color'] : '#e0e0e0'; ?>; background:<?php echo $df_checked ? $dfdata['bg'] : '#fff'; ?>; <?php echo $df_locked ? 'opacity:0.7; pointer-events:none;' : ''; ?>"
                         onchange="this.style.borderColor=this.querySelector('input').checked?'<?php echo $dfdata['color']; ?>':'#e0e0e0'; this.style.background=this.querySelector('input').checked?'<?php echo $dfdata['bg']; ?>':'#fff';">
                    <input type="checkbox" name="<?php echo $dfname; ?>" value="1" <?php echo $df_checked ? 'checked' : ''; ?>
                           style="width:14px; height:14px; accent-color:<?php echo $dfdata['color']; ?>; cursor:pointer;">
                    <span style="font-size:0.86em; color:#333;"><?php echo htmlspecialchars($dfdata['label']); ?></span>
                    <?php if ($df_checked && $df_ts): ?>
                    <span style="margin-left:auto; text-align:right; line-height:1.3;">
                      <span style="display:block; font-size:0.71em; font-weight:bold; color:<?php echo $dfdata['color']; ?>;">&#10003; Done</span>
                      <span style="display:block; font-size:0.68em; color:#999;"><?php echo htmlspecialchars($df_ts); ?></span>
                    </span>
                    <?php endif; ?>
                  </label>
                  <?php endif; ?>
                  <?php endforeach; ?>
                  <div style="margin-top:10px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                      <div style="flex:1;">
                        <div style="font-size:0.74em; font-weight:bold; color:#555; margin-bottom:3px;">Receipt No.</div>
                        <input type="text" name="baby_receipt_no" id="baby_receipt_no_<?php echo $bid; ?>" placeholder="e.g. RCP-00123"
                               value="<?php echo htmlspecialchars(isset($b['baby_receipt_no']) ? $b['baby_receipt_no'] : ''); ?>"
                               <?php if (!empty($b['baby_receipt_no'])): ?>readonly<?php endif; ?>
                               style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.86em; width:100%; box-sizing:border-box;<?php echo !empty($b['baby_receipt_no']) ? 'background:#f5f5f5; color:#555;' : ''; ?>">
                        <input type="hidden" name="baby_receipt_price_mismatch" id="baby_receipt_price_mismatch_<?php echo $bid; ?>"
                               value="<?php echo htmlspecialchars(isset($b['baby_receipt_price_mismatch']) ? $b['baby_receipt_price_mismatch'] : ''); ?>">
                      </div>
                      <div style="margin-top:14px; display:flex; gap:5px;">
                        <button type="button" class="alpro-btn alpro-btn-blue" onclick="verifyReceipt(this, <?php echo $bid; ?>, 'baby')" style="padding:4px 10px; font-size:0.82em; <?php echo !empty($b['baby_receipt_no']) ? 'display:none;' : ''; ?>">Verify</button>
                        <?php if (!empty($b['baby_receipt_no'])): ?>
                        <button type="button" class="alpro-btn alpro-btn-grey" onclick="viewReceipt('<?php echo htmlspecialchars(addslashes($b['baby_receipt_no'])); ?>')" style="padding:4px 10px; font-size:0.82em;">&#128196; View</button>
                        <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?>
                        <button type="button" class="alpro-btn alpro-btn-orange" onclick="reVerifyReceipt(this, <?php echo $bid; ?>, 'baby')" style="padding:4px 10px; font-size:0.82em;" title="Admin: Re-verify and repopulate SKU items">&#8635; Re-verify</button>
                        <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if (!empty($b['baby_receipt_price_mismatch'])): ?>
                    <div id="baby_receipt_mismatch_badge_<?php echo $bid; ?>" style="margin-top:6px; background:#fff3f3; border:1px solid #ef9a9a; border-radius:4px; padding:6px 10px; font-size:0.8em; color:#c62828;">
                      <span style="font-weight:bold;">&#9888; Mismatch / Issue:</span><br>
                      <span style="white-space:pre-line;"><?php echo htmlspecialchars($b['baby_receipt_price_mismatch']); ?></span>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

              </div>

              </div> <!-- End of inner flex container -->

              <!-- Twin section -->
              <?php
              $tw_data = null;
              if (!empty($b['twin_id']) && $b['twin_id'] > 0) {
                  $tw_r = $conn->prepare("SELECT * FROM mamabe_baby WHERE id=? AND mama_id=? AND recycle=0 LIMIT 1");
                  $tw_r->bind_param("ii", $b['twin_id'], $id);
                  $tw_r->execute();
                  $tw_data = $tw_r->get_result()->fetch_assoc();
                  $tw_r->close();
              }
              $tw_sens_arr = ($tw_data && !empty($tw_data['sensitivity'])) ? array_map('trim', explode(',', $tw_data['sensitivity'])) : array();
              ?>
              <input type="hidden" name="add_twin" id="add_twin_<?php echo $bid; ?>" value="<?php echo $tw_data ? '1' : '0'; ?>">
              <div id="twin-section-<?php echo $bid; ?>" style="display:<?php echo $tw_data ? 'block' : 'none'; ?>; border-top:2px solid #128C7E; background:#f5fcfa; padding:12px 14px;">
                
                <div style="flex:1 1 100%; background:#fff; border:1px solid #d0dff0; border-radius:5px; overflow:hidden;">
                  <div style="background:#128C7E; color:#fff; font-size:0.78em; font-weight:bold; text-transform:uppercase; letter-spacing:1px; padding:5px 12px;">Twin Baby Information | Twin Baby Registration Benefits</div>
                  <div style="display:flex; flex-wrap:wrap; gap:0; align-items:stretch;">

                    <!-- Twin Baby Info -->
                    <div style="flex:1 1 300px; padding:12px 14px;">
                      <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:10px;">
                        <div style="flex:1 1 160px;">
                          <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">Baby Status</label>
                          <?php $tw_delivered_val = $tw_data ? (int)$tw_data['is_delivered'] : 0; ?>
                          <select name="twin_is_delivered" onchange="toggleTwinDelivery(<?php echo $bid; ?>, this.value)"
                                  style="width:100%; padding:5px; border-radius:3px; border:1px solid #ccc; font-size:0.9em;">
                            <option value="0"<?php echo $tw_delivered_val == 0 ? ' selected' : ''; ?>>Expected</option>
                            <option value="1"<?php echo $tw_delivered_val == 1 ? ' selected' : ''; ?>>Safely Delivered</option>
                            <option value="2"<?php echo $tw_delivered_val == 2 ? ' selected' : ''; ?>>Miscarriage</option>
                          </select>
                        </div>
                        <div style="flex:2 1 260px;">
                          <label style="display:block; font-size:0.82em; font-weight:bold; color:#444; margin-bottom:4px;">Remarks</label>
                          <textarea name="twin_remarks" rows="2"
                                    style="width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:3px; font-size:0.88em; resize:vertical; box-sizing:border-box;"><?php echo $tw_data ? htmlspecialchars($tw_data['remarks'] ?? '') : ''; ?></textarea>
                        </div>
                      </div>

                      <!-- Twin Baby Info (shows when Delivered) -->
                      <div id="twin-baby-info-wrapper-<?php echo $bid; ?>" style="display:<?php echo $tw_delivered_val == 1 ? 'block' : 'none'; ?>;">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                          <div class="edit-field"><label>Baby Name</label><input type="text" name="twin_baby_name" placeholder="Twin baby name" value="<?php echo $tw_data ? htmlspecialchars($tw_data['baby_name'] ?? '') : ''; ?>"></div>
                          <div class="edit-field"><label>MyKid (IC)</label><input type="text" name="twin_mykid" class="alpro-mono" value="<?php echo $tw_data ? htmlspecialchars($tw_data['mykid'] ?? '') : ''; ?>"></div>
                          <div class="edit-field"><label>Date of Birth (Actual)</label><input type="date" name="twin_dob" value="<?php echo $tw_data ? htmlspecialchars($tw_data['dob'] ?? '') : ''; ?>"></div>
                          <div class="edit-field"><label>Gender</label>
                            <?php $tw_gender_val = $tw_data ? $tw_data['gender'] : ''; ?>
                            <select name="twin_gender">
                              <option value="">– Select –</option>
                              <option value="Male"<?php echo $tw_gender_val === 'Male' ? ' selected' : ''; ?>>Male</option>
                              <option value="Female"<?php echo $tw_gender_val === 'Female' ? ' selected' : ''; ?>>Female</option>
                            </select>
                          </div>
                        </div>
                        <div class="edit-field full" style="margin-top:10px;">
                          <label><b>Baby Sensitivity Conditions</b> <span style="color:red">*</span> <span style="font-weight:normal;color:#888;">(Tick ✓)</span></label>
                          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px 15px; margin-top:5px;">
                          <?php foreach ($sens_options as $sopt): ?>
                            <label style="font-weight:normal; display:flex; align-items:flex-start; gap:6px; cursor:pointer; line-height:1.2;">
                              <input type="checkbox" name="twin_sensitivity[]" value="<?php echo htmlspecialchars($sopt); ?>"
                                <?php echo in_array($sopt, $tw_sens_arr) ? 'checked' : ''; ?>
                                style="margin-top:2px; width:auto;">
                              <span style="flex:1;"><?php echo htmlspecialchars($sopt); ?></span>
                            </label>
                          <?php endforeach; ?>
                          </div>
                        </div>
                      </div>

                      <!-- Miscarriage Details (shows when Miscarriage) -->
                      <div id="twin-miscarriage-info-wrapper-<?php echo $bid; ?>" style="display:<?php echo $tw_delivered_val == 2 ? 'flex' : 'none'; ?>;flex-wrap:wrap;gap:10px;">
                        <div style="font-size:0.78em; font-weight:bold; color:#c82333; text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid #f5c6cb; padding-bottom:4px; margin-bottom:8px; flex:1 1 100%;">Miscarriage Details</div>
                        <div class="edit-field">
                          <label>Date</label>
                          <input type="date" name="twin_miscarriage_date" value="<?php echo $tw_data ? htmlspecialchars($tw_data['miscarriage_date'] ?? '') : ''; ?>">
                        </div>
                        <div class="edit-field">
                          <label>Time</label>
                          <input type="time" name="twin_miscarriage_time" value="<?php echo $tw_data ? htmlspecialchars($tw_data['miscarriage_time'] ?? '') : ''; ?>">
                        </div>
                      </div>
                    </div>

                    <!-- Twin Baby Registration Benefits -->
                    <?php $tw_show_benefits = ($tw_delivered_val == 1); ?>
                    <div id="twin-baby-reg-benefits-<?php echo $bid; ?>" style="flex:1 1 300px; display:<?php echo $tw_show_benefits ? 'flex' : 'none'; ?>; flex-wrap:wrap; gap:0; align-items:stretch; border-left:1px solid #d0dff0;">

                      <!-- Baby Health Book -->
                      <?php
                      $tw_bhb = ($tw_data && !empty($tw_data['baby_health_book'])) ? $tw_data['baby_health_book'] : '';
                      $tw_bhb_ext = $tw_bhb ? strtolower(pathinfo($tw_bhb, PATHINFO_EXTENSION)) : '';
                      ?>
                      <div style="flex:0 0 160px; background:#f0f8ff; border-right:1px solid #c8ddf0; padding:12px; text-align:center;">
                        <div style="font-size:0.74em; font-weight:bold; color:#1a7a9a; text-transform:uppercase; letter-spacing:1px; margin-bottom:7px;">Baby Health Book</div>
                        <div id="preview-tw-<?php echo $bid; ?>" style="position:relative; display:inline-block; vertical-align:top;">
                          <?php if ($tw_bhb): ?>
                            <?php if (in_array($tw_bhb_ext, array('jpg','jpeg','png'))): ?>
                              <img src="<?php echo htmlspecialchars(mamabe_file_url($tw_bhb)); ?>" style="width:120px; height:100px; object-fit:cover; border-radius:8px; display:block; margin:0 auto 7px;">
                            <?php else: ?>
                              <div style="width:120px; height:100px; border:2px dashed #a0c8d8; border-radius:8px; display:flex; align-items:center; justify-content:center; margin:0 auto 7px; background:#e8f0fe;">
                                <span style="color:#2a6496; font-size:0.8em; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:110px;" title="<?php echo htmlspecialchars(basename($tw_bhb)); ?>">&#128196; <?php echo htmlspecialchars(basename($tw_bhb)); ?></span>
                              </div>
                            <?php endif; ?>
                          <?php else: ?>
                          <div style="width:120px; height:100px; border:2px dashed #a0c8d8; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#80b8c8; font-size:0.76em; margin:0 auto 7px; background:#fff;">
                            <span style="font-size:1.5em; margin-bottom:3px;">&#128247;</span>
                            No file yet
                          </div>
                          <?php endif; ?>
                          <?php if ($tw_bhb && isset($mamab_autho) && $mamab_autho == '1'): ?>
                          <button type="button" onclick="deletePhoto(<?php echo (int)$tw_data['id']; ?>, 'baby_health_book')" title="Delete photo"
                                  style="position:absolute; top:-6px; right:-6px; width:22px; height:22px; border-radius:50%; background:#c82333; color:#fff; border:2px solid #fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.35); line-height:1; padding:0;">
                            &times;
                          </button>
                          <?php endif; ?>
                        </div>
                        <br>
                        <!-- Twin benefits are new, so it's not locked when creating -->
                        <?php if (!$tw_bhb): ?>
                        <label style="display:inline-block; padding:4px 10px; background:#1a7a9a; color:#fff; border-radius:4px; font-size:0.74em; cursor:pointer; font-weight:bold;">
                          &#8593; Upload
                          <input type="file" name="twin_baby_health_book" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handleFileUpload(this, 'twin_benefit_baby_book', 'preview-tw-<?php echo $bid; ?>')">
                        </label>
                        <?php endif; ?>
                      </div>

                      <!-- Benefits checkboxes -->
                      <div style="flex:1; min-width:190px; padding:12px 14px; background:#fafafa;">
                        <div style="font-size:0.74em; font-weight:bold; color:#2a6496; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; border-bottom:1px solid #b0d8e8; padding-bottom:4px;">Benefits Given</div>
                        <?php
                        $tw_bens = array(
                          'twin_benefit_baby_book'    => array('label' => 'MamaBe Baby Book (Parenting Guide)',                    'color' => '#1a7a9a', 'bg' => '#f0f8fc', 'db_col' => 'benefit_baby_book', 'at' => 'benefit_baby_book_at'),
                          'twin_benefit_free_diapers' => array('label' => 'Free Items',                 'color' => '#2a7a4a', 'bg' => '#f0fff5', 'db_col' => 'benefit_free_items_baby', 'at' => 'benefit_free_items_baby_at'),
                          'twin_benefit_50_off_baby'  => array('label' => '50% Off Register-Day Purchase','color' => '#d07a00', 'bg' => '#fffbf0', 'db_col' => 'benefit_50_off_baby', 'at' => 'benefit_50_off_baby_at'),
                        );
                        foreach ($tw_bens as $tfname => $tfdata):
                        ?>
                        <?php
                        $tw_50off_items_arr = ($tw_data && !empty($tw_data['benefit_50_off_baby_items'])) ? array_map('trim', explode(',', $tw_data['benefit_50_off_baby_items'])) : array();
                        $tw_50off_checked = $tw_data && !empty($tw_data['benefit_50_off_baby']);
                        $tw_50off_ts = ($tw_50off_checked && !empty($tw_data['benefit_50_off_baby_at']) && $tw_data['benefit_50_off_baby_at'] !== '0000-00-00 00:00:00') ? $tw_data['benefit_50_off_baby_at'] : '';
                        ?>
                        <?php if ($tfname === 'twin_benefit_50_off_baby'): ?>
                        <?php $tw_50off_show = ($tw_50off_checked && !empty($tw_50off_items_arr)) ? 'block' : 'none'; ?>
                        <div style="margin-bottom:6px; border:1px solid <?php echo $tw_50off_checked ? '#d07a00' : '#e0e0e0'; ?>; border-radius:6px; background:<?php echo $tw_50off_checked ? '#fffbf0' : '#fff'; ?>;" id="wrap_50off_twin_<?php echo $bid; ?>">
                          <div style="padding:6px 10px; font-weight:bold; color:#333; font-size:0.86em; border-bottom:1px dashed #f0d090; display:flex; justify-content:space-between; align-items:center;">
                            <span><?php echo htmlspecialchars($tfdata['label']); ?></span>
                            <input type="checkbox" name="twin_benefit_50_off_baby" value="1" <?php echo $tw_50off_checked ? 'checked' : ''; ?> style="display:none;">
                            <?php if ($tw_50off_checked && $tw_50off_ts): ?>
                            <span style="text-align:right; line-height:1.3;">
                              <span style="display:block; font-size:0.82em; font-weight:bold; color:#d07a00;">&#10003; Done</span>
                              <span style="display:block; font-size:0.75em; color:#999; font-weight:normal;"><?php echo htmlspecialchars($tw_50off_ts); ?></span>
                            </span>
                            <?php endif; ?>
                          </div>
                          <div id="sku_items_twin_<?php echo $bid; ?>" style="display:block; padding:8px 10px;">
                            <div style="font-size:0.71em; color:#888; margin-bottom:4px; font-weight:bold; text-transform:uppercase;">Items Purchased (After 50%)</div>
                            <div id="sku_items_twin_container_<?php echo $bid; ?>">
                            <?php foreach ($tw_50off_items_arr as $scode):
                              if ($scode === '') continue;
                              $sname  = isset($master_skus['baby'][$scode])  ? $master_skus['baby'][$scode]  : $scode;
                              $sprice = isset($master_prices['baby'][$scode]) ? $master_prices['baby'][$scode] : 0;
                            ?>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:3px; font-size:0.8em; color:#444;">
                              <input type="hidden" name="twin_benefit_50_off_baby_items[]" value="<?php echo htmlspecialchars($scode); ?>">
                              <span style="color:#aaa; font-family:monospace; font-size:0.88em; min-width:82px;"><?php echo htmlspecialchars($scode); ?></span>
                              <span style="flex:1;"><?php echo htmlspecialchars($sname); ?></span>
                              <span style="color:#888; white-space:nowrap;"><?php echo $sprice > 0 ? 'RM ' . number_format($sprice, 2) : 'RM –'; ?></span>
                            </div>
                            <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                        <?php elseif ($tfname === 'twin_benefit_free_diapers'): ?>
                        <?php
                        $tw_free_locked = $_admin_override ? false : ($tw_data && !empty($tw_data['benefit_free_items_baby_at']) && $tw_data['benefit_free_items_baby_at'] !== '0000-00-00 00:00:00' && (time() - strtotime($tw_data['benefit_free_items_baby_at'])) >= 86400);
                        echo mamabe_render_free_items($bid, 'twin_benefit_free_items_baby', ($tw_data && isset($tw_data['benefit_free_items_baby'])) ? $tw_data['benefit_free_items_baby'] : '', ($tw_data && isset($tw_data['benefit_free_items_baby_at'])) ? $tw_data['benefit_free_items_baby_at'] : null, $free_items_baby, $tw_free_locked);
                        ?>
                        <?php else: ?>
                        <?php
                        $tw_ben_checked = $tw_data && !empty($tw_data[$tfdata['db_col']]);
                        $tw_ben_ts = ($tw_ben_checked && !empty($tw_data[$tfdata['at']]) && $tw_data[$tfdata['at']] !== '0000-00-00 00:00:00') ? $tw_data[$tfdata['at']] : '';
                        ?>
                        <label style="display:flex; align-items:center; gap:9px; margin-bottom:6px; cursor:pointer; padding:6px 10px; border-radius:6px; border:1px solid <?php echo $tw_ben_checked ? $tfdata['color'] : '#e0e0e0'; ?>; background:<?php echo $tw_ben_checked ? $tfdata['bg'] : '#fff'; ?>;"
                               onchange="this.style.borderColor=this.querySelector('input').checked?'<?php echo $tfdata['color']; ?>':'#e0e0e0'; this.style.background=this.querySelector('input').checked?'<?php echo $tfdata['bg']; ?>':'#fff';">
                          <input type="checkbox" name="<?php echo $tfname; ?>" value="1"
                                 <?php echo $tw_ben_checked ? 'checked' : ''; ?>
                                 style="width:14px; height:14px; accent-color:<?php echo $tfdata['color']; ?>; cursor:pointer;">
                          <span style="font-size:0.86em; color:#333;"><?php echo htmlspecialchars($tfdata['label']); ?></span>
                          <?php if ($tw_ben_checked && $tw_ben_ts): ?>
                          <span style="margin-left:auto; text-align:right; line-height:1.3;">
                            <span style="display:block; font-size:0.71em; font-weight:bold; color:<?php echo $tfdata['color']; ?>;">&#10003; Done</span>
                            <span style="display:block; font-size:0.68em; color:#999;"><?php echo htmlspecialchars($tw_ben_ts); ?></span>
                          </span>
                          <?php endif; ?>
                        </label>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <div style="margin-top:10px;">
                          <div style="display:flex; align-items:center; gap:8px;">
                            <div style="flex:1;">
                              <div style="font-size:0.74em; font-weight:bold; color:#555; margin-bottom:3px;">Receipt No.</div>
                              <input type="text" name="twin_baby_receipt_no" id="twin_baby_receipt_no_<?php echo $bid; ?>" placeholder="e.g. RCP-00123"
                                     value="<?php echo $tw_data ? htmlspecialchars($tw_data['baby_receipt_no']) : ''; ?>"
                                     <?php if ($tw_data && !empty($tw_data['baby_receipt_no'])): ?>readonly<?php endif; ?>
                                     style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.86em; width:100%; box-sizing:border-box;<?php echo ($tw_data && !empty($tw_data['baby_receipt_no'])) ? 'background:#f5f5f5; color:#555;' : ''; ?>">
                              <input type="hidden" name="twin_baby_receipt_price_mismatch" id="twin_baby_receipt_price_mismatch_<?php echo $bid; ?>"
                                     value="<?php echo ($tw_data && isset($tw_data['baby_receipt_price_mismatch'])) ? htmlspecialchars($tw_data['baby_receipt_price_mismatch']) : ''; ?>">
                            </div>
                            <div style="margin-top:14px; display:flex; gap:5px;">
                              <button type="button" class="alpro-btn alpro-btn-blue" onclick="verifyReceipt(this, <?php echo $bid; ?>, 'twin')" style="padding:4px 10px; font-size:0.82em; <?php echo ($tw_data && !empty($tw_data['baby_receipt_no'])) ? 'display:none;' : ''; ?>">Verify</button>
                              <?php if ($tw_data && !empty($tw_data['baby_receipt_no'])): ?>
                              <button type="button" class="alpro-btn alpro-btn-grey" onclick="viewReceipt('<?php echo htmlspecialchars(addslashes($tw_data['baby_receipt_no'])); ?>')" style="padding:4px 10px; font-size:0.82em;">&#128196; View</button>
                              <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1','3'), true)): ?>
                              <button type="button" class="alpro-btn alpro-btn-orange" onclick="reVerifyReceipt(this, <?php echo $bid; ?>, 'twin')" style="padding:4px 10px; font-size:0.82em;" title="Admin: Re-verify and repopulate SKU items">&#8635; Re-verify</button>
                              <?php endif; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                          <?php if ($tw_data && !empty($tw_data['baby_receipt_price_mismatch'])): ?>
                          <div id="twin_baby_receipt_mismatch_badge_<?php echo $bid; ?>" style="margin-top:6px; background:#fff3f3; border:1px solid #ef9a9a; border-radius:4px; padding:6px 10px; font-size:0.8em; color:#c62828;">
                            <span style="font-weight:bold;">&#9888; Mismatch / Issue:</span><br>
                            <span style="white-space:pre-line;"><?php echo htmlspecialchars($tw_data['baby_receipt_price_mismatch']); ?></span>
                          </div>
                          <?php endif; ?>
                        </div>
                      </div>

                    </div>

                  </div>
                </div>

              </div>

              <?php if (!empty($b['baby_filled_by'])): ?>
              <div style="padding:4px 12px 6px; font-size:0.75em; color:#888; border-top:1px dashed #e0e0e0; text-align:right;">
                &#9998; Baby status section filled by <strong><?php echo htmlspecialchars($b['baby_filled_by']); ?></strong>
                <?php if (!empty($b['baby_filled_at']) && $b['baby_filled_at'] !== '0000-00-00 00:00:00'): ?>
                on <?php echo htmlspecialchars($b['baby_filled_at']); ?>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div><!-- /row 2 -->

          </div><!-- /column container -->

          <!-- Buttons -->
          <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <input class="alpro-btn alpro-btn-blue" type="submit" name="update_baby"
                   id="update_btn_<?php echo $bid; ?>" value="<?php echo $tw_data ? 'Update & Save Twin' : 'Update'; ?>"
                   style="width:auto; padding-left:15px; padding-right:15px;">
            <button class="alpro-btn alpro-btn-green" type="button"
                    style="display:<?php echo $tw_data ? 'none' : 'inline-block'; ?>; width:auto;padding-left:15px;padding-right:15px;background:#128C7E;color:#fff;border-color:#128C7E;"
                    id="twin_btn_<?php echo $bid; ?>"
                    data-has-twin="<?php echo $tw_data ? '1' : '0'; ?>"
                    onclick="toggleTwinSection(<?php echo $bid; ?>)">+ Twin</button>
            <button class="alpro-btn alpro-btn-grey" type="button" style="width:auto; padding-left:15px; padding-right:15px;"
                    onclick="toggleEdit(<?php echo $bid; ?>)">Cancel</button>
          </div>

        </form>

      </td>
    </tr>

    <?php endforeach; else: ?>
    <tr>
      <td colspan="9" class="alpro-center alpro-muted">
        No baby record yet.
        <a href="javascript:void(0)" onclick="toggleAddBaby()">Add one now.</a>
      </td>
    </tr>
    <?php endif; ?>
  </table>
  <?php if (isset($mamab_autho) && $mamab_autho == '1'): ?>
  <!-- One shared hidden form for every row's Delete button - JS fills in which baby before submitting it, so the
       table rows themselves don't each need their own <form> (a <form> can't legally sit inside a <tr>/<td> as a
       direct table child). -->
  <form method="post" id="deleteBabyForm" style="display:none;">
    <input type="hidden" name="delete_baby" value="1">
    <input type="hidden" name="baby_id" id="deleteBabyId">
  </form>
  <!-- Same shared-form pattern, for the Pregnancy Record Book / Baby Health Book "Delete" buttons on each photo. -->
  <form method="post" id="deletePhotoForm" style="display:none;">
    <input type="hidden" name="delete_photo" value="1">
    <input type="hidden" name="photo_baby_id" id="deletePhotoBabyId">
    <input type="hidden" name="photo_field" id="deletePhotoField">
  </form>
  <script>
  function deleteBaby(bid) {
      // form.submit() does NOT fire the form's submit event (so an onsubmit="confirm(...)" there never runs) -
      // the confirm has to happen here, before submit() is called, not rely on the form to ask it.
      if (!confirm('Delete this baby record? This cannot be undone.')) return;
      document.getElementById('deleteBabyId').value = bid;
      document.getElementById('deleteBabyForm').submit();
  }
  function deletePhoto(babyId, field) {
      if (!confirm('Delete this photo? This cannot be undone.')) return;
      document.getElementById('deletePhotoBabyId').value = babyId;
      document.getElementById('deletePhotoField').value = field;
      document.getElementById('deletePhotoForm').submit();
  }
  </script>
  <?php endif; ?>
</fieldset>

<script>
var isAlproBabyOutlet = <?php echo $is_alpro_baby_outlet ? 'true' : 'false'; ?>;
var FREE_ITEMS_MAMA = <?php echo json_encode($free_items_mama, JSON_UNESCAPED_UNICODE); ?>; // {code: name}
var FREE_ITEMS_BABY = <?php echo json_encode($free_items_baby, JSON_UNESCAPED_UNICODE); ?>; // {code: name}

function mamabeAppendFreeItem(containerId, fieldName, code, name) {
    var container = document.getElementById(containerId);
    if (!container) return;
    var cb = container.querySelector('input[type="checkbox"][value="' + code + '"]');
    if (cb) { cb.checked = true; }
    var display = document.getElementById(containerId.replace('_container_', '_display_'));
    if (display) {
        display.style.borderColor = '#2a7a4a';
        display.style.background = '#f0fff5';
        var hint = display.querySelector('.auto-receipt-hint');
        if (hint) { hint.outerHTML = '<span style="margin-left:auto; font-size:0.71em; font-weight:bold; color:#2a7a4a;">&#10003; Auto-ticked</span>'; }
    }
}

function toggle50OffNew(cb) {
    var wrap  = document.getElementById('wrap_benefit_50_off_new');
    var items = document.getElementById('sku_items_new');
    if (cb.checked) {
        wrap.style.borderColor = '#d07a00';
        wrap.style.background  = '#fffbf0';
        items.style.display    = 'block';
    } else {
        wrap.style.borderColor = '#e0e0e0';
        wrap.style.background  = '#fff';
        items.style.display    = 'none';
        var boxes = items.querySelectorAll('input[type=checkbox]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
    }
}

function toggle50OffBaby(cb, bid) {
    var wrap  = document.getElementById('wrap_50off_' + bid);
    var items = document.getElementById('sku_items_' + bid);
    if (cb.checked) {
        wrap.style.borderColor = '#d07a00';
        wrap.style.background  = '#fffbf0';
        items.style.display    = 'block';
    } else {
        wrap.style.borderColor = '#e0e0e0';
        wrap.style.background  = '#fff';
        items.style.display    = 'none';
        var boxes = items.querySelectorAll('input[type=checkbox]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
    }
}

function toggleBenefits(bid) {
    var row = document.getElementById('benefits-row-' + bid);
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}

// Auto-open edit row if redirected back after save
(function() {
    var params = new URLSearchParams(window.location.search);
    var bid = params.get('benefits') || params.get('baby_benefits');
    if (bid) {
        var row = document.getElementById('edit-row-' + bid);
        if (row) { row.style.display = 'table-row'; }
    }
})();

function toggleMamaEdit(show) {
    document.getElementById('mama-view').style.display = show ? 'none' : 'block';
    document.getElementById('mama-edit').style.display = show ? 'block' : 'none';
}

function toggleAddBaby() {
    var sec = document.getElementById('add-baby-section');
    sec.style.display = (sec.style.display === 'none' || sec.style.display === '') ? 'block' : 'none';
}

function toggleEdit(bid) {
    var row = document.getElementById('edit-row-' + bid);
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}

function toggleDelivery(bid, val) {
    var babySec  = document.getElementById('baby-info-wrapper-' + bid);
    var miscSec  = document.getElementById('miscarriage-info-wrapper-' + bid);
    var benSec   = document.getElementById('baby-reg-benefits-' + bid);
    var benBtn   = document.getElementById('save-ben-btn-' + bid);
    if (babySec) babySec.style.display  = (val == 1) ? 'block'        : 'none';
    if (miscSec) miscSec.style.display  = (val == 2) ? 'flex'         : 'none';
    if (benSec)  benSec.style.display   = (val == 1) ? 'flex'         : 'none';
    if (benBtn)  benBtn.style.display   = (val == 1) ? 'inline-block' : 'none';
}

function toggleTwinDelivery(bid, val) {
    var babySec  = document.getElementById('twin-baby-info-wrapper-' + bid);
    var miscSec  = document.getElementById('twin-miscarriage-info-wrapper-' + bid);
    var benSec   = document.getElementById('twin-baby-reg-benefits-' + bid);
    if (babySec) babySec.style.display  = (val == 1) ? 'block' : 'none';
    if (miscSec) miscSec.style.display  = (val == 2) ? 'flex'  : 'none';
    if (benSec)  benSec.style.display   = (val == 1) ? 'flex'  : 'none';
}

function handleFileUpload(inputElem, checkboxName, previewId) {
    var form = inputElem.closest('form');
    if (form) {
        var cb = form.querySelector('input[name="' + checkboxName + '"]');
        if (cb) {
            cb.checked = true;
            var lbl = cb.closest('label');
            if (lbl) {
                var evt = document.createEvent("HTMLEvents");
                evt.initEvent("change", false, true);
                lbl.dispatchEvent(evt);
            }
        }
    }
    
    if (inputElem.files && inputElem.files[0]) {
        var file = inputElem.files[0];
        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var previewDiv = document.getElementById(previewId);
                if (previewDiv) {
                    previewDiv.innerHTML = '<img src="' + e.target.result + '" style="max-width:120px; max-height:120px; border:1px solid #ccc; border-radius:6px; display:block; margin:0 auto 7px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
                }
            }
            reader.readAsDataURL(file);
        } else {
            var previewDiv = document.getElementById(previewId);
            if (previewDiv) {
                previewDiv.innerHTML = '<div style="display:inline-block; padding:7px 12px; background:#e8f0fe; border:1px solid #aac4f0; border-radius:6px; color:#2a6496; font-size:0.8em; margin-bottom:7px; text-decoration:none; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + file.name + '">&#128196; ' + file.name + '</div><br>';
            }
        }
    }
}

// Book checkboxes are auto-controlled by file upload only — block manual clicks
document.addEventListener('DOMContentLoaded', function() {
    var bookFields = ['benefit_mama_book', 'benefit_baby_book', 'twin_benefit_baby_book'];
    bookFields.forEach(function(name) {
        var boxes = document.querySelectorAll('input[name="' + name + '"]');
        boxes.forEach(function(cb) {
            cb.style.cursor = 'default';
            cb.addEventListener('click', function(e) { e.preventDefault(); });
            var lbl = cb.closest('label');
            if (lbl) { lbl.style.cursor = 'default'; }
        });
    });
});

function toggle50OffBabyEdit(cb, bid) {
    var wrap  = document.getElementById('wrap_50off_baby_' + bid);
    var items = document.getElementById('sku_items_baby_' + bid);
    if (cb.checked) {
        wrap.style.borderColor = '#d07a00';
        wrap.style.background  = '#fffbf0';
        items.style.display    = 'block';
    } else {
        wrap.style.borderColor = '#e0e0e0';
        wrap.style.background  = '#fff';
        items.style.display    = 'none';
        var boxes = items.querySelectorAll('input[type=checkbox]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
    }
}
function toggle50OffTwin(cb, bid) {
    var wrap  = document.getElementById('wrap_50off_twin_' + bid);
    var items = document.getElementById('sku_items_twin_' + bid);
    if (cb.checked) {
        wrap.style.borderColor = '#d07a00';
        wrap.style.background  = '#fffbf0';
        items.style.display    = 'block';
    } else {
        wrap.style.borderColor = '#e0e0e0';
        wrap.style.background  = '#fff';
        items.style.display    = 'none';
        var boxes = items.querySelectorAll('input[type=checkbox]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
    }
}

<?php if (isset($_POST['update_mama']) && $msg_type === 'danger'): ?>
toggleMamaEdit(true);
<?php endif; ?>

// Auto-calculate EDD = LMP + 280 days
function calcEDD() {
    var lmp = document.getElementById('lmp_input').value;
    if (!lmp) return;
    var d = new Date(lmp);
    d.setDate(d.getDate() + 280);
    var yyyy = d.getFullYear();
    var mm   = ('0' + (d.getMonth() + 1)).slice(-2);
    var dd   = ('0' + d.getDate()).slice(-2);
    document.getElementById('edd_input').value = yyyy + '-' + mm + '-' + dd;
}

// Toggle twin section inside edit form
function toggleTwinSection(bid) {
    var section   = document.getElementById('twin-section-' + bid);
    var hidden    = document.getElementById('add_twin_' + bid);
    var btn       = document.getElementById('twin_btn_' + bid);
    var updateBtn = document.getElementById('update_btn_' + bid);
    var hasTwin   = btn.getAttribute('data-has-twin') === '1';

    if (section.style.display === 'none') {
        // Only auto-copy DOB/status when adding a brand new twin
        if (!hasTwin) {
            var row = document.getElementById('edit-row-' + bid);
            var getVal = function(name) {
                var el = row.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            };
            row.querySelector('[name="twin_dob"]').value = getVal('dob');
            var statusEl = row.querySelector('[name="twin_is_delivered"]');
            if (statusEl) {
                statusEl.value = getVal('is_delivered');
                toggleTwinDelivery(bid, statusEl.value);
            }
        }
        section.style.display = 'block';
        hidden.value = '1';
        btn.innerHTML = hasTwin ? '&#10003; Twin' : '- Twin';
        updateBtn.value = 'Update & Save Twin';
    } else {
        section.style.display = 'none';
        hidden.value = '0';
        btn.innerHTML = hasTwin ? '&#10003; Twin' : '+ Twin';
        updateBtn.value = 'Update';
    }
}

// Auto-calculate LMP = EDD - 280 days
function calcLMP() {
    var edd = document.getElementById('edd_input').value;
    if (!edd) return;
    var d = new Date(edd);
    d.setDate(d.getDate() - 280);
    var yyyy = d.getFullYear();
    var mm   = ('0' + (d.getMonth() + 1)).slice(-2);
    var dd   = ('0' + d.getDate()).slice(-2);
    document.getElementById('lmp_input').value = yyyy + '-' + mm + '-' + dd;
}

// Inline edit row: EDD = LMP + 280 days
function calcEditEDD(bid) {
    var lmpEl = document.getElementById('lmp_edit_' + bid);
    var eddEl = document.getElementById('edd_edit_' + bid);
    if (!lmpEl || !lmpEl.value) return;
    var d = new Date(lmpEl.value);
    d.setDate(d.getDate() + 280);
    var yyyy = d.getFullYear();
    var mm   = ('0' + (d.getMonth() + 1)).slice(-2);
    var dd   = ('0' + d.getDate()).slice(-2);
    eddEl.value = yyyy + '-' + mm + '-' + dd;
}

// Inline edit row: LMP = EDD - 280 days
function calcEditLMP(bid) {
    var lmpEl = document.getElementById('lmp_edit_' + bid);
    var eddEl = document.getElementById('edd_edit_' + bid);
    if (!eddEl || !eddEl.value) return;
    var d = new Date(eddEl.value);
    d.setDate(d.getDate() - 280);
    var yyyy = d.getFullYear();
    var mm   = ('0' + (d.getMonth() + 1)).slice(-2);
    var dd   = ('0' + d.getDate()).slice(-2);
    lmpEl.value = yyyy + '-' + mm + '-' + dd;
}

function reVerifyReceipt(btn, bid, type) {
    if (!confirm('Re-verify will clear existing SKU items and reload from the receipt. Continue?')) { return; }

    // Determine container and input IDs
    var containerId, inputId, verifyBtnSel;
    if (type === 'mama') {
        containerId  = 'sku_items_container_' + bid;
        inputId      = 'receipt_no_' + bid;
        verifyBtnSel = '[onclick*="verifyReceipt"][onclick*="\'' + bid + '\'"][onclick*="\'mama\'"]';
    } else if (type === 'baby') {
        containerId  = 'sku_items_baby_container_' + bid;
        inputId      = 'baby_receipt_no_' + bid;
        verifyBtnSel = '[onclick*="verifyReceipt"][onclick*="\'' + bid + '\'"][onclick*="\'baby\'"]';
    } else {
        containerId  = 'sku_items_twin_container_' + bid;
        inputId      = 'twin_baby_receipt_no_' + bid;
        verifyBtnSel = '[onclick*="verifyReceipt"][onclick*="\'' + bid + '\'"][onclick*="\'twin\'"]';
    }

    // Clear existing SKU rows from container (keep non-hidden-input children)
    var container = document.getElementById(containerId);
    if (container) { container.innerHTML = ''; }

    // Unlock the receipt input so verifyReceipt can read & re-lock it
    var rcptInput = document.getElementById(inputId);
    if (rcptInput) {
        rcptInput.readOnly = false;
        rcptInput.style.background = '';
        rcptInput.style.color = '';
    }

    // Show the hidden Verify button so verifyReceipt can hide it again after done
    var verifyBtn = btn.parentNode ? btn.parentNode.querySelector('.alpro-btn-blue') : null;
    if (verifyBtn) { verifyBtn.style.display = ''; }

    // Trigger normal verify
    verifyReceipt(verifyBtn || btn, bid, type);
}

function checkVerifyBeforeSubmit(bid) {
    var suffix = bid; // works for both 'new' and numeric bid

    // ── Baby Info validation (only when Safely Delivered) ──
    var isDeliveredEl = document.querySelector(
        bid === 'new'
            ? '#add-baby-section select[name="is_delivered"]'
            : '#edit-row-' + bid + ' select[name="is_delivered"]'
    );
    if (isDeliveredEl && isDeliveredEl.value === '1') {
        var wrapper = document.getElementById(
            bid === 'new' ? 'add-baby-section' : 'edit-row-' + bid
        );
        if (wrapper) {
            // Baby Name
            var babyNameEl = wrapper.querySelector('input[name="baby_name"]');
            if (babyNameEl && !babyNameEl.value.trim()) {
                alert('Please enter the Baby Name.');
                babyNameEl.focus();
                return false;
            }
            // MyKid (IC)
            var mykidEl = wrapper.querySelector('input[name="mykid"]');
            if (mykidEl && !mykidEl.value.trim()) {
                alert('Please enter the MyKid (IC) number.');
                mykidEl.focus();
                return false;
            }
            // Date of Birth
            var dobEl = wrapper.querySelector('input[name="dob"]');
            if (dobEl && !dobEl.value.trim()) {
                alert('Please enter the Date of Birth (Actual).');
                dobEl.focus();
                return false;
            }
            // Gender
            var genderEl = wrapper.querySelector('select[name="gender"]');
            if (genderEl && !genderEl.value) {
                alert('Please select the Gender.');
                genderEl.focus();
                return false;
            }
            // Sensitivity – at least 1 must be ticked
            var sensBoxes = wrapper.querySelectorAll('input[name="sensitivity[]"]:checked');
            if (sensBoxes.length === 0) {
                alert('Please tick at least one Baby Sensitivity Condition.');
                return false;
            }
        }
    }

    // ── Receipt verify check ──
    var checks = [
        { receiptId: 'receipt_no_' + suffix,             label: 'Mama 50% Off' },
        { receiptId: 'baby_receipt_no_' + suffix,        label: 'Baby 50% Off' },
        { receiptId: 'twin_baby_receipt_no_' + suffix,   label: 'Twin Baby 50% Off' }
    ];
    for (var i = 0; i < checks.length; i++) {
        var rcptEl = document.getElementById(checks[i].receiptId);
        if (!rcptEl) continue;
        var receiptVal = rcptEl.value.trim();
        if (!receiptVal) continue;
        if (!rcptEl.readOnly) {
            alert('Please click "Verify" for the ' + checks[i].label + ' receipt before saving.\n\nReceipt No: ' + receiptVal);
            rcptEl.focus();
            return false;
        }
    }
    return true;
}

async function verifyReceipt(btn, bid, type) {
    let inputId = '', mismatchInputId = '';
    if (type === 'mama')      { inputId = 'receipt_no_' + bid;           mismatchInputId = 'receipt_price_mismatch_' + bid; }
    else if (type === 'baby') { inputId = 'baby_receipt_no_' + bid;      mismatchInputId = 'baby_receipt_price_mismatch_' + bid; }
    else if (type === 'twin') { inputId = 'twin_baby_receipt_no_' + bid; mismatchInputId = 'twin_baby_receipt_price_mismatch_' + bid; }

    let rcptInput = document.getElementById(inputId);
    if (!rcptInput || !rcptInput.value.trim()) {
        alert("Please enter a receipt number first.");
        return;
    }

    // For ALPRO BABY outlets: block same receipt number as pharmacy receipt
    if (type === 'mama' && isAlproBabyOutlet) {
        var vdEl = document.getElementById('vd_receipt_no_' + bid);
        if (vdEl && vdEl.value.trim() && vdEl.value.trim() === rcptInput.value.trim()) {
            alert('The 50% Off receipt number cannot be the same as the pharmacy receipt number.\nPlease use a different receipt.');
            return;
        }
    }

    let oldText = btn.innerHTML;
    btn.innerHTML = "Verifying...";
    btn.disabled = true;

    try {
        let resp = await fetch('mamabe_verify_receipt.php?receipt_no=' + encodeURIComponent(rcptInput.value.trim()));
        let data = await resp.json();
        console.log(data);
        if (!data.success) {
            alert(data.error);
        } else {
            let containerId = '';
            let chkName    = '';
            let nameField  = '';
            let masterKey  = '';

            if (type === 'mama') {
                containerId = bid === 'new' ? 'sku_items_new_container' : 'sku_items_container_' + bid;
                chkName    = 'benefit_50_off_items[]';
                nameField  = 'benefit_50_off_names[]';
                masterKey  = 'in_mama';
            } else if (type === 'baby') {
                containerId = 'sku_items_baby_container_' + bid;
                chkName    = 'benefit_50_off_baby_items[]';
                nameField  = 'benefit_50_off_baby_names[]';
                masterKey  = 'in_baby';
            } else if (type === 'twin') {
                containerId = 'sku_items_twin_container_' + bid;
                chkName    = 'twin_benefit_50_off_baby_items[]';
                nameField  = 'twin_benefit_50_off_baby_names[]';
                masterKey  = 'in_baby';
            }

            let container = document.getElementById(containerId);
            if (container) {
                // Filter: only process items registered in SKU master — ignore everything else
                var allSkuItems = data.items.filter(function(it) { return !!it[masterKey]; });

                // Reject SKU master items with qty > 1 (same item bought multiple times not allowed)
                var rejectedQtyItems = allSkuItems.filter(function(it) { return it.qty > 1; });
                var skuItems = allSkuItems.filter(function(it) { return it.qty <= 1; });

                if (rejectedQtyItems.length > 0) {
                    var rejMsg = 'The following item(s) cannot be counted for the 50% Off benefit because the quantity purchased is more than 1:\n\n';
                    rejectedQtyItems.forEach(function(it) {
                        rejMsg += '• ' + it.sku + ' ' + it.name + ' (Qty: ' + it.qty + ')\n';
                    });
                    rejMsg += '\nOnly 1 unit per item code is allowed.';
                    alert(rejMsg);
                }

                let mismatches = [];

                // Collect qty mismatches (qty > 1 = not allowed for 50% discount)
                rejectedQtyItems.forEach(function(it) {
                    mismatches.push('[QTY] ' + it.sku + ' ' + it.name + ': Purchased qty=' + it.qty + ', only 1 allowed');
                });

                // First pass: collect price mismatches from SKU master items only
                skuItems.forEach(function(it) {
                    if (it.price_ok === false) {
                        mismatches.push('[PRICE] ' + it.sku + ' ' + it.name + ': Receipt RM ' + it.price.toFixed(2) + ', Expected RM ' + it.db_price.toFixed(2));
                    }
                });

                // Second pass: add SKU master items to container
                skuItems.forEach(function(it) {
                    let existing = container.querySelector('[data-sku="' + it.sku + '"]');
                    if (existing) {
                        if (it.price_ok === false) {
                            existing.style.background = '#ffebee';
                            existing.style.border = '1px solid #ef9a9a';
                            if (!existing.querySelector('.price-mismatch-badge')) {
                                let badge = document.createElement('span');
                                badge.className = 'price-mismatch-badge';
                                badge.style.cssText = 'font-size:0.75em; color:#fff; background:#e53935; border-radius:3px; padding:1px 5px; white-space:nowrap; font-weight:bold;';
                                badge.innerHTML = '&#9888; Expected RM ' + it.db_price.toFixed(2);
                                let removeBtn = existing.querySelector('button');
                                if (removeBtn) { existing.insertBefore(badge, removeBtn); }
                                else { existing.appendChild(badge); }
                            }
                        }
                        return;
                    }

                    let safeName = it.name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
                    let displayPrice = (it.db_price !== undefined && it.db_price !== null) ? it.db_price : it.price;
                    let priceBadge = '';
                    if (it.price_ok === false) {
                        priceBadge = '<span class="price-mismatch-badge" style="font-size:0.75em; color:#fff; background:#e53935; border-radius:3px; padding:1px 5px; white-space:nowrap; font-weight:bold;">&#9888; RM ' + displayPrice.toFixed(2) + ' (Receipt: RM ' + it.price.toFixed(2) + ')</span>';
                    } else if (it.price_ok === true) {
                        priceBadge = '<span style="font-size:0.75em; color:#388e3c; white-space:nowrap;">RM ' + displayPrice.toFixed(2) + ' &#10003;</span>';
                    } else {
                        priceBadge = '<span style="font-size:0.75em; color:#aaa; white-space:nowrap;">RM ' + displayPrice.toFixed(2) + '</span>';
                    }

                    let bgStyle = it.price_ok === false
                        ? 'background:#ffebee; border:1px solid #ef9a9a;'
                        : 'background:#f1f8e9; border:1px solid #c8e6c9;';

                    let row = document.createElement('div');
                    row.setAttribute('data-sku', it.sku);
                    row.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:0.8em; padding:3px 6px; border-radius:4px; flex-wrap:wrap;' + bgStyle;
                    row.innerHTML =
                        '<input type="hidden" name="' + chkName + '" value="' + it.sku + '" data-sku="' + it.sku + '">'
                        + '<input type="hidden" name="' + nameField + '" value="' + safeName + '">'
                        + '<span style="color:#aaa; font-family:monospace; font-size:0.88em; min-width:82px;">' + it.sku + '</span>'
                        + '<span style="flex:1;">' + it.name + '</span>'
                        + priceBadge
                        + '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#e53935;cursor:pointer;font-size:1.1em;padding:0 2px;line-height:1;" title="Remove">&times;</button>';
                    container.appendChild(row);
                });

                // If mismatches found, show confirmation before finalising
                if (mismatches.length > 0) {
                    var confirmMsg = 'WARNING: Issue(s) detected!\n\n' + mismatches.join('\n') + '\n\nDo you want to confirm and proceed anyway?';
                    if (!confirm(confirmMsg)) {
                        // Staff chose not to proceed — restore button and stop
                        btn.innerHTML = oldText;
                        btn.disabled = false;
                        return;
                    }
                }

                // Auto-add matching Free Items when a catalog SKU is found on the receipt
                // For ALPRO BABY outlets, mama-side free items come from the pharmacy receipt — skip here
                data.items.forEach(function(it) {
                    if (type === 'mama' && !isAlproBabyOutlet && FREE_ITEMS_MAMA.hasOwnProperty(it.sku)) {
                        mamabeAppendFreeItem('benefit_free_items_container_' + bid, 'benefit_free_items', it.sku, FREE_ITEMS_MAMA[it.sku]);
                    }
                    if (type === 'baby' && FREE_ITEMS_BABY.hasOwnProperty(it.sku)) {
                        mamabeAppendFreeItem('benefit_free_items_baby_container_' + bid, 'benefit_free_items_baby', it.sku, FREE_ITEMS_BABY[it.sku]);
                    }
                    if (type === 'twin' && FREE_ITEMS_BABY.hasOwnProperty(it.sku)) {
                        mamabeAppendFreeItem('twin_benefit_free_items_baby_container_' + bid, 'twin_benefit_free_items_baby', it.sku, FREE_ITEMS_BABY[it.sku]);
                    }
                });

                // After verify: hide button, make receipt readonly, hide remove buttons
                btn.style.display = 'none';
                rcptInput.readOnly = true;
                rcptInput.style.background = '#f5f5f5';
                rcptInput.style.color = '#555';
                container.querySelectorAll('button[title="Remove"]').forEach(function(rb) { rb.style.display = 'none'; });

                // Update hidden mismatch input
                var mismatchEl = document.getElementById(mismatchInputId);
                if (mismatchEl) { mismatchEl.value = mismatches.length > 0 ? mismatches.join('\n') : ''; }

                // Show persistent mismatch badge (no dismiss button)
                if (mismatches.length > 0) {
                    var badgeId = mismatchInputId.replace('_price_mismatch_', '_mismatch_badge_');
                    var existingBadge = document.getElementById(badgeId);
                    if (!existingBadge) {
                        var badge = document.createElement('div');
                        badge.id = badgeId;
                        badge.style.cssText = 'margin-top:6px; background:#fff3f3; border:1px solid #ef9a9a; border-radius:4px; padding:6px 10px; font-size:0.8em; color:#c62828;';
                        badge.innerHTML = '<span style="font-weight:bold;">&#9888; Mismatch / Issue:</span><br><span style="white-space:pre-line;">' + mismatches.join('\n') + '</span>';
                        rcptInput.parentNode.insertBefore(badge, rcptInput.nextSibling);
                    }
                }

                if (skuItems.length > 0) {
                    let mainChkName = '';
                    if (type === 'mama') mainChkName = 'benefit_50_off';
                    else if (type === 'baby') mainChkName = 'benefit_50_off_baby';
                    else if (type === 'twin') mainChkName = 'twin_benefit_50_off_baby';

                    let form = rcptInput.closest('form');
                    if (form) {
                        let mainChk = form.querySelector('input[name="' + mainChkName + '"]');
                        if (mainChk && !mainChk.checked) {
                            mainChk.checked = true;
                            let ev = new Event('change');
                            mainChk.dispatchEvent(ev);
                        }
                    }
                    if (mismatches.length === 0) {
                        let msg = 'Found ' + skuItems.length + ' registered item(s) in this receipt.';
                        alert(msg);
                    }
                } else {
                    alert('No registered SKU items found in this receipt.\nThe receipt has been recorded but no items will be listed.');
                }
            }
        }
    } catch (e) {
        console.error('Receipt verify failed:', e);
        alert("Error connecting to server. Please try again.\n\n(" + (e && e.message ? e.message : e) + ")");
    }

    if (btn.style.display !== 'none') {
        btn.innerHTML = oldText;
        btn.disabled = false;
    }
}

async function verifyVdReceipt(btn, bid) {
    var inputId = 'vd_receipt_no_' + bid;
    var rcptInput = document.getElementById(inputId);
    if (!rcptInput) return;
    var receiptNo = rcptInput.value.trim();
    if (!receiptNo) { alert('Please enter the pharmacy receipt number first.'); return; }

    // Block same receipt number as main (50% Off) receipt
    var mainRcptEl = document.getElementById('receipt_no_' + bid);
    if (mainRcptEl && mainRcptEl.value.trim() && mainRcptEl.value.trim() === receiptNo) {
        alert('The pharmacy receipt number cannot be the same as the 50% Off receipt number.\nPlease use a different receipt.');
        return;
    }

    var oldText = btn.innerHTML;
    btn.innerHTML = 'Checking...';
    btn.disabled = true;

    try {
        var resp = await fetch('mamabe_verify_receipt.php?receipt_no=' + encodeURIComponent(receiptNo));
        var data = await resp.json();

        if (!data.success) {
            alert('Error: ' + (data.error || 'Could not verify receipt.'));
            btn.innerHTML = oldText;
            btn.disabled = false;
            return;
        }

        // Filter SKU master mama items (qty=1 only)
        var allSkuItems = data.items.filter(function(it) { return !!it['in_mama']; });
        var rejectedQtyItems = allSkuItems.filter(function(it) { return it.qty > 1; });
        var skuItems = allSkuItems.filter(function(it) { return it.qty <= 1; });

        if (rejectedQtyItems.length > 0) {
            var rejMsg = 'The following item(s) have quantity > 1 and cannot be counted:\n\n';
            rejectedQtyItems.forEach(function(it) { rejMsg += '• ' + it.sku + ' ' + it.name + ' (Qty: ' + it.qty + ')\n'; });
            alert(rejMsg);
        }

        // Auto-add matching Free Items from the pharmacy receipt
        data.items.forEach(function(it) {
            if (FREE_ITEMS_MAMA.hasOwnProperty(it.sku)) {
                mamabeAppendFreeItem('benefit_free_items_container_' + bid, 'benefit_free_items', it.sku, FREE_ITEMS_MAMA[it.sku]);
            }
        });

        // Populate 50% Off items container from pharmacy receipt
        var skuContainer = document.getElementById('sku_items_vd_' + bid + '_container');
        if (skuContainer && skuItems.length > 0) {
            // Collect SKUs already in the main receipt container (cross-receipt duplicate check)
            var mainContainerId = (bid === 'new') ? 'sku_items_new_container' : 'sku_items_container_' + bid;
            var mainContainer = document.getElementById(mainContainerId);
            var takenSkus = {};
            if (mainContainer) {
                mainContainer.querySelectorAll('[data-sku]').forEach(function(el) { takenSkus[el.getAttribute('data-sku')] = true; });
            }
            // Also collect SKUs already added to pharmacy container itself
            skuContainer.querySelectorAll('[data-sku]').forEach(function(el) { takenSkus[el.getAttribute('data-sku')] = true; });

            var duplicateItems = skuItems.filter(function(it) { return takenSkus[it.sku]; });
            var newItems       = skuItems.filter(function(it) { return !takenSkus[it.sku]; });

            if (duplicateItems.length > 0) {
                var dupMsg = 'The following item(s) were skipped because they are already recorded in the ALPRO BABY receipt (one item per customer):\n\n';
                duplicateItems.forEach(function(it) { dupMsg += '• ' + it.sku + ' ' + it.name + '\n'; });
                alert(dupMsg);
            }
            skuItems = newItems;

            skuItems.forEach(function(it) {
                var existing = skuContainer.querySelector('[data-sku="' + it.sku + '"]');
                if (existing) return;
                var safeName = it.name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
                var displayPrice = (it.db_price !== undefined && it.db_price !== null) ? it.db_price : it.price;
                var priceBadge = it.price_ok === false
                    ? '<span style="font-size:0.75em; color:#fff; background:#e53935; border-radius:3px; padding:1px 5px; white-space:nowrap; font-weight:bold;">&#9888; RM ' + displayPrice.toFixed(2) + ' (Receipt: RM ' + it.price.toFixed(2) + ')</span>'
                    : '<span style="font-size:0.75em; color:#388e3c; white-space:nowrap;">RM ' + displayPrice.toFixed(2) + ' &#10003;</span>';
                var bgStyle = it.price_ok === false
                    ? 'background:#ffebee; border:1px solid #ef9a9a;'
                    : 'background:#f1f8e9; border:1px solid #c8e6c9;';
                var row = document.createElement('div');
                row.setAttribute('data-sku', it.sku);
                row.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:0.8em; padding:3px 6px; border-radius:4px; flex-wrap:wrap;' + bgStyle;
                row.innerHTML =
                    '<input type="hidden" name="benefit_50_off_items[]" value="' + it.sku + '" data-sku="' + it.sku + '">'
                    + '<input type="hidden" name="benefit_50_off_names[]" value="' + safeName + '">'
                    + '<span style="color:#aaa; font-family:monospace; font-size:0.88em; min-width:82px;">' + it.sku + '</span>'
                    + '<span style="flex:1;">' + it.name + '</span>'
                    + priceBadge;
                skuContainer.appendChild(row);
            });

            // Auto-tick benefit_50_off
            var form = rcptInput.closest('form');
            if (form) {
                var mainChk = form.querySelector('input[name="benefit_50_off"]');
                if (mainChk && !mainChk.checked) { mainChk.checked = true; mainChk.dispatchEvent(new Event('change')); }
            }
        }

        // Lock receipt input and hide verify button
        rcptInput.readOnly = true;
        rcptInput.style.background = '#f5f5f5';
        rcptInput.style.color = '#555';
        btn.style.display = 'none';

        var vdFound = data.items.filter(function(it) { return FREE_ITEMS_MAMA.hasOwnProperty(it.sku); });
        var msgs = [];
        if (vdFound.length > 0)   msgs.push('Vitamin D Test benefit auto-ticked.');
        if (skuItems.length > 0)  msgs.push('Found ' + skuItems.length + ' 50% Off item(s) from pharmacy receipt.');
        if (vdFound.length === 0 && skuItems.length === 0) msgs.push('No registered items found. Receipt recorded.');
        alert(msgs.join('\n'));

    } catch (e) {
        console.error('Vitamin D receipt verify failed:', e);
        alert('Error connecting to server. Please try again.\n\n(' + (e && e.message ? e.message : e) + ')');
        btn.innerHTML = oldText;
        btn.disabled = false;
    }
}

function viewReceipt(receiptNo) {
    document.getElementById('receipt-modal-no').textContent = receiptNo;
    document.getElementById('receipt-modal-body').innerHTML = '<div style="text-align:center; color:#888; padding:40px; font-size:1em;">Loading...</div>';
    document.getElementById('receipt-modal-overlay').style.display = 'flex';

    fetch('mamabe_receipt_detail.php?receipt_no=' + encodeURIComponent(receiptNo))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('receipt-modal-body').innerHTML = '<div style="color:#c62828; padding:20px;">' + data.error + '</div>';
                return;
            }
            var s = data.sale;
            var td  = 'style="padding:6px 10px; border:1px solid #e0e0e0; vertical-align:top;"';
            var th  = 'style="background:#f5f5f5; padding:6px 10px; text-align:right; white-space:nowrap; border:1px solid #e0e0e0; font-weight:bold; vertical-align:top;"';
            var html = '<table style="width:100%; border-collapse:collapse; font-size:0.88em;">';

            html += '<tr><th ' + th + '>Status</th><td ' + td + '><b>' + (s.status || '') + '</b></td>'
                  + '<th ' + th + '>Outlet</th><td ' + td + ' colspan="3">' + (s.outlet || '') + '</td></tr>';
            html += '<tr><th ' + th + '>Invoice Date</th><td ' + td + ' colspan="5">' + (s.dateTime || '') + '</td></tr>';
            var payDate = (s.collections && s.collections[0]) ? (s.collections[0].paymentDate || '') : '';
            html += '<tr><th ' + th + '>Payment Date</th><td ' + td + ' colspan="5">' + payDate + '</td></tr>';
            html += '<tr><th ' + th + '>Invoice Num</th><td ' + td + ' colspan="5">' + (s.salesNo || '') + '</td></tr>';
            var cname = (s.client && s.client.name) ? s.client.name : '';
            html += '<tr><th ' + th + '>Client Name</th><td ' + td + ' colspan="5">' + cname + '</td></tr>';
            html += '<tr><th ' + th + '>Client Contact</th><td ' + td + ' colspan="5">' + (s.client ? (s.client.contactNo || '') : '') + '</td></tr>';
            html += '<tr><th ' + th + '>Client Code</th><td ' + td + ' colspan="5">' + (s.client ? (s.client.clientCode || '') : '') + '</td></tr>';
            var addr = '';
            if (s.client && s.client.billingAddress) {
                var ba = s.client.billingAddress;
                addr = [ba.street, ba.zipcode, ba.city, ba.state].filter(function(x) { return x; }).join(', ');
            }
            html += '<tr><th ' + th + '>Address</th><td ' + td + ' colspan="5">' + addr + '</td></tr>';
            html += '<tr><th ' + th + '>Salesperson</th><td ' + td + ' colspan="5">' + (s.salesPerson || '') + '</td></tr>';

            var hth = 'style="background:#2a6496; color:#fff; padding:7px 10px; border:1px solid #1a5070; text-align:left;"';
            html += '<tr>'
                  + '<th ' + hth + '>Item Code</th>'
                  + '<th ' + hth + '>Description</th>'
                  + '<th ' + hth + ' style="background:#2a6496; color:#fff; padding:7px 10px; border:1px solid #1a5070; text-align:center;">Qty</th>'
                  + '<th ' + hth + ' style="background:#2a6496; color:#fff; padding:7px 10px; border:1px solid #1a5070; text-align:right;">Price</th>'
                  + '<th ' + hth + ' style="background:#2a6496; color:#fff; padding:7px 10px; border:1px solid #1a5070; text-align:right;">Total</th>'
                  + '<th ' + hth + ' style="background:#2a6496; color:#fff; padding:7px 10px; border:1px solid #1a5070; text-align:right;">Voucher</th>'
                  + '</tr>';

            if (s.items) {
                s.items.forEach(function(item, idx) {
                    var total = item.quantity * item.unitPrice;
                    var bg = idx % 2 === 0 ? '#fff' : '#f9f9f9';
                    html += '<tr style="background:' + bg + ';">'
                          + '<td style="padding:5px 10px; border:1px solid #e0e0e0; font-family:monospace;">' + item.itemCode + '</td>'
                          + '<td style="padding:5px 10px; border:1px solid #e0e0e0;">' + item.itemName + '</td>'
                          + '<td style="padding:5px 10px; border:1px solid #e0e0e0; text-align:center;">' + item.quantity + '</td>'
                          + '<td style="padding:5px 10px; border:1px solid #e0e0e0; text-align:right;">RM ' + parseFloat(item.unitPrice).toFixed(2) + '</td>'
                          + '<td style="padding:5px 10px; border:1px solid #e0e0e0; text-align:right;">RM ' + total.toFixed(2) + '</td>'
                          + '<td style="padding:5px 10px; border:1px solid #e0e0e0; text-align:right;">' + (item.voucherNumber || '') + '</td>'
                          + '</tr>';
                });
            }

            var totalAmt   = (s.balance > 0) ? s.balance : ((s.collections && s.collections[0]) ? s.collections[0].amount : 0);
            var totalLabel = (s.balance > 0) ? 'Total Balance' : 'Total Paid';
            html += '<tr><td colspan="4" style="border:none;"></td>'
                  + '<th style="padding:6px 10px; text-align:right; background:#f5f5f5; border:1px solid #e0e0e0;">' + totalLabel + '</th>'
                  + '<td style="padding:6px 10px; text-align:right; font-weight:bold; border:1px solid #e0e0e0;">RM ' + parseFloat(totalAmt).toFixed(2) + '</td></tr>';
            html += '</table>';
            document.getElementById('receipt-modal-body').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('receipt-modal-body').innerHTML = '<div style="color:#c62828; padding:20px;">Error loading receipt data. Please try again.</div>';
        });
}

function closeReceiptModal() {
    document.getElementById('receipt-modal-overlay').style.display = 'none';
}
</script>

<!-- Receipt View Modal -->
<div id="receipt-modal-overlay" onclick="if(event.target===this)closeReceiptModal();"
     style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); z-index:9999; justify-content:center; align-items:flex-start; overflow-y:auto; padding:30px 15px; box-sizing:border-box;">
  <div style="background:#fff; width:100%; max-width:820px; border-radius:8px; box-shadow:0 6px 32px rgba(0,0,0,0.3); overflow:hidden; margin:auto;">
    <div style="background:#2a6496; color:#fff; padding:12px 16px; display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:bold; font-size:1em;">&#128196; Receipt Details &ndash; <span id="receipt-modal-no" style="font-family:monospace;"></span></span>
      <button type="button" onclick="closeReceiptModal()" style="background:none; border:none; color:#fff; font-size:1.6em; cursor:pointer; line-height:1; padding:0 4px;">&times;</button>
    </div>
    <div id="receipt-modal-body" style="padding:16px; overflow-x:auto;">
      <div style="text-align:center; color:#888; padding:40px;">Loading...</div>
    </div>
  </div>
</div>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>