<?php
/**
 * AI Analysis - how fast / how well the AI reads the submitted documents, from the timing log (mamabe_ai_log, one
 * row per AI read by worker.php - see MamabeVerifier::logAiRead). Only for staff with the MamaBe permission = 1 in
 * Digital Innovation (common/auth.php's 'ai' rule - a separate rule from Admin, neither implies the other).
 * Tiles + breakdowns and a paged list of reads, all loaded via AJAX from ajax_ai_log.php. Shows no names or images.
 */
$page_title    = 'MamaBe – AI Analysis';
$active_tab    = 'ai';
$mamabe_access = 'ai';
require_once(dirname(__FILE__) . '/header.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');
$page_js = MAMABE_BASE . 'common/js/ai_analysis.js';
$meta    = MamabeVerifier::statusMeta();
?>

<fieldset>
    <legend><b>AI Analysis</b> <span class="mamabe-count" id="aiCount"></span></legend>

    <div class="mamabe-search-bar">
        <label for="fFrom" class="mamabe-muted">From</label>
        <input type="date" id="fFrom" value="<?php echo date('Y-m-d', strtotime('-29 days')); ?>">
        <label for="fTo" class="mamabe-muted">To</label>
        <input type="date" id="fTo" value="<?php echo date('Y-m-d'); ?>">
        <select id="fWho">
            <option value="">– Document: All –</option>
            <option value="pink">Mother (pink book)</option>
            <option value="baby">Baby (baby book)</option>
        </select>
        <select id="fResult">
            <option value="">– Result: All –</option>
            <option value="<?php echo MamabeVerifier::STATUS_PROCEED; ?>"><?php echo htmlspecialchars($meta[MamabeVerifier::STATUS_PROCEED][0]); ?></option>
            <option value="<?php echo MamabeVerifier::STATUS_PENDING; ?>"><?php echo htmlspecialchars($meta[MamabeVerifier::STATUS_PENDING][0]); ?></option>
        </select>
        <select id="fAi">
            <option value="">– AI answer: All –</option>
            <option value="ok">Answered</option>
            <option value="fail">Failed / timed out</option>
        </select>
        <button type="button" id="btnApply" class="alpro-btn alpro-btn-blue">Apply</button>
        <button type="button" id="btnReset" class="alpro-btn alpro-btn-grey">Reset</button>
        <button type="button" id="btnRefresh" class="alpro-btn alpro-btn-grey">&#8635; Refresh</button>
    </div>

    <div class="mamabe-kpis" id="aiKpis"></div>

    <div class="mamabe-ai-grid">
        <div class="mamabe-card-mini">
            <div class="mamabe-card-mini-title">By photo resolution <span class="mamabe-muted mamabe-small">(shorter side of the stored photo)</span></div>
            <div class="mamabe-table-wrap"><table class="myTable alpro-table"><thead><tr><th>Photo</th><th>Reads</th><th>Avg AI</th><th>Slowest</th><th>Failed</th></tr></thead><tbody id="aiByPhoto"></tbody></table></div>
        </div>
        <div class="mamabe-card-mini">
            <div class="mamabe-card-mini-title">By document</div>
            <div class="mamabe-table-wrap"><table class="myTable alpro-table"><thead><tr><th>Document</th><th>Reads</th><th>Avg AI</th><th>Slowest</th><th>Failed</th></tr></thead><tbody id="aiByWho"></tbody></table></div>
        </div>
        <div class="mamabe-card-mini">
            <div class="mamabe-card-mini-title">By AI setup <span class="mamabe-muted mamabe-small">(model @ image size sent)</span></div>
            <div class="mamabe-table-wrap"><table class="myTable alpro-table"><thead><tr><th>Setup</th><th>Reads</th><th>Avg AI</th><th>Slowest</th><th>Failed</th></tr></thead><tbody id="aiBySetup"></tbody></table></div>
        </div>
        <div class="mamabe-card-mini">
            <div class="mamabe-card-mini-title">By day</div>
            <div class="mamabe-table-wrap" style="max-height:260px;overflow-y:auto;"><table class="myTable alpro-table"><thead><tr><th>Day</th><th>Reads</th><th>Avg AI</th><th>Slowest</th><th>Failed</th></tr></thead><tbody id="aiByDay"></tbody></table></div>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>
        <b>AI Reads</b>
        <span style="float:right;font-weight:normal;">
            <label for="fSort" class="mamabe-muted" style="font-size:0.85em;">Sort</label>
            <select id="fSort" style="width:auto;height:28px;">
                <option value="new">Newest first</option>
                <option value="slow">Slowest first</option>
            </select>
        </span>
    </legend>
    <p class="mamabe-muted mamabe-small"><b>Queue</b> = wait from submitting until the worker picked the document up;
       <b>AI</b> = the AI call itself; <b>Total</b> = what the customer waits for this document. A timeout shows as about 300s with AI Failed.</p>
    <div class="mamabe-table-wrap">
        <table class="myTable alpro-table" width="100%">
            <thead>
                <tr>
                    <th>Started</th>
                    <th>Submission</th>
                    <th>Queue</th>
                    <th>AI</th>
                    <th>Total</th>
                    <th>Photo</th>
                    <th>Setup</th>
                    <th>AI answer</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody id="aiRows">
                <tr><td colspan="9" class="alpro-center alpro-muted">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
    <div id="aiPager"></div>
</fieldset>

<?php require_once(dirname(__FILE__) . '/footer.php'); ?>
