<?php
/**
 * Mamabe submissions - Admin / Super Admin view of the MamaBe website registrations (already saved into
 * mamabe_mama / mamabe_baby) and what the AI verification decided (Proceed / Pending). One row per registration
 * EVENT (mother + baby together for a 'both' registration, via mamabe_submission.batch_id - see api/register.php),
 * with one line per PERSON inside it (the mother, then each baby), each with their own independent result. Loaded
 * via AJAX (ajax_list.php) with pagination.
 */
$page_title = 'MamaBe – Website Registrations';
$active_tab = 'submissions';
require_once(dirname(__FILE__) . '/header.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');
require_once(dirname(__FILE__) . '/common/lib/Csrf.php');
$page_js = MAMABE_BASE . 'common/js/list.js';
$typeLabels = array(1 => 'Mother only', 2 => 'Baby only', 3 => 'Both');
?>

<fieldset>
    <legend><b>Website Registrations</b> <span class="mamabe-count" id="mamabeCount"></span></legend>

    <div class="mamabe-search-bar">
        <select id="fType">
            <option value="">– Type: All –</option>
            <?php foreach ($typeLabels as $code => $label): ?>
            <option value="<?php echo (int)$code; ?>"><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="fStatus">
            <option value="">– Result: All –</option>
            <?php foreach (MamabeVerifier::statusMeta() as $code => $m): ?>
            <option value="<?php echo (int)$code; ?>"><?php echo htmlspecialchars($m[0]); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="fQ" placeholder="Mother or baby name..." autocomplete="off">
        <button type="button" id="btnApply" class="alpro-btn alpro-btn-blue">Search</button>
        <button type="button" id="btnReset" class="alpro-btn alpro-btn-grey">Reset</button>
        <button type="button" id="btnRefresh" class="alpro-btn alpro-btn-grey" title="Reload the list (checks finish a few seconds after submission)">&#8635; Refresh</button>
        <?php if (MAMABE_PUBLIC_FORM_URL !== ''): ?>
        <a href="<?php echo htmlspecialchars(MAMABE_PUBLIC_FORM_URL); ?>" target="_blank" rel="noopener" class="alpro-btn alpro-btn-grey">Public Form &#8599;</a>
        <?php endif; ?>
    </div>
    <p class="mamabe-muted mamabe-small">Result filters by <b>person</b>, not event &ndash; e.g. Pending shows exactly who (mother and/or which baby) needs a manual look; the rest of that event's people still show alongside them.</p>

    <div class="mamabe-table-wrap">
    <table class="myTable alpro-table mamabe-list" width="100%">
        <thead>
            <tr>
                <th class="mamabe-col-date">Submitted</th>
                <th>Who</th>
                <th>Name</th>
                <th class="mamabe-col-doc">Attachment</th>
                <th>Extracted from image</th>
                <th>Result</th>
                <th>Approve / Reject</th>
            </tr>
        </thead>
        <tbody id="mamabeRows">
            <tr><td colspan="7" class="alpro-center alpro-muted">Loading&hellip;</td></tr>
        </tbody>
    </table>
    </div>

    <div id="mamabePager"></div>
    <?php echo MamabeCsrf::field(); /* for the "Ask AI to re-read" button (ajax_reread.php) */ ?>
</fieldset>

<!-- click-to-enlarge -->
<div class="mamabe-lightbox" id="imgModal" hidden>
    <div class="mamabe-lightbox-box">
        <div class="mamabe-lightbox-head">
            <b id="imgModalTitle">Attachment</b>
            <button type="button" id="imgModalClose" class="alpro-btn alpro-btn-grey">Close</button>
        </div>
        <div class="mamabe-lightbox-body">
            <img id="imgModalImg" src="" alt="Attachment">
        </div>
    </div>
</div>

<?php require_once(dirname(__FILE__) . '/footer.php'); ?>
