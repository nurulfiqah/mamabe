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
    $label = trim((string)($_POST['label'] ?? ''));
    if ($label === '' || mb_strlen($label, 'UTF-8') > 100) {
        $sourceError = 'Enter a label (up to 100 characters).';
    } else {
        // The stored code is generated from the label (e.g. "Confinement Center" -> confinement_center) - staff never
        // see or type it. Made unique with _2, _3... so a hidden/retired code is never reused for something different.
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        $base = substr($base !== '' ? $base : 'option', 0, 26);
        $value = $base;
        for ($n = 2; ; $n++) {
            $chk = $conn->prepare("SELECT 1 FROM mamabe_source WHERE value = ?");
            $chk->bind_param('s', $value);
            $chk->execute();
            $chk->store_result();
            $taken = $chk->num_rows > 0;
            $chk->close();
            if (!$taken) break;
            $value = $base . '_' . $n;
        }

        $stmt = $conn->prepare("INSERT INTO mamabe_source (value, label, sort_order, recycle) VALUES (?, ?, (SELECT m FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS m FROM mamabe_source) t), 0)");
        $stmt->bind_param('ss', $value, $label);
        try {
            $stmt->execute();
            $stmt->close();
            header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
            exit;
        } catch (mysqli_sql_exception $e) {
            $sourceError = 'Could not add it. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'edit_source') {
    MamabeCsrf::verify();
    $id    = (int)($_POST['id'] ?? 0);
    $label = trim((string)($_POST['label'] ?? ''));
    if ($label === '' || mb_strlen($label, 'UTF-8') > 100) {
        $sourceError = 'Enter a label (up to 100 characters).';
    } else {
        // Label only - sort_order is no longer edited here (new options simply go last, see add_source).
        $stmt = $conn->prepare("UPDATE mamabe_source SET label = ? WHERE id = ?");
        $stmt->bind_param('si', $label, $id);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'move_source') {
    // ▲/▼ - swap with the neighbour in the same group (active rows among themselves, hidden among themselves), in
    // the same order the table shows, then renumber every row 1..n so ties/gaps from older data never block a move.
    MamabeCsrf::verify();
    $id  = (int)($_POST['id'] ?? 0);
    $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
    $rows = $conn->query("SELECT id, recycle FROM mamabe_source ORDER BY recycle, sort_order, label")->fetch_all(MYSQLI_ASSOC);
    $ids  = array_map('intval', array_column($rows, 'id'));
    $i    = array_search($id, $ids, true);
    $j    = $i === false ? false : $i + $dir;
    if ($i !== false && isset($rows[$j]) && (int)$rows[$j]['recycle'] === (int)$rows[$i]['recycle']) {
        list($ids[$i], $ids[$j]) = array($ids[$j], $ids[$i]);
        $stmt = $conn->prepare("UPDATE mamabe_source SET sort_order = ? WHERE id = ?");
        foreach ($ids as $pos => $rowId) {
            $order = $pos + 1;
            $stmt->bind_param('ii', $order, $rowId);
            $stmt->execute();
        }
        $stmt->close();
    }
    header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'delete_source') {
    // Permanent delete - only for an option no submission ever used (otherwise past registrations would lose their
    // label; Hide is the way to retire those). 'confinement_center' is fixed: the public form switches to the Avida
    // outlet list by that name, and MamabeRecords::ensureSourceTable() re-creates it anyway.
    MamabeCsrf::verify();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT value FROM mamabe_source WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($delValue);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        $sourceError = 'Option not found.';
    } elseif ($delValue === 'confinement_center') {
        $sourceError = 'Confinement center cannot be deleted - the form needs it for the Avida outlet list. Hide it instead.';
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM mamabe_submission WHERE source = ?");
        $stmt->bind_param('s', $delValue);
        $stmt->execute();
        $stmt->bind_result($used);
        $stmt->fetch();
        $stmt->close();

        if ($used > 0) {
            $sourceError = "This option was used by $used registration(s), so it cannot be deleted - hide it instead.";
        } else {
            $stmt = $conn->prepare("DELETE FROM mamabe_source WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            header('Location: ' . MAMABE_BASE . 'settings.php?source_saved=1');
            exit;
        }
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
    </form>
    <form id="srcToggleForm" method="post" style="display:none;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="toggle_source">
        <input type="hidden" name="id" id="srcToggleId">
        <input type="hidden" name="recycle" id="srcToggleRecycle">
    </form>
    <form id="srcMoveForm" method="post" style="display:none;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="move_source">
        <input type="hidden" name="id" id="srcMoveId">
        <input type="hidden" name="dir" id="srcMoveDir">
    </form>
    <form id="srcDeleteForm" method="post" style="display:none;">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="delete_source">
        <input type="hidden" name="id" id="srcDeleteId">
    </form>

    <style>
        .mamabe-src { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 14px; }
        .mamabe-src th { background: #f1f3f5; color: #495057; font-size: 12px; font-weight: 600; text-align: left; padding: 6px 8px; border-bottom: 1px solid #dee2e6; }
        .mamabe-src td { padding: 5px 8px; border-bottom: 1px solid #edf0f2; vertical-align: middle; }
        .mamabe-src tr.is-hidden td { background: #fafafa; }
        .mamabe-src tr.is-hidden input { color: #9aa0a6; }
        .mamabe-src input { font-size: 13px; padding: 4px 8px; height: 30px; box-sizing: border-box; border: 1px solid #ced4da; border-radius: 4px; width: 100%; }
        .mamabe-src input[type=number] { width: 54px; text-align: center; }
        .mamabe-src .src-pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .mamabe-src .src-pill.on  { background: #e6f6f1; color: #128C7E; }
        .mamabe-src .src-pill.off { background: #f1f3f5; color: #868e96; }
        .mamabe-src .src-actions { white-space: nowrap; text-align: center; }
        .mamabe-src .src-actions .src-btn:first-child { margin-left: 0; }
        .mamabe-src .src-btn { font-size: 12px; padding: 3px 10px; border-radius: 4px; border: 1px solid #ced4da; background: #fff; color: #333; cursor: pointer; margin-left: 4px; line-height: 1.5; }
        .mamabe-src .src-btn:hover { background: #f1f3f5; }
        .mamabe-src .src-btn.icon { width: 30px; height: 28px; padding: 0; line-height: 0; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; color: #495057; }
        .mamabe-src .src-btn.save { color: #128C7E; border-color: #b7e4d8; }
        .mamabe-src .src-btn.save:hover { background: #128C7E; color: #fff; border-color: #128C7E; }
        .mamabe-src .src-btn.show { color: #0d6efd; border-color: #b6d0fb; }
        .mamabe-src .src-btn.show:hover { background: #0d6efd; color: #fff; border-color: #0d6efd; }
        .mamabe-src .src-btn.del { color: #c62828; border-color: #f1c4c4; }
        .mamabe-src .src-btn.del:hover { background: #c62828; color: #fff; border-color: #c62828; }
        .mamabe-src .src-move { white-space: nowrap; padding-right: 0; }
        .mamabe-src .src-move button { width: 20px; height: 22px; padding: 0; line-height: 0; border: 1px solid #dee2e6; background: #fff; color: #495057; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; }
        .mamabe-src .src-move button:first-child { border-radius: 4px 0 0 4px; }
        .mamabe-src .src-move button:last-child  { border-radius: 0 4px 4px 0; border-left: 0; }
        .mamabe-src .src-move button:hover:not(:disabled) { background: #0d6efd; color: #fff; border-color: #0d6efd; }
        .mamabe-src .src-move button:disabled { color: #ced4da; cursor: default; }
        .mamabe-src .src-btn-gap { display: inline-block; width: 30px; margin-left: 4px; vertical-align: middle; }
        .mamabe-src-add { display: flex; gap: 8px; align-items: center; }
        .mamabe-src-add input { flex: 1; font-size: 13px; padding: 4px 8px; height: 30px; box-sizing: border-box; border: 1px solid #ced4da; border-radius: 4px; }
        .mamabe-src-add button { font-size: 12px; padding: 0 16px; height: 30px; border-radius: 4px; border: 0; background: #0d6efd; color: #fff; font-weight: 600; cursor: pointer; }
    </style>
    <table class="mamabe-src">
        <tr>
            <th style="width:44px;"></th><th>Label</th><th style="width:80px;text-align:center;">Status</th><th style="width:120px;text-align:center;">Action</th>
        </tr>
        <?php if ($sourceRows): foreach ($sourceRows as $k => $s): $sid = (int)$s['id'];
            // ▲/▼ only move within the same group (active / hidden) - no arrow past the group's first/last row.
            $canUp   = isset($sourceRows[$k - 1]) && $sourceRows[$k - 1]['recycle'] == $s['recycle'];
            $canDown = isset($sourceRows[$k + 1]) && $sourceRows[$k + 1]['recycle'] == $s['recycle'];
        ?>
        <tr<?php echo $s['recycle'] ? ' class="is-hidden"' : ''; ?>>
            <td class="src-move">
                <button type="button" title="Move up" aria-label="Move up" <?php echo $canUp ? 'onclick="mamabeMoveSource(' . $sid . ', \'up\')"' : 'disabled'; ?>>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
                </button><button type="button" title="Move down" aria-label="Move down" <?php echo $canDown ? 'onclick="mamabeMoveSource(' . $sid . ', \'down\')"' : 'disabled'; ?>>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </button>
            </td>
            <td><input type="text" id="src_label_<?php echo $sid; ?>" maxlength="100" value="<?php echo htmlspecialchars($s['label']); ?>"></td>
            <td style="text-align:center;"><?php echo $s['recycle'] ? '<span class="src-pill off">Hidden</span>' : '<span class="src-pill on">Active</span>'; ?></td>
            <td class="src-actions">
                <button type="button" class="src-btn icon save" title="Save" aria-label="Save" onclick="mamabeSaveSource(<?php echo $sid; ?>)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                </button>
                <?php if ($s['recycle']): ?>
                <button type="button" class="src-btn icon show" title="Unhide (show on the form)" aria-label="Unhide" onclick="mamabeToggleSource(<?php echo $sid; ?>, 0)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <?php else: ?>
                <button type="button" class="src-btn icon" title="Hide from the form" aria-label="Hide" onclick="mamabeToggleSource(<?php echo $sid; ?>, 1)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
                </button>
                <?php endif; ?>
                <?php if ($s['value'] !== 'confinement_center'): ?>
                <button type="button" class="src-btn icon del" title="Delete permanently" aria-label="Delete"
                        onclick="mamabeDeleteSource(<?php echo $sid; ?>, <?php echo htmlspecialchars(json_encode($s['label']), ENT_QUOTES); ?>)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                </button>
                <?php else: ?>
                <span class="src-btn-gap"></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="4" class="alpro-center alpro-muted">No options yet.</td></tr>
        <?php endif; ?>
    </table>
    <script>
    function mamabeSaveSource(id) {
        document.getElementById('srcEditId').value = id;
        document.getElementById('srcEditLabel').value = document.getElementById('src_label_' + id).value;
        document.getElementById('srcEditForm').submit();
    }
    function mamabeToggleSource(id, newRecycle) {
        document.getElementById('srcToggleId').value = id;
        document.getElementById('srcToggleRecycle').value = newRecycle;
        document.getElementById('srcToggleForm').submit();
    }
    function mamabeMoveSource(id, dir) {
        document.getElementById('srcMoveId').value = id;
        document.getElementById('srcMoveDir').value = dir;
        document.getElementById('srcMoveForm').submit();
    }
    function mamabeDeleteSource(id, label) {
        if (!confirm('Delete "' + label + '" permanently? This cannot be undone.')) return;
        document.getElementById('srcDeleteId').value = id;
        document.getElementById('srcDeleteForm').submit();
    }
    </script>

    <div style="font-weight:600;font-size:13px;margin-bottom:6px;">Add a new option</div>
    <form method="post" class="mamabe-src-add">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="mode" value="add_source">
        <input type="text" id="src_label" name="label" maxlength="100" required placeholder="Label shown on the form, e.g. Our website" aria-label="Label shown on the form">
        <button type="submit">+ Add</button>
    </form>
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
