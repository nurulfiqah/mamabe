<?php
/**
 * Mamabe email templates - Admin / Super Admin only (header.php enforces it via $mamabe_access = 'admin', the
 * default). Lets staff edit the Subject / Header title / Body of the Approve and Reject notification emails
 * (common/lib/Mailer.php's sendMamabeApprovalEmail / sendMamabeRejectionEmail) without touching code - a saved
 * row in mamabe_email_template overrides the hardcoded default from that point on; past sent emails are unaffected.
 */
$page_title = 'MamaBe – Email Templates';
$active_tab = 'email_templates';
require_once(dirname(__FILE__) . '/header.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');
require_once(dirname(__FILE__) . '/common/lib/Mailer.php');

mamabeEnsureEmailTemplateTable($conn);

$templateLabels = array('approval' => 'Approve Email', 'approval_physical' => 'Approve Email (Physical Voucher)', 'rejection' => 'Reject Email');
$templateHints  = array(
    'approval'  => 'Sent when staff click Approve and a digital voucher is assigned. The voucher code is always shown to the customer automatically - no need to type it. You can use {{name}} to insert the customer\'s name.',
    'approval_physical' => 'Sent instead of the above when she already has a physical voucher from registering at an outlet (no digital code to assign). You can use {{name}} to insert the customer\'s name.',
    'rejection' => 'Sent when staff click Reject on the submissions list. You can use {{name}} to insert the customer\'s name.',
);

$msg = '';
$msg_type = 'success';
$guideError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'redeem_guide_upload') {
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
                header('Location: ' . MAMABE_BASE . 'email_templates.php?guide_saved=1');
                exit;
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'redeem_guide_remove') {
    MamabeCsrf::verify();
    foreach (array('jpg', 'jpeg', 'png', 'webp', 'gif') as $ext) {
        $p = dirname(__FILE__) . '/public_assets/redeem_guide.' . $ext;
        if (is_file($p)) @unlink($p);
    }
    $conn->query("DELETE FROM mamabe_setting WHERE setting_key = 'redeem_guide_ext'");
    header('Location: ' . MAMABE_BASE . 'email_templates.php?guide_saved=1');
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    MamabeCsrf::verify();
    $key = isset($_POST['template_key']) ? (string)$_POST['template_key'] : '';

    if (!isset(MAMABE_EMAIL_TEMPLATE_DEFAULTS[$key])) {
        $msg = 'Unknown template.';
        $msg_type = 'danger';
    } elseif (isset($_POST['reset'])) {
        $stmt = $conn->prepare("DELETE FROM mamabe_email_template WHERE template_key = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . MAMABE_BASE . 'email_templates.php?saved=' . urlencode($key) . '&reset=1');
        exit;
    } else {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $headerTitle = trim((string)($_POST['header_title'] ?? ''));
        $body    = (string)($_POST['body'] ?? '');

        if ($subject === '' || $headerTitle === '' || trim($body) === '') {
            $msg = 'Subject, header title and body are all required.';
            $msg_type = 'danger';
        } else {
            $by  = (int)$id_user;
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare(
                "INSERT INTO mamabe_email_template (template_key, subject, header_title, body, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE subject = VALUES(subject), header_title = VALUES(header_title), body = VALUES(body),
                                         updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)"
            );
            $stmt->bind_param('ssssis', $key, $subject, $headerTitle, $body, $by, $now);
            $stmt->execute();
            $stmt->close();
            header('Location: ' . MAMABE_BASE . 'email_templates.php?saved=' . urlencode($key));
            exit;
        }
    }
}

$saved = isset($_GET['saved']) && isset(MAMABE_EMAIL_TEMPLATE_DEFAULTS[$_GET['saved']]) ? $_GET['saved'] : '';
$wasReset = isset($_GET['reset']);

$guideExt = '';
$guideRes = $conn->query("SELECT setting_value FROM mamabe_setting WHERE setting_key = 'redeem_guide_ext' LIMIT 1");
if ($guideRes && ($guideRow = $guideRes->fetch_assoc())) $guideExt = (string)$guideRow['setting_value'];
$guideFile = $guideExt !== '' ? dirname(__FILE__) . '/public_assets/redeem_guide.' . $guideExt : '';
if ($guideFile !== '' && !is_file($guideFile)) { $guideExt = ''; $guideFile = ''; }
?>

<fieldset style="width:100%;box-sizing:border-box;margin-bottom:20px;">
    <legend><b>Redeem Voucher Guide Image</b></legend>
    <p class="alpro-muted" style="font-size:12px;margin:0 0 12px;">
        Added to the bottom of both Approve emails above (digital and physical voucher) - shows the customer how to redeem it. Same image the public form's success screen shows. No image = the section simply doesn't show.
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
    <form method="post" style="display:inline;" onsubmit="return confirm('Remove the redeem guide image? Both approval emails and the public form will stop showing it.');">
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

<?php foreach (array_keys(MAMABE_EMAIL_TEMPLATE_DEFAULTS) as $key): $tpl = mamabeGetEmailTemplate($conn, $key); ?>
<fieldset style="width:100%;box-sizing:border-box;margin-bottom:20px;">
    <legend><b><?php echo htmlspecialchars($templateLabels[$key]); ?></b></legend>

    <?php if ($saved === $key): ?>
    <div class="alpro-alert alpro-success" role="alert"><?php echo $wasReset ? 'Reset to the default text.' : 'Saved.'; ?></div>
    <?php endif; ?>
    <?php if ($msg && ($_POST['template_key'] ?? '') === $key): ?>
    <div class="alpro-alert alpro-danger" role="alert"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <p class="alpro-muted" style="font-size:12px;margin:0 0 10px;"><?php echo $templateHints[$key]; ?></p>

    <form method="post">
        <?php echo MamabeCsrf::field(); ?>
        <input type="hidden" name="template_key" value="<?php echo htmlspecialchars($key); ?>">

        <div class="alpro-field" style="margin-bottom:10px;">
            <label for="subject_<?php echo $key; ?>">Subject</label>
            <input type="text" class="alpro-input" id="subject_<?php echo $key; ?>" name="subject" maxlength="255" required
                   value="<?php echo htmlspecialchars(($_POST['template_key'] ?? '') === $key && $msg ? ($_POST['subject'] ?? '') : $tpl['subject']); ?>">
        </div>

        <div class="alpro-field" style="margin-bottom:10px;">
            <label for="header_title_<?php echo $key; ?>">Header title <span class="alpro-muted" style="font-weight:normal;">(shown at the top of the email, under the logo)</span></label>
            <input type="text" class="alpro-input" id="header_title_<?php echo $key; ?>" name="header_title" maxlength="255" required
                   value="<?php echo htmlspecialchars(($_POST['template_key'] ?? '') === $key && $msg ? ($_POST['header_title'] ?? '') : $tpl['header_title']); ?>">
        </div>

        <div class="alpro-field" style="margin-bottom:10px;">
            <label for="body_<?php echo $key; ?>">Message</label>
            <textarea class="alpro-input" id="body_<?php echo $key; ?>" name="body" rows="8" style="width:100%;box-sizing:border-box;" required><?php echo htmlspecialchars(($_POST['template_key'] ?? '') === $key && $msg ? ($_POST['body'] ?? '') : $tpl['body']); ?></textarea>
        </div>

        <button type="submit" class="alpro-btn alpro-btn-blue">Save</button>
        <button type="submit" name="reset" value="1" class="alpro-btn alpro-btn-grey" onclick="return confirm('Reset this email back to the default text?');">Reset to Default</button>
    </form>
</fieldset>
<?php endforeach; ?>

<?php require_once(dirname(__FILE__) . '/footer.php'); ?>
