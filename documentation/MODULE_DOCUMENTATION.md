# MODULE_DOCUMENTATION.md — mamabe (Octopus module)

## A. Module Overview
"Mamabe" is a maternal/baby-care loyalty and benefits-tracking module inside
the Octopus internal system. It registers expectant mothers ("mama"), tracks
pregnancy/baby records, campaign-based WhatsApp blast messaging tied to
pregnancy milestones, receipt/benefit verification against a Xilnex POS
integration, and SKU master data for benefit items. Intended users are
outlet/pharmacy staff (registration, benefit verification) and super-admin
staff (SKU master, campaign management).

## B. Folder & File Structure
- `index.php` — dashboard: stat tiles, recent registrations, upcoming EDD, today's blast counts.
- `mamabe_mama_add.php` — new mama registration (Tab A: new IC, Tab B: old IC/passport).
- `mamabe_mama_list.php` — searchable/paginated mama listing.
- `mamabe_mama_detail.php` — **entry point + form handler**, largest file (~3000 lines): mama profile edit, baby records (add/update), benefit tracking (mama book, vitamin D, 50%-off items), twin records, receipt verification triggers.
- `mamabe_baby_form.php` — standalone baby-birth-registration form.
- `mamabe_sku_master.php` — CRUD for benefit-eligible SKU items (super-admin only).
- `mamabe_add_campaign.php` / `mamabe_edit_campaign.php` / `mamabe_campaign_list.php` — WhatsApp blast campaign CRUD.
- `mamabe_blast.php` — per-campaign blast target list + send-tracking UI.
- `mamabe_blast_export.php` — generates a `.js` payload of blast targets/messages for the external WhatsApp-sending tool.
- `mamabe_blast_ajax.php` — AJAX partial for marking blast rows as sent.
- `mamabe_verify_receipt.php` — AJAX: calls Xilnex API to verify a receipt number against SKU master prices.
- `mamabe_receipt_detail.php` — AJAX: fetches raw receipt detail from Xilnex API.
- `mamabe_price_mismatch.php` — admin view of receipts whose Xilnex price doesn't match SKU master.
- `mamabe_unverified.php` — admin view of benefit claims not yet receipt-verified.
- `mamabe_ajax.php` — AJAX: VIP card lookup by IC/name against `customer` table.
- `mamabe_search_customer.php` — AJAX: customer autocomplete search.
- `mamabe_vip_lookup.php` — AJAX: Xilnex client lookup by client code.
- `mamabe_sidebar.php` — shared nav include.
- `images/`, `uploads/` — static assets / pink-book file uploads.
- `*-lama` files — legacy backups, not included by anything, left untouched.

## C. Logic Flow
- **Registration**: `mamabe_mama_add.php` → INSERT `mamabe_mama` (+ `customer` upsert) → redirect to `mamabe_mama_detail.php`.
- **Pregnancy/baby CRUD**: all inside `mamabe_mama_detail.php` — `add_baby`/`update_baby` POST handlers INSERT/UPDATE `mamabe_baby`, with twin-record support and pink-book file upload via `$_FILES`.
- **Benefit tracking**: `save_benefits`/`save_baby_benefits`/`save_baby_delivery_benefits` handlers in `mamabe_mama_detail.php` toggle benefit flags with a 24h "lock" window (`$lck_*` checks) once claimed, and record a `receipt_no` that can later be verified.
- **Receipt verification**: frontend calls `mamabe_verify_receipt.php` (AJAX) → Xilnex API lookup by receipt number → compares item prices against `mamabe_sku_master` → returns match/mismatch JSON → mismatches surface in `mamabe_price_mismatch.php`.
- **Campaign/blast flow**: `mamabe_add_campaign.php`/`mamabe_edit_campaign.php` define a campaign (duration/date-type trigger + WhatsApp message templates in `mamabe_wa`) → `mamabe_blast.php` shows eligible targets for a given campaign+date → `mamabe_blast_export.php` generates the outbound message payload → `mamabe_blast_ajax.php` marks rows sent in `mamabe_blast_log`.
- **Search/filter**: `mamabe_mama_list.php` filters by search text/outlet/baby-status/month/year with pagination; `mamabe_search_customer.php`/`mamabe_ajax.php` power autocomplete widgets.

## D. Database Usage
Key tables: `mamabe_mama`, `mamabe_baby`, `mamabe_campaign`, `mamabe_wa`,
`mamabe_blast_log`, `mamabe_sku_master`, `customer`, `outlet`. Relationships:
`mamabe_baby.mama_id → mamabe_mama.id`, `mamabe_mama.customer_id → customer.id`,
`mamabe_mama.outlet_id → outlet.id`, `mamabe_wa.campaign_id → mamabe_campaign.id`.
Soft-delete via `recycle` flag throughout. Several `ALTER TABLE ... ADD COLUMN`
statements are embedded as idempotent bootstrap blocks at the top of files
(`mamabe_mama_detail.php`, `mamabe_price_mismatch.php`) — this is existing
Octopus convention, left unchanged.

## E. Authentication & Permissions
Session/login state comes from the shared `../common/index_adv.php` include
(outside this module). Role-based gating uses `$mamab_autho` (module-specific
authorization level, e.g. `'3'` = super admin) and `$nama_staff`/`$id_user`
(current staff identity), both populated by the shared include. Pages that
require it check `isset($mamab_autho)` before exposing admin actions
(SKU master edit, campaign management, super-admin-only receipt re-verify).

## F. External Dependencies
- **Xilnex POS API** (`api.xilnex.com`) — receipt verification (`mamabe_verify_receipt.php`), raw receipt detail (`mamabe_receipt_detail.php`), client lookup (`mamabe_vip_lookup.php`). Uses cURL with hardcoded `appid`/`token` headers and a local `cacert.pem` path.
- Shared includes: `../common/index_adv.php` (session/DB bootstrap, provides `$conn` mysqli instance), `../lock_adv.php` (page lock/access control).
- No CDN/frontend JS framework dependencies beyond what's already in the shared Octopus header/footer.

## G. JavaScript Architecture
JavaScript is inline within each page (no module extraction was needed —
existing structure preserved per upgrade rules). Notable AJAX flows:
- `mamabe_mama_detail.php` inline JS calls `mamabe_verify_receipt.php` (fetch/XHR) and renders match/mismatch badges.
- `mamabe_blast_ajax.php` returns an HTML fragment (not JSON) for direct DOM insertion via the calling page's `onclick` handlers (`markUnsent(...)`).
- `mamabe_ajax.php`/`mamabe_search_customer.php` return pipe-delimited/plain-text responses consumed by autocomplete widgets that split on `|`/`\n` — preserved exactly; do not change response format without also updating the consuming JS.

## H. PHP 8 Upgrade Notes
See `COMPATIBILITY_REPORT.md` for the full analysis and `FINAL_UPGRADE_SUMMARY.md`
for the exact per-file line changes. Summary: null-coalescing guards added to
~90 unguarded `$_POST`/`$_GET`/DB-column reads feeding non-nullable function
parameters; `rand()` bounds ordering fixed in `mamabe_blast_export.php` to
prevent a PHP 8 `ValueError`. No business logic, SQL, response formats, or UI
markup was altered.

## I. Testing Checklist
- [ ] Browser: load `index.php` dashboard, verify all stat tiles and lists render.
- [ ] CRUD: register a new mama via both Tab A (new IC) and Tab B (old IC/passport) in `mamabe_mama_add.php`.
- [ ] CRUD: add/update a baby record, twin record, and pink-book file upload in `mamabe_mama_detail.php`.
- [ ] CRUD: toggle each benefit type (mama book, vitamin D, 50%-off, baby book, free diapers) and confirm the 24h lock behavior.
- [ ] API: submit a receipt number in `mamabe_verify_receipt.php` flow — confirm Xilnex lookup and price-mismatch detection still work (network-dependent, needs live Xilnex sandbox/creds).
- [ ] Export/import: run `mamabe_blast_export.php` for a campaign with an unusual `send_min`/`send_max` combination (including a reversed one, e.g. min=100/max=10) to confirm the `rand()` fix prevents a crash.
- [ ] AJAX: exercise `mamabe_ajax.php`, `mamabe_search_customer.php`, `mamabe_blast_ajax.php`, `mamabe_vip_lookup.php` and confirm responses are still parsed correctly by the frontend (no stray warning text in pipe-delimited/JSON output).
- [ ] Session: confirm redirects (`header('Location: ...')`) after save actions in `mamabe_add_campaign.php`, `mamabe_edit_campaign.php`, `mamabe_mama_add.php`, `mamabe_mama_detail.php` still work under PHP 8.2 with `display_errors` set per production config.
- [ ] Search/filter/pagination in `mamabe_mama_list.php` across all filter combinations.

## J. Technical Debt / Known Risks
- SQL string-interpolation (not fully parameterized) in `mamabe_blast_export.php`'s dynamic query blocks and a few INSERT statements — see Security notes in `COMPATIBILITY_REPORT.md`.
- Hardcoded Xilnex API credentials in `mamabe_vip_lookup.php`.
- Leftover debug code (`file_put_contents(__DIR__ . '/mamabe_debug.txt', ...)`) in `mamabe_blast_export.php` — writes a debug file on every export; not a PHP 8 compatibility issue, left in place per "don't fix what wasn't asked" but flagged for cleanup.
- `mamabe_sku_master.php`'s `delete_sku` POST handler has no corresponding form in the rendered UI (only reachable via direct POST) — appears to be legacy/dead code path, left as-is.
- `htmlspecialchars()` default-flag change (PHP 8.1) is a systemic, cosmetic output difference across nearly every file — not fixed, documented as accepted behavior change.
