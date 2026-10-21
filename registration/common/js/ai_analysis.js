/* Mamabe AI Analysis: tiles + breakdowns + paged list of AI reads from ajax_ai_log.php.
 * A filter change reloads everything (summary=1); paging / sorting reloads only the list. */
(function () {
    var el = function (id) { return document.getElementById(id); };
    var fFrom = el('fFrom'), fTo = el('fTo'), fWho = el('fWho'), fResult = el('fResult'), fAi = el('fAi'), fSort = el('fSort');
    var tbody = el('aiRows'), pager = el('aiPager'), count = el('aiCount');
    var defFrom = fFrom.value, defTo = fTo.value;
    var page = 1;
    var COLS = 9;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function secs(v) { return v === null || v === undefined ? '–' : v + ' s'; }
    function pill(label, colour) { return '<span class="mamabe-pill mamabe-pill-' + esc(colour) + '">' + esc(label) + '</span>'; }

    function query(withSummary) {
        return 'from=' + encodeURIComponent(fFrom.value) + '&to=' + encodeURIComponent(fTo.value)
            + '&who=' + encodeURIComponent(fWho.value) + '&result=' + encodeURIComponent(fResult.value)
            + '&ai=' + encodeURIComponent(fAi.value) + '&sort=' + encodeURIComponent(fSort.value)
            + '&page=' + page + (withSummary ? '&summary=1' : '');
    }

    function tile(label, value, hint) {
        return '<div class="mamabe-kpi"><div class="mamabe-kpi-label">' + esc(label) + '</div>'
            + '<div class="mamabe-kpi-value">' + esc(value) + '</div>'
            + (hint ? '<div class="mamabe-kpi-hint">' + esc(hint) + '</div>' : '') + '</div>';
    }

    function renderSummary(s) {
        el('aiKpis').innerHTML = s.n === 0
            ? '<div class="alpro-center alpro-muted" style="grid-column:1/-1;padding:10px;">No AI reads logged for these filters yet.</div>'
            : tile('AI reads', s.n)
              + tile('Average AI time', secs(s.avg_s), 'median ' + secs(s.median_s))
              + tile('90% finish within', secs(s.p90_s), 'slowest ' + secs(s.max_s))
              + tile('Average queue wait', secs(s.avg_queue_s), 'before the AI starts')
              + tile('Average customer wait', secs(s.avg_total_s), 'queue + AI, per document')
              + tile('AI failed', s.failed, 'errors / timeouts');
        count.textContent = s.n ? '(' + s.n + ' reads)' : '';
    }

    function renderGroup(id, rows) {
        el(id).innerHTML = rows.length
            ? rows.map(function (r) {
                return '<tr><td>' + esc(r.key) + '</td><td>' + r.n + '</td><td>' + secs(r.avg_s) + '</td><td>' + secs(r.max_s) + '</td>'
                    + '<td>' + (r.failed ? '<span class="mamabe-bad">' + r.failed + '</span>' : '0') + '</td></tr>';
            }).join('')
            : '<tr><td colspan="5" class="alpro-center alpro-muted">No data.</td></tr>';
    }

    function renderRows(d) {
        page = d.page;
        if (!d.rows.length) { tbody.innerHTML = '<tr><td colspan="' + COLS + '" class="alpro-center alpro-muted">No AI reads found.</td></tr>'; pager.innerHTML = ''; return; }
        tbody.innerHTML = d.rows.map(function (r) {
            return '<tr>'
                + '<td>' + esc(r.started_at) + '</td>'
                + '<td>#' + r.submission_id + ' <span class="mamabe-muted">' + esc(r.who) + '</span></td>'
                + '<td>' + secs(r.queue_s) + '</td>'
                + '<td>' + secs(r.ai_s) + '</td>'
                + '<td>' + secs(r.total_s) + '</td>'
                + '<td>' + (r.photo ? esc(r.photo) + ' <span class="mamabe-muted">' + r.kb + ' KB</span>' : '<span class="mamabe-muted">–</span>') + '</td>'
                + '<td><span class="mamabe-muted">' + esc(r.setup) + '</span></td>'
                + '<td>' + (r.ai_ok ? '<span class="mamabe-ok">&#10004; Answered</span>'
                                    : '<span class="mamabe-bad">&#10008; Failed</span>'
                                      + (r.ai_error ? '<div class="mamabe-muted mamabe-small" style="white-space:normal;max-width:260px;">' + esc(r.ai_error) + '</div>' : '')) + '</td>'
                + '<td>' + pill(r.result_label, r.result_pill) + '</td>'
                + '</tr>';
        }).join('');
        renderPager(d);
    }

    function pageLink(label, target) {
        return '<a href="#" data-page="' + target + '">' + label + '</a>';
    }

    function renderPager(d) {
        if (d.pages <= 1) { pager.innerHTML = ''; return; }
        var html = '<div class="mamabe-pagination"><span class="info">Page ' + d.page + ' of ' + d.pages + ' (' + d.total + ' reads)</span>';
        if (d.page > 1) html += pageLink('&laquo;', 1) + pageLink('&lsaquo;', d.page - 1);
        var start = Math.max(1, d.page - 3), end = Math.min(d.pages, d.page + 3);
        for (var i = start; i <= end; i++) html += i === d.page ? '<span class="current">' + i + '</span>' : pageLink(String(i), i);
        if (d.page < d.pages) html += pageLink('&rsaquo;', d.page + 1) + pageLink('&raquo;', d.pages);
        pager.innerHTML = html + '</div>';
    }

    function load(withSummary) {
        tbody.innerHTML = '<tr><td colspan="' + COLS + '" class="alpro-center alpro-muted">Loading&hellip;</td></tr>';
        fetch('ajax_ai_log.php?' + query(withSummary), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                if (withSummary) {
                    renderSummary(d.summary);
                    renderGroup('aiByPhoto', d.by_photo);
                    renderGroup('aiByWho', d.by_who);
                    renderGroup('aiBySetup', d.by_setup);
                    renderGroup('aiByDay', d.by_day);
                }
                renderRows(d);
            })
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="' + COLS + '" class="alpro-center alpro-muted">Could not load. Reload the page (your session may have expired).</td></tr>';
                pager.innerHTML = '';
            });
    }

    function reloadAll() { page = 1; load(true); }

    pager.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-page]');
        if (!a) return;
        e.preventDefault();
        page = parseInt(a.getAttribute('data-page'), 10);
        load(false);
    });
    el('btnApply').addEventListener('click', reloadAll);
    el('btnRefresh').addEventListener('click', function () { load(true); });
    el('btnReset').addEventListener('click', function () {
        fFrom.value = defFrom; fTo.value = defTo; fWho.value = ''; fResult.value = ''; fAi.value = ''; fSort.value = 'new';
        reloadAll();
    });
    [fWho, fResult, fAi, fFrom, fTo].forEach(function (s) { s.addEventListener('change', reloadAll); });
    fSort.addEventListener('change', function () { page = 1; load(false); });

    load(true);
})();
