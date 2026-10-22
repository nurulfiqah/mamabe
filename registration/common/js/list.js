/* MamaBe website registrations list: one row per PERSON (mother, then each baby), grouped under the registration
 * event they were submitted in together (a 'both' registration = mother + baby rows sharing one event; Submitted
 * spans the group). Loads from ajax_list.php, click a thumbnail to enlarge. */
(function () {
    var tbody = document.getElementById('mamabeRows');
    var pager = document.getElementById('mamabePager');
    var count = document.getElementById('mamabeCount');
    var fType = document.getElementById('fType');
    var fStatus = document.getElementById('fStatus');
    var fQ = document.getElementById('fQ');
    var modal = document.getElementById('imgModal');
    var modalImg = document.getElementById('imgModalImg');
    var COLS = document.querySelectorAll('.mamabe-list thead th').length;   // 8 with the Admin-only Action column
    var detailUrl = '';
    var canDelete = false;
    var TRASH_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
        + '<path d="M10 11v6"/><path d="M14 11v6"/></svg>';
    var page = 1;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function dash() { return '<span class="mamabe-muted">&ndash;</span>'; }
    function line(label, html) {
        return '<div class="mamabe-read"><span class="mamabe-muted">' + label + '</span> ' + html + '</div>';
    }

    /** Typed name + tick/cross once the AI has read the document (true/false), or "(via mother name)" when a baby's
     *  own name didn't match but the mother's name on that same document did (the fallback - see MamabeVerifier). */
    function nameCell(p) {
        var mark = p.name_match === null ? '' : (p.name_match
            ? ' <span class="mamabe-ok" title="Matches the image">&#10004;</span>'
            : ' <span class="mamabe-bad" title="Does not match the image">&#10008;</span>');
        var via = p.via_mother_name ? ' <span class="mamabe-muted mamabe-small">(via mother name)</span>' : '';
        var tag = '';
        if (p.who === 'Mother' && p.is_first) {
            tag = p.mama_is_new
                ? '<span class="mamabe-tag mamabe-tag-new">New mama</span>'
                : '<span class="mamabe-tag mamabe-tag-ok">Existing mama</span>';
            if (p.mama_id && detailUrl) tag = '<a href="' + esc(detailUrl + p.mama_id) + '" target="_blank" rel="noopener">' + tag + '</a>';
            tag = '<div>' + tag + '</div>';
            if (p.mama_email) tag += '<div class="mamabe-muted mamabe-small">' + esc(p.mama_email) + '</div>';
        }
        var repeat = p.repeat ? '<div><span class="mamabe-tag mamabe-tag-new" title="This mama already sent this same kind of registration earlier - her record was not duplicated">Repeat submission</span></div>' : '';
        return esc(p.name) + mark + via + tag + repeat;
    }

    function thumb(p, doc, label) {
        return '<img class="mamabe-thumb" loading="lazy" alt="' + label + '" title="Click to enlarge"'
            + ' data-src="file.php?id=' + p.id + '&doc=' + doc + '" data-name="' + esc(label + ' – ' + p.name) + '"'
            + ' src="file.php?id=' + p.id + '&doc=' + doc + '&thumb=1">';
    }

    function nasLine(p) {
        if (p.nas === 'done') return '<div class="mamabe-muted mamabe-small">Saved to MamaBe record</div>';
        if (p.nas === 'waiting') return '';
        return '<div class="mamabe-bad mamabe-small" title="' + esc(p.nas) + '">Not copied to NAS yet (retrying)</div>';
    }

    /** 100px thumbnail of this person's own document (pink book for Mother, baby book for Baby). */
    function attachmentCell(p) {
        if (p.who === 'Mother' && p.has_pink_book) return thumb(p, 'pink', 'Pink book') + nasLine(p);
        if (p.who === 'Baby' && p.has_baby_book) return thumb(p, 'baby', 'Baby health book') + nasLine(p);
        return '<span class="mamabe-muted">No Document</span>';
    }

    /** What the AI read off this person's document, each field with its own tick/cross. */
    function extractedCell(p) {
        if (p.status === 0) return '<span class="mamabe-muted mamabe-small">Waiting to be checked&hellip;</span>';
        if (p.status === 1) return '<span class="mamabe-muted mamabe-small">Checking&hellip;</span>';
        function read(v, ok) {
            if (v === null) return dash();
            if (v === '') return '<span class="mamabe-muted">(nothing read)</span>';
            var mark = ok === null ? '' : (ok
                ? ' <span class="mamabe-ok" title="Passes the check">&#10004;</span>'
                : ' <span class="mamabe-bad" title="Fails the check">&#10008;</span>');
            return '<b>' + esc(v) + '</b>' + mark;
        }
        var html = '';
        if (p.who === 'Mother') {
            html += line('Mother:', read(p.extracted_mother, p.name_match));
            html += line('EDD:', read(p.extracted_edd, p.edd_ok));
        } else {
            html += line('Baby:', read(p.extracted_child, p.name_match));
            html += line('Mother on doc:', read(p.extracted_mother, p.mother_on_doc_ok));
            var dobNote = p.dob_via_mykid ? ' <span class="mamabe-muted mamabe-small">(DOB confirmed via MyKid)</span>' : '';
            html += line('DOB:', read(p.extracted_dob, p.dob_ok) + dobNote);
            if (p.extracted_mykid) html += line('MyKid read:', '<b>' + esc(p.extracted_mykid) + '</b>');
        }
        return html;
    }

    /** This person's own Proceed/Pending pill + why, if pending. */
    function resultCell(p) {
        var html = '<span class="mamabe-pill mamabe-pill-' + esc(p.status_pill) + '">' + esc(p.status_label) + '</span>';
        if (p.note) html += '<div class="mamabe-note">' + esc(p.note) + '</div>';
        return html;
    }

    /** Put one row back in the AI queue (ajax_reread.php); the list then refreshes itself until it is checked. */
    function reread(btn) {
        var csrf = document.querySelector('input[name="csrf_token"]');
        var body = new FormData();
        body.append('id', btn.getAttribute('data-reread'));
        body.append('csrf_token', csrf ? csrf.value : '');
        btn.disabled = true;
        btn.textContent = 'Sending…';
        fetch('ajax_reread.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Your session may have expired - reload the page.' }; }); })
            .then(function (d) {
                if (!d.ok) alert(d.error || 'Could not send it to the AI.');
                load(true);
            })
            .catch(function () { alert('Could not reach the server.'); load(true); });
    }

    /** This person's own Approve/Reject column: assigns a voucher from stock, or marks it rejected (hidden from the
     *  Mama List if this submission created the data - an existing customer's record is never touched either way).
     *  Buttons only ever appear once the AI result is in AND needs a human look (status 3, Pending) - a Proceed
     *  result (status 2) is auto-approved by worker.php itself with no staff click, and while still queued/checking
     *  (0/1) there is nothing to decide yet. */
    function approveCell(p) {
        if (p.voucher_code) {
            return '<span class="mamabe-ok" title="Voucher assigned">&#10004; ' + esc(p.voucher_code) + '</span>';
        }
        if (p.approved_no_voucher) {
            return '<span class="mamabe-ok" title="Already has a physical voucher from the outlet - no digital code assigned">&#10004; Approved (physical voucher)</span>';
        }
        if (p.rejected) {
            return '<span class="mamabe-bad" title="Rejected">&#10008; Rejected</span>';
        }
        if (p.status !== 3) {
            return dash();
        }
        return '<button type="button" class="mamabe-approve-btn" data-approve="' + p.id + '">Approve</button>'
             + ' <button type="button" class="mamabe-reject-btn" data-reject="' + p.id + '">Reject</button>';
    }

    /** Assign a voucher to one person (ajax_approve.php) - confirmed first since it assigns real voucher stock. */
    function approve(btn) {
        if (!confirm('Approve this? A voucher will be assigned to them from stock, unless they already have a physical one from an outlet.')) return;
        var csrf = document.querySelector('input[name="csrf_token"]');
        var body = new FormData();
        body.append('id', btn.getAttribute('data-approve'));
        body.append('csrf_token', csrf ? csrf.value : '');
        btn.disabled = true;
        btn.textContent = 'Approving…';
        fetch('ajax_approve.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Your session may have expired - reload the page.' }; }); })
            .then(function (d) {
                if (!d.ok) { alert(d.error || 'Could not approve.'); btn.disabled = false; btn.textContent = 'Approve'; return; }
                load(true);
            })
            .catch(function () { alert('Could not reach the server.'); btn.disabled = false; btn.textContent = 'Approve'; });
    }

    /** Reject one person (ajax_reject.php) - confirmed first since it can hide data from the Mama List. */
    function reject(btn) {
        if (!confirm('Reject this? If this submission created a new mama/baby record, it will be hidden from the Mama List. An existing customer\'s own record is never touched.')) return;
        var csrf = document.querySelector('input[name="csrf_token"]');
        var body = new FormData();
        body.append('id', btn.getAttribute('data-reject'));
        body.append('csrf_token', csrf ? csrf.value : '');
        btn.disabled = true;
        btn.textContent = 'Rejecting…';
        fetch('ajax_reject.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Your session may have expired - reload the page.' }; }); })
            .then(function (d) {
                if (!d.ok) { alert(d.error || 'Could not reject.'); btn.disabled = false; btn.textContent = 'Reject'; return; }
                load(true);
            })
            .catch(function () { alert('Could not reach the server.'); btn.disabled = false; btn.textContent = 'Reject'; });
    }

    /** Delete the whole registration (ajax_delete.php, Admin only) - confirmed first since it cannot be undone. */
    function del(btn) {
        if (!confirm('Delete this whole registration (mother and all babies in it)?\n\nThis cannot be undone. New mama/baby records it created will also be hidden from the Mama List. An existing customer\'s own record is never touched.')) return;
        var csrf = document.querySelector('input[name="csrf_token"]');
        var body = new FormData();
        body.append('id', btn.getAttribute('data-delete'));
        body.append('csrf_token', csrf ? csrf.value : '');
        btn.disabled = true;
        fetch('ajax_delete.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Your session may have expired - reload the page.' }; }); })
            .then(function (d) {
                if (!d.ok) { alert(d.error || 'Could not delete.'); btn.disabled = false; return; }
                load(true);
            })
            .catch(function () { alert('Could not reach the server.'); btn.disabled = false; });
    }

    function showMessage(text) {
        tbody.innerHTML = '<tr><td colspan="' + COLS + '" class="alpro-center alpro-muted">' + esc(text) + '</td></tr>';
        pager.innerHTML = '';
    }

    /** quiet = keep the table on screen while reloading (auto-refresh / after a re-read) instead of "Loading…". */
    var pollTimer = null;
    function load(quiet) {
        var qs = 'page=' + encodeURIComponent(page)
            + '&f_type=' + encodeURIComponent(fType.value)
            + '&f_status=' + encodeURIComponent(fStatus.value)
            + '&f_q=' + encodeURIComponent(fQ.value);
        clearTimeout(pollTimer);
        if (quiet !== true) showMessage('Loading…');
        fetch('ajax_list.php?' + qs, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(render)
            .catch(function () { count.textContent = ''; showMessage('Could not load the list. Reload the page (your session may have expired).'); });
    }

    /** While anyone on this page is still queued / being checked, refresh quietly every 10 s so the result shows up. */
    function pollWhileChecking(data) {
        var waiting = data.events.some(function (ev) {
            return ev.people.some(function (p) { return p.status === 0 || p.status === 1; });
        });
        if (waiting) pollTimer = setTimeout(function () { load(true); }, 10000);
    }

    /** One event -> its people's <tr> rows, striped per event, Submitted spans the whole group. */
    function eventRows(ev, stripe) {
        var rows = '';
        ev.people.forEach(function (p, i) {
            p.is_first = i === 0;
            p.mama_id = ev.mama_id;
            p.mama_is_new = ev.mama_is_new;
            p.mama_email = ev.email;
            rows += '<tr class="' + (stripe ? 'mamabe-stripe' : '') + '">';
            if (i === 0) {
                rows += '<td rowspan="' + ev.people.length + '">' + esc(String(ev.created_at).slice(0, 10))
                     + '<div class="mamabe-muted">' + esc(String(ev.created_at).slice(11, 16)) + '</div>'
                     + '<div class="mamabe-muted mamabe-small">' + esc(ev.type_label) + '</div></td>';
            }
            rows += '<td>' + esc(p.who) + '</td>'
                 + '<td>' + nameCell(p) + '</td>'
                 + '<td>' + attachmentCell(p) + '</td>'
                 + '<td>' + extractedCell(p) + '</td>'
                 + '<td>' + resultCell(p) + '</td>'
                 + '<td>' + approveCell(p) + '</td>';
            // Action column (Admin only): one Delete per registration event, spanning all its people.
            if (canDelete && i === 0) {
                rows += '<td rowspan="' + ev.people.length + '" class="mamabe-col-action">'
                     + (ev.has_voucher
                        ? '<span class="mamabe-muted mamabe-small" title="A voucher was assigned - cannot be deleted">&ndash;</span>'
                        : '<button type="button" class="mamabe-reject-btn mamabe-icon-btn" data-delete="' + p.id + '" title="Delete this whole registration" aria-label="Delete">' + TRASH_ICON + '</button>')
                     + '</td>';
            }
            rows += '</tr>';
        });
        return rows;
    }

    function render(data) {
        page = data.page;
        detailUrl = data.detail_url || '';
        canDelete = !!data.can_delete;
        count.textContent = '(' + data.total + ' registration' + (data.total === 1 ? '' : 's') + ')';
        if (!data.events.length) { showMessage('No submissions found.'); return; }

        tbody.innerHTML = data.events.map(function (ev, i) { return eventRows(ev, i % 2 === 1); }).join('');
        renderPager(data);
        pollWhileChecking(data);
    }

    function pageLink(label, target) {
        return '<a href="#" data-page="' + target + '">' + label + '</a>';
    }

    function renderPager(d) {
        if (d.pages <= 1) { pager.innerHTML = ''; return; }
        var html = '<div class="mamabe-pagination"><span class="info">Page ' + d.page + ' of ' + d.pages + '</span>';
        if (d.page > 1) html += pageLink('&laquo;', 1) + pageLink('&lsaquo;', d.page - 1);
        var start = Math.max(1, d.page - 3), end = Math.min(d.pages, d.page + 3);
        for (var i = start; i <= end; i++) html += i === d.page ? '<span class="current">' + i + '</span>' : pageLink(String(i), i);
        if (d.page < d.pages) html += pageLink('&rsaquo;', d.page + 1) + pageLink('&raquo;', d.pages);
        pager.innerHTML = html + '</div>';
    }

    function closeModal() { modal.hidden = true; modalImg.src = ''; }

    pager.addEventListener('click', function (e) {
        var a = e.target.closest('[data-page]');
        if (!a) return;
        e.preventDefault();
        page = parseInt(a.getAttribute('data-page'), 10);
        load();
    });

    tbody.addEventListener('click', function (e) {
        var rr = e.target.closest('[data-reread]');
        if (rr) { reread(rr); return; }
        var ap = e.target.closest('[data-approve]');
        if (ap) { approve(ap); return; }
        var rj = e.target.closest('[data-reject]');
        if (rj) { reject(rj); return; }
        var dl = e.target.closest('[data-delete]');
        if (dl) { del(dl); return; }
        var img = e.target.closest('.mamabe-thumb');
        if (!img) return;
        modalImg.src = img.getAttribute('data-src');
        document.getElementById('imgModalTitle').textContent = img.getAttribute('data-name');
        modal.hidden = false;
    });

    document.getElementById('imgModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

    document.getElementById('btnApply').addEventListener('click', function () { page = 1; load(); });
    document.getElementById('btnRefresh').addEventListener('click', load);
    document.getElementById('btnReset').addEventListener('click', function () { fType.value = ''; fStatus.value = ''; fQ.value = ''; page = 1; load(); });
    fType.addEventListener('change', function () { page = 1; load(); });
    fStatus.addEventListener('change', function () { page = 1; load(); });
    fQ.addEventListener('keydown', function (e) { if (e.key === 'Enter') { page = 1; load(); } });

    load();
})();
