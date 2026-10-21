<?php
/**
 * Mamabe check settings - Admin / Super Admin only (header.php enforces it via $mamabe_access = 'admin', the default).
 *   - Baby age limit for the DOB check, in months (mamabe_setting.baby_max_age_months). A change applies to checks
 *     run AFTER saving; results already decided are never re-run.
 *   - "How do you know us?" options (mamabe_source, see MamabeRecords::sources()) - what the public form's chips
 *     offer. Admin-editable here instead of a hardcoded list: add, rename, reorder, hide/show. Soft-delete via
 *     recycle, same convention as every other lookup table in this app - a hidden option still displays correctly
 *     on old submissions that used it, it just stops being offered on the form.
 *   - "Redeem Voucher Guide Image" (mamabe_setting.redeem_guide_ext) - the instructional image shown on the public
 *     form's success screen, after a visitor submits. Stored at public_assets/redeem_guide.<ext>, served to the
 *     form through api/redeem_guide.php (-> education server's api/redeem-guide.php proxy). No image configured
 *     = the section simply doesn't show.
 */
$page_title = 'MamaBe – Settings';
$active_tab = 'settings';
require_once(dirname(__FILE__) . '/header.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeRecords.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');

const MAMABE_AGE_MONTHS_MIN = 1;
const MAMABE_AGE_MONTHS_MAX = 120;

MamabeRecords::ensureSourceTable($conn);

$error = '';
$sourceError = '';
$guideError = '';
$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === '') {
    // The age-limit form (no mode field - the original/only form before sources were added).
    MamabeCsrf::verify();
    $raw = isset($_POST['baby_max_age_months']) ? trim((string)$_POST['baby_max_age_months']) : '';
    if (!ctype_digit($raw) || (int)$raw < MAMABE_AGE_MONTHS_MIN || (int)$raw > MAMABE_AGE_MONTHS_MAX) {
        $error = 'Enter a whole number of months from ' . MAMABE_AGE_MONTHS_MIN . ' to ' . MAMABE_AGE_MONTHS_MAX . '.';
    } else {
        $value = (string)(int)$raw;
        $by    = (int)$id_user;
        $now   = date('Y-m-d H:i:s');
        $stmt  = $conn->prepare("INSERT INTO mamabe_setting (setting_key, setting_value, updated_by, updated_at)
                                 VALUES ('baby_max_age_months', ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        $stmt->bind_param('sis', $value, $by, $now);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . MAMABE_BASE . 'settings.php?saved=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'add_source') {
    MamabeCsrf::verify();
    $value = strtolower(trim((string)($_POST['value'] ?? '')));
    $label = trim((string)($_POST['label'] ?? ''));
    if (!preg_match('/^[a-z0-9_]{1,30}$/', $value)) {
        $sourceError = 'Code must be lowercase letters, numbers and underscores only (up to 30 characters).';
    } elseif ($label === '' || mb_strlen($label, 'UTF-8') > 100) {
        $sourceError = 'Enter a label (up to 100 characters).';
    } else {
        $stmt = $conn->prepare("INSERT INTO mamabe_source (value, label, sort_order, recycle) VALUES (?, ?, (SELECT m FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS m FROM mamabe_source) t), 0)");
        $stmt->bind_param('ss', $value, $label);
        try {
            $stmt->execute();
            $stmt->close();
            header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
            exit;
        } catch (mysqli_sql_exception $e) {
            $sourceError = ($e->getCode() === 1062) ? "The code \"$value\" is already in use." : 'Could not add it. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'edit_source') {
    MamabeCsrf::verify();
    $id    = (int)($_POST['id'] ?? 0);
    $label = trim((string)($_POST['label'] ?? ''));
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($label === '' || mb_strlen($label, 'UTF-8') > 100) {
        $sourceError = 'Enter a label (up to 100 characters).';
    } else {
        $stmt = $conn->prepare("UPDATE mamabe_source SET label = ?, sort_order = ? WHERE id = ?");
        $stmt->bind_param('sii', $label, $sort, $id);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'toggle_source') {
    MamabeCsrf::verify();
    $id      = (int)($_POST['id'] ?? 0);
    $recycle = ((int)($_POST['recycle'] ?? 0)) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE mamabe_source SET recycle = ? WHERE id = ?");
    $stmt->bind_param('ii', $recycle, $id);
    $stmt->execute();
    $stmt->close();
    header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'redeem_guide_upload') {
    MamabeCsrf::verify();
    $mimes = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    $f = $_FILES['redeem_guide'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $guideError = 'Please choose an image to upload.';
    } elseif ($f['size'] < 1 || $f['size'] > 5 * 1024 * 1024) {
        $guideError = 'The image is too large (max 5 MB).';
    } else {
        $info = @getimagesize($f['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!$info || !isset($mimes[$mime])) {
            $guideError = 'Please upload a JPG, PNG, WEBP or GIF image.';
        } else {
            $ext = $mimes[$mime];
            foreach ($mimes as $otherExt) {
                $old = dirname(__FILE__) . '/public_assets/redeem_guide.' . $otherExt;
                if ($otherExt !== $ext && is_file($old)) @unlink($old);
            }
            if (!move_uploaded_file($f['tmp_name'], dirname(__FILE__) . '/public_assets/redeem_guide.' . $ext)) {
                $guideError = 'Could not save the image. Please try again.';
            } else {
                $by  = (int)$id_user;
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO mamabe_setting (setting_key, setting_value, updated_by, updated_at)
                                         VALUES ('redeem_guide_ext', ?, ?, ?)
                                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
                $stmt->bind_param('sis', $ext, $by, $now);
                $stmt->execute();
                $stmt->close();
                header('Location: ' . MAMABE_BASE . 'settings.php?guide_saved=1');
                exit;
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'redeem_guide_remove') {
    MamabeCsrf::verify();
    foreach (array('jpg', 'jpeg', 'png', 'webp', 'gif') as $ext) {
        $p = dirname(__FILE__) . '/public_assets/redeem_guide.' . $ext;
        if (is_file($p)) @unlink($p);
    }
    $conn->query("DELETE FROM mamabe_setting WHERE setting_key = 'redeem_guide_ext'");
    header('Location: ' . MAMABE_BASE . 'settings.php?guide_saved=1');
    exit;
}

MamabeVerifier::loadSettings($conn);
$current = isset($_POST['baby_max_age_months']) && $error ? (string)$_POST['baby_max_age_months'] : (string)MamabeVerifier::babyMaxAgeMonths();

$lastChange = null;
$res = $conn->query("SELECT s.updated_at, st.nama_staff FROM mamabe_setting s LEFT JOIN staff st ON st.id = s.updated_by
                      WHERE s.setting_key = 'baby_max_age_months'");
if ($res) $lastChange = $res->fetch_assoc();

$sourceRows = $conn->query("SELECT id, value, label, sort_order, recycle FROM mamabe_source ORDER BY recycle, sort_order, label")->fetch_all(MYSQLI_ASSOC);

$guideExt = '';
$guideRes = $conn->query("SELECT setting_value FROM mamabe_setting WHERE setting_key = 'redeem_guide_ext' LIMIT 1");
if ($guideRes && ($guideRow = $guideRes->fetch_assoc())) $guideExt = (string)$guideRow['setting_value'];
$guideFile = $guideExt !== '' ? dirname(__FILE__) . '/public_assets/redeem_guide.' . $guideExt : '';
if ($guideFile !== '' && !is_file($guideFile)) { $guideExt = ''; $guideFile = ''; }
?>

<fieldset>
    <legend><b>Check Rules</b></legend>

    <?php if (isset($_GET['saved']) && !$error): ?>
    <div class="alpro-alert alpro-success" role="alert">Saved. New checks use this value from now on; results already decided are not re-run.</div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alpro-alert alpro-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo MAMABE_BASE; ?>settings.php">
        <?php echo MamabeCsrf::field(); ?>
        <div class="alpro-field" style="max-width:260px;">
            <label for="baby_max_age_months">Baby age limit (months)</label>
            <input type="number" class="alpro-input" id="baby_max_age_months" name="baby_max_age_months"
                   min="<?php echo MAMABE_AGE_MONTHS_MIN; ?>" max="<?php echo MAMABE_AGE_MONTHS_MAX; ?>" step="1" required
                   value="<?php echo htmlspecialchars($current); ?>">
        </div>
        <p class="alpro-muted" style="font-size:12px;margin:8px 0 16px;">
            A baby passes the DOB check only while younger than this on the day it is checked
            (24 = 2 years, 18 = 1&frac12; years). The mother's EDD rule is fixed: EDD must be today or later.
            <?php if ($lastChange && $lastChange['nama_staff']): ?>
            <br>Last changed by <?php echo htmlspecialchars($lastChange['nama_staff']); ?> on <?php echo htmlspecialchars($lastChange['updated_at']); ?>.
            <?php endif; ?>
        </p>
        <button type="submit" class="alpro-btn alpro-btn-blue">Save</button>
    </form>
</fieldset>

<fieldset>
    <legend><b>"How do you know us?" Options</b></legend>
    <p class="alpro-muted" style="font-size:12px;margin:0 0 12px;">
        What the public MamaBe form's chips offer (<a href="https://alpropharmacy.com/mamabe/" target="_blank" rel="noopener">mamabe registration form</a>, step 2). Hiding an
        option removes it from the form immediately but keeps showing correctly on past submissions that used it.
    </p>

    <?php if (isset($_GET['source_saved'])): ?>
    <div class="alpro-alert alpro-success" role="alert">Saved.</div>
    <?php endif; ?>
    <?php if ($sourceError): ?>
    <div class="alpro-alert alpro-danger" role="alert"><?php echo htmlspecialchars($sourceError); ?></div>
    <?php endif; ?>

    <!-- One shared hidden form per action - JS fills in which row (id/label/sort_order) before submitting it, so
         the table itself can stay plain <tr>/<td> markup (a <form> can't legally sit inside a <tr>/<td> as a direct
         table child - browsers hoist it out and corrupt the layout) without depending on the HTML5 form="id"
         cross-reference attribute, which isn't reliable in every environment this admin page may run in. -->
    <form id="srcEditForm" method="post" style="display:none;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="edit_source">
        <input type="hidden" name="id" id="srcEditId">
        <input type="hidden" name="label" id="srcEditLabel">
        <input type="hidden" name="sort_order" id="srcEditSort">
    </form>
    <form id="srcToggleForm" method="post" style="display:none;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="toggle_source">
        <input type="hidden" name="id" id="srcToggleId">
        <input type="hidden" name="recycle" id="srcToggleRecycle">
    </form>

    <table class="myTable alpro-table" width="100%" style="margin-bottom:16px;">
        <tr>
            <th>Order</th><th>Code</th><th>Label</th><th width="90">Status</th><th width="170"></th>
        </tr>
        <?php if ($sourceRows): foreach ($sourceRows as $s): ?>
        <tr<?php echo $s['recycle'] ? ' style="opacity:.55;"' : ''; ?>>
            <td><input class="alpro-input" type="number" id="src_sort_<?php echo (int)$s['id']; ?>" value="<?php echo (int)$s['sort_order']; ?>" style="width:60px;"></td>
            <td class="alpro-mono"><?php echo htmlspecialchars($s['value']); ?></td>
            <td><input class="alpro-input" type="text" id="src_label_<?php echo (int)$s['id']; ?>" maxlength="100" value="<?php echo htmlspecialchars($s['label']); ?>"></td>
            <td><?php echo $s['recycle'] ? '<span class="alpro-muted">Hidden</span>' : '<span style="color:#128C7E;font-weight:bold;">Active</span>'; ?></td>
            <td>
                <button type="button" class="alpro-btn alpro-btn-grey" style="font-size:0.85em;padding:4px 10px;"
                        onclick="mamabeSaveSource(<?php echo (int)$s['id']; ?>)">Save</button>
                <button type="button" class="alpro-btn <?php echo $s['recycle'] ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>" style="font-size:0.85em;padding:4px 10px;"
                        onclick="mamabeToggleSource(<?php echo (int)$s['id']; ?>, <?php echo $s['recycle'] ? 0 : 1; ?>)">
                    <?php echo $s['recycle'] ? 'Unhide' : 'Hide'; ?>
                </button>
            </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5" class="alpro-center alpro-muted">No options yet.</td></tr>
        <?php endif; ?>
    </table>
    <script>
    function mamabeSaveSource(id) {
        document.getElementById('srcEditId').value = id;
        document.getElementById('srcEditLabel').value = document.getElementById('src_label_' + id).value;
        document.getElementById('srcEditSort').value = document.getElementById('src_sort_' + id).value;
        document.getElementById('srcEditForm').submit();
    }
    function mamabeToggleSource(id, newRecycle) {
        document.getElementById('srcToggleId').value = id;
        document.getElementById('srcToggleRecycle').value = newRecycle;
        document.getElementById('srcToggleForm').submit();
    }
    </script>

    <div style="font-weight:bold;margin-bottom:6px;">Add a new option</div>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="add_source">
        <div class="alpro-field" style="max-width:180px;">
            <label for="src_value">Code</label>
            <input class="alpro-input" type="text" id="src_value" name="value" maxlength="30" pattern="[a-z0-9_]+" required
                   placeholder="e.g. website">
        </div>
        <div class="alpro-field" style="flex:1;min-width:180px;">
            <label for="src_label">Label shown on the form</label>
            <input class="alpro-input" type="text" id="src_label" name="label" maxlength="100" required
                   placeholder="e.g. Our website">
        </div>
        <button type="submit" class="alpro-btn alpro-btn-blue">Add</button>
    </form>
    <p class="alpro-muted" style="font-size:12px;margin:8px 0 0;">Code: lowercase letters, numbers and underscores only - stored on every submission, so pick it once and don't reuse a retired one for something different.</p>
</fieldset>

<fieldset>
    <legend><b>Redeem Voucher Guide Image</b></legend>
    <p class="alpro-muted" style="font-size:12px;margin:0 0 12px;">
        Shown on the public form's success screen after a visitor submits (step-by-step "how to redeem" picture). No image = the section simply doesn't show.
    </p>

    <?php if (isset($_GET['guide_saved'])): ?>
    <div class="alpro-alert alpro-success" role="alert">Saved.</div>
    <?php endif; ?>
    <?php if ($guideError): ?>
    <div class="alpro-alert alpro-danger" role="alert"><?php echo htmlspecialchars($guideError); ?></div>
    <?php endif; ?>

    <?php if ($guideFile !== ''): ?>
    <div style="margin-bottom:14px;">
        <img src="<?php echo MAMABE_BASE; ?>public_assets/redeem_guide.<?php echo htmlspecialchars($guideExt); ?>?v=<?php echo filemtime($guideFile); ?>"
             alt="Current redeem guide image" style="max-width:320px;height:auto;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);display:block;">
    </div>
    <form method="post" style="display:inline;" onsubmit="return confirm('Remove the redeem guide image? The success screen will stop showing it.');">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="redeem_guide_remove">
        <button type="submit" class="alpro-btn alpro-btn-grey" style="color:#c62828;">Remove image</button>
    </form>
    <?php else: ?>
    <p class="alpro-muted" style="font-size:13px;">No image set.</p>
    <?php endif; ?>

    <div style="margin-top:16px;font-weight:bold;margin-bottom:6px;"><?php echo $guideFile !== '' ? 'Replace image' : 'Upload image'; ?></div>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="redeem_guide_upload">
        <div class="alpro-field" style="flex:1;min-width:220px;">
            <label for="redeem_guide">Image file (JPG, PNG, WEBP or GIF, max 5 MB)</label>
            <input class="alpro-input" type="file" id="redeem_guide" name="redeem_guide" accept="image/jpeg,image/png,image/webp,image/gif" required>
        </div>
        <button type="submit" class="alpro-btn alpro-btn-blue">Upload</button>
    </form>
</fieldset>

<?php require_once(dirname(__FILE__) . '/footer.php'); ?>
