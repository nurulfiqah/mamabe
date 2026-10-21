# mamabe/registration — MamaBe website registrations (odb side): API, AI name check, NAS copy, admin list

The **public form lives on the education server** (package `www/mamabe_api/` — outside odb, see its README) and only forwards each registration to
**odb's API** — `/api/mamabeRegister.php`, authenticated as the `api_user` **`mamabe_api`**. Everything is stored and processed here in odb.
Anyone can sign up (open registration for the MamaBe website). A mama registers **one of three ways** (all fields required):

- **Common** — how she knows us (`MamabeRecords::sources()`: an admin-editable lookup table, `registration/settings.php`, served to the
  form by `api/sources.php` — not a hardcoded list), mama name (as per IC), IC (MyKad or passport), mobile number, email (where her
  approve/reject voucher notification is sent), address (optional), preferred language, preferred
  outlet to redeem (from `MamabeRecords::outlets()`: active Alpro Pharmacy / Alpro Baby outlets, served to the form by `api/outlets.php`).
- **Pink Book** (`register_type = pink`) — EDD + a photo of her **pink book**.
- **Baby Health Book** (`register_type = baby`) — baby name (as per IC), date of birth, baby IC (MyKid) + a photo of the **baby health book**.
- **Both** (`register_type = both`) — she is currently pregnant *and* already has a baby registered in the same visit: both the
  EDD + pink book *and* the baby's details + baby health book, all required. `api/register.php` treats this as two ordinary
  registrations (a pink one then a baby one) sharing one mama record — it saves two `mamabe_baby` rows and two
  `mamabe_submission` rows (`register_type` = `pink` then `baby`). Nothing downstream (this README's save/match rules below,
  the admin list, the AI check, the NAS sync) needs to know about `both` — every *stored* row is always `pink` or `baby`.

`api/register.php` saves it **straight into the MamaBe module's own tables**:

- **`mamabe_mama`** — matched by **IC, else mobile number** (`recycle = 0`; dashes ignored, `01x` and `601x` treated alike). An existing
  mama is only linked to (her details are never overwritten from the public form — only an empty IC / address / outlet is filled in); a
  new row is created only when neither is there yet (`customer_id = 0`, `outlet_id` = her preferred outlet,
  `registered_by = 'MamaBe website form'`). **odb's `customer` table is
  never touched** — that is the paid-membership table owned by outlets.
- **`mamabe_baby`** — one row per pregnancy / baby. `MamabeRecords::findBabyRow` puts the submission into the mama's existing row when
  one fits ("open pregnancy" = not delivered, no baby name / MyKid / DOB yet):
  - **Baby Health Book**: 1) a row with the same MyKid (re-submission) · 2) the open pregnancy whose EDD is closest to the DOB, from
    4 weeks before to 20 weeks after it (late / premature births) — the baby is filled into that pregnancy, its EDD/LMP untouched ·
    3) a row whose EDD is the DOB and has no MyKid yet · else a **new row with EDD = DOB** (`is_delivered = 1`).
  - **Twins**: a baby that fits none of those but has the **same DOB as another of her babies (other MyKid, not yet paired)** is added
    the way the MamaBe page's *add twin* does it — a second row with the sibling's LMP / EDD / pink book / pregnancy benefits copied and
    `twin_id` set on both, so the page shows "Baby A & Baby B" on one pregnancy. `twin_id` holds one pair only: a triplet's third
    baby becomes a separate row for staff to sort out.
  - **Pink Book**: 1) same EDD · 2) the open pregnancy with the closest EDD within ±30 days · else a new row.
  - **Nothing is ever overwritten**: a matched row only gets its empty fields filled (and the NAS copy only fills an empty photo slot).
  **LMP is derived as EDD − 280 days** for new rows — the MamaBe module's own rule, and its page needs LMP set. Windows are the
  `BIRTH_*_EDD_DAYS` / `EDD_SAME_PREGNANCY_DAYS` constants.
- **`mamabe_submission`** — what she typed, plus `register_type`, `source` (kept only here) and `preferred_outlet_id` (as she picked it,
  even when her `mamabe_mama.outlet_id` was already set).

The visitor only ever sees *"Submitted successfully"* — the form never reveals whether the mama was already registered. In the background the
AI reads the mother's name off the pink book — or, for a Baby Health Book registration, the baby's **and** the mother's name off the baby
health book; if every checked name matches what was typed the row becomes **Proceed**, otherwise **Pending**. The worker then copies the images to the NAS folders the MamaBe module uses and
links them on `mamabe_baby`, so they show on `mamabe/mamabe_mama_detail.php`. Admin / Super Admin review everything in `index.php`.

```
EDUCATION SERVER (public)                         ODB (internal)
form.php ─▶ api/submit.php ── HTTPS, api_user──▶  /api/mamabeRegister.php ─▶ mamabe/registration/api/register.php
            (proxy, api/.env)                       │  saves at once ─▶ mamabe_mama / mamabe_baby   (never `customer`)
                                                    └▶ mamabe_submission + uploads/ ──queue──▶ worker.php
                                                                     ▲                          │ AI (common/ai_vision)
                                                       proceed / pending ◀─────────────────────┘ (NAS copy first)
                                   index.php + ajax_list.php + file.php  (MamaBe Admin / Super Admin)
```

## Files
| File | Purpose |
|---|---|
| `api/register.php` | **The API handler** (reached only via `/api/mamabeRegister.php` — `api/.htaccess` blocks direct HTTP). Authenticates the caller (`common/api_auth.php`), rate-limits on the visitor IP the proxy forwards (`client_ip`), validates everything (register type, source, Malaysian mobile number, outlet from the allowed list, EDD within −6 years…+10 months, baby DOB within 6 years and not in the future, MyKid date + place-of-birth sanity check), re-encodes the photo, saves into `mamabe_mama` / `mamabe_baby` + `mamabe_submission` in **one transaction**, queues the AI check, pings the worker. Replies `{status, message}` like the other `/api` endpoints; messages are written for the visitor and never reveal whether the mama existed. |
| `api/outlets.php` | Outlet list for the form's state → "Preferred outlet to redeem" pickers (`{status, outlets:[{id,name,state}]}`), same auth. Reached via `/api/mamabeOutlets.php`. `outlet` has no state column, so `MamabeRecords::stateOf()` reads it from the address: the state name written last wins, else the postcode, else a few Sarawak towns, else *Others*. An outlet in the wrong state = fix its address in odb. |
| `api/sources.php` | "How do you know us?" chip list (`{status, sources:[{value,label}]}`), same auth. Reached via `/api/mamabeSources.php`. Active-only (`MamabeRecords::sources($conn)`) - admin manages the full list (add/rename/reorder/hide) on `registration/settings.php`, backed by the self-migrating `mamabe_source` table. |
| `/api/mamabeRegister.php`, `/api/mamabeOutlets.php`, `/api/mamabeSources.php` (in odb's `api/` folder, outside this module) | Two-line entry points that include `api/register.php` / `api/outlets.php` / `api/sources.php`. |
| `common/api_auth.php` | `mamabe_api_user_ok()`: `api_user` username + SHA-256 password, and the username must be in `MAMABE_API_USERS`. |
| `common/lib/MamabeRecords.php` | Input normalising, the language / source / register-type lists, the outlet list (`outlets`), **the save into `mamabe_mama` / `mamabe_baby`** (`save`) and the NAS copy (`syncPendingToNas`). |
| `common/lib/MamabeVerifier.php` | Status constants, **the AI prompt** (`readNamesFromImage`), name matching (`namesMatch` / `decide`), queue claim/process. |
| `worker.php` | Copies new submissions' images to the NAS first, then checks queued rows **sequentially** (the AI server handles one image at a time). Loopback + token only, or CLI. |
| `common/config.php` | Allowed API users, public form URL (admin menu link), limits, worker URL/token, path to the MamaBe module's `nas_config.php`, `mamabe_db()` (uses the deployment's own `common/index_adv.php`, no duplicated credentials). |
| `index.php`, `ajax_list.php`, `ajax_reread.php`, `file.php`, `header/footer/navbar.php` | Admin list (AJAX + pagination) and image viewer. Six columns that fit the screen: submitted, mama (name ✓/✗, IC, phone, address, language, how she knows us, outlet to redeem, *New / Existing mama* → link to the MamaBe mama page), pregnancy/baby (registered with, EDD or baby name ✓/✗, MyKid, DOB), AI check (result, note, what the AI read), document (pink book / baby health book thumbnail — click to enlarge — and NAS status). Access = `staff.mamab` 1 (Admin) or 3 (Super Admin), same as the rest of MamaBe. |
| `sql/mamabe_submission.sql` | Submissions table. Run once per deployment. |
| `sql/mamabe_submission_register_types.sql` | Upgrade for a table created from the older file (register type, source, preferred outlet; pink book optional). |
| `uploads/` | Local image copies the AI reads. Blocked from HTTP (`.htaccess`); served only through `file.php`. The permanent copies are on the NAS. |

Uses the shared AI helper at `odb/common/ai_vision/` (see its README) and the MamaBe module's `odb/mamabe/nas_config.php` +
`odb/mamabe/lib/synologynas.php` for the NAS — don't copy that code here.

## How the check works
- **Pink book → mother's name.** The prompt tells the AI that on a pregnancy record (the pink *Rekod Kesihatan Ibu*) the book's owner *is*
  the mother and her name may just be labelled *Nama* / *Nama Pesakit*. **Baby health book → baby's name, and the mother's name too**
  (there is no pink book on that path). If a new document type trips it up, add its label wording to the prompt in `readNamesFromImage()`.
- The names typed on the form are **not** sent to the AI, so it can't be nudged into agreeing.
- **Match rule** (`MamabeVerifier::namesMatch`): same set of words, ignoring case, spacing, punctuation, word order and repeated words.
- **Proceed only if every checked name matches.** Anything else — mismatch, name not found, AI unreachable — is **Pending**, with the reason
  in the Note column. Pending means "a human should look", never "rejected" — the MamaBe records are already saved either way.
- **Re-read** (`MamabeVerifier::MAX_READS` = 2): the AI answers differently run to run on the same photo (a handwritten name one
  letter off, boxed "11/04/2026" read as "1/04/2026"). A read that isn't Proceed — or a failed AI call — is read once more and the
  read with the fewest problems is kept. A clean first read costs nothing extra; a real Pending (e.g. a past EDD) costs one more read.
  Every read is a row in `mamabe_ai_log`.
- Statuses (`MamabeVerifier::STATUS_*`): 0 queued, 1 checking, 2 proceed, 3 pending.
- **NAS copy** happens **first** — the worker copies a new submission's photo before the AI check, so it shows on the MamaBe mama page
  within seconds (the AI can take minutes). Every row is linked, whatever the check decides. It only fills `mamabe_baby.pink_book` / `baby_health_book` when
  they are still empty (never replaces a file staff attached), and then ticks `benefit_mama_book` / `benefit_baby_book` (+ `_at`) —
  the MamaBe page does the same when a book is uploaded (those checkboxes can't be ticked by hand). A failure is shown in the admin list and retried on the next worker run.

## Deploying to production (checklist)

**1. Copy** this folder to `odb/mamabe/registration/` — a **subfolder** of the MamaBe module. Never merge its files straight into
`odb/mamabe/`: that would overwrite the module's `index.php`, and this folder's `uploads/.htaccess` would block the module's legacy
`uploads/pink_book/` images. Also copy `api/mamabeRegister.php` into odb's `api/` folder. Don't copy the contents of `uploads/` (local test
images) or `uploads/.worker.lock`.
- `common/ai_vision/` (`ai_vision.php`, `README.md`).
- **Do copy the dot-files** `uploads/.htaccess`, `sql/.htaccess`, `api/.htaccess` and `uploads/.gitignore` — some copy tools/zips skip hidden files, and without
  `uploads/.htaccess` the stored images are downloadable.

**2. Database** — run `sql/mamabe_submission.sql` on the production `odb` database (InnoDB, utf8mb4); if the table is already there
from the older version, run `sql/mamabe_submission_register_types.sql` instead. Also deploy `/api/mamabeOutlets.php`. The DB user in the server's
`common/index_adv.php` needs SELECT / INSERT / UPDATE on `mamabe_submission`, `mamabe_mama` and `mamabe_baby` (plus CREATE for this one run).
No credentials live here.

**3. Config** — edit `common/config.php` on the server:
- `MAMABE_WORKER_TOKEN` → generate a **new** random value (the local one is only for dev).
- `MAMABE_WORKER_URL` → must reach `worker.php` **from the server itself, over plain HTTP**. If Apache is not on port 80, uses a different
  vhost/folder name, or redirects http → https, the ping silently fails (cURL does not follow redirects) and rows stay *Queued*.
- `MAMABE_NAS_CONFIG` / `MAMABE_MAMA_DETAIL_URL` → point at the MamaBe module (defaults assume `odb/mamabe/`).
- `/api/mamabeRegister.php` includes `../odb/mamabe/registration/api/register.php` — adjust that path if odb's `api/` and `odb/` folders aren't siblings.
- Limits (`MAMABE_RATE_MAX`, `MAMABE_QUEUE_CAP`, `MAMABE_MAX_UPLOAD_BYTES`) — review.

**4. Server requirements**
- PHP 8 with extensions **gd (JPEG + PNG), exif, curl, mbstring, mysqli (mysqlnd)**; `upload_max_filesize` ≥ 6M and `post_max_size` ≥ 12M
  (two photos).
- `uploads/` writable by the web-server user; Apache `AllowOverride All` (so the `.htaccess` files are honoured).
- The server must reach the **AI server** (`AI_VISION_URL` in `common/ai_vision/ai_vision.php`, currently `http://172.18.28.29/…`, LAN/VPN
  only — test: `curl -I http://172.18.28.29/read_img_api.php`) and the **NAS** (`CORP_NAS_IP` in `mamabe/nas_config.php`).
- No CDNs: both the form and the admin pages use odb's own `common/css/*`.

**5. Exposure** — nothing in this module is public any more. The public form + proxy run on the education server; odb only needs
`/api/mamabeRegister.php` reachable **from that server** (restrict it to the education server's IP if you can). Admin list, `file.php`,
`worker.php` need a login or are loopback-only; `api/`, `sql/`, `uploads/` are blocked by `.htaccess`.
Add the `mamabe_api` user to `api_user` (password stored as SHA-256 — same as the other API users) and give its password to whoever sets up
the education server's `api/.env`. Set `MAMABE_PUBLIC_FORM_URL` to the form's public URL for the admin menu link.

**6. Safety net** — schedule `php <path>/odb/mamabe/registration/worker.php` every few minutes (cron, or Windows Task Scheduler with `php.exe`). It picks
up anything the ping missed, retries failed NAS copies and resets rows stuck in *Checking* after a crash.

**7. Smoke test on the server**
1. `http://<server>/odb/mamabe/registration/uploads/x.jpg` and `.../sql/mamabe_submission.sql` → **403**.
2. Open the public form (education server), submit a test registration → "Submitted successfully"; a new row appears in `mamabe_mama` / `mamabe_baby`, and **no row in `customer`**.
3. As Admin/Super Admin open `mamabe/registration/index.php` (or MamaBe → Admin Settings → Website Registrations): the row goes Queued → Proceed/Pending within about a minute, then shows
   "Saved to MamaBe record"; the *New mama* tag opens the mama's page, which shows the pink book.
4. Submit again with the same phone + EDD → no second mama or pregnancy row (the admin list shows *Existing mama*).
5. A non-admin staff account gets no access / is redirected; a logged-out visitor is sent to login.
6. Submit 6 times quickly → the 6th is refused (rate limit).
7. Delete your test rows (`mamabe_submission`, `mamabe_mama`, `mamabe_baby`), local images and NAS files afterwards.

**8. Decide before go-live**
- **Open sign-up writes straight into `mamabe_mama` / `mamabe_baby`.** Spam or a wrong IC / phone number lands there too (flagged *Pending*
  when the books don't match). Someone who knows a mama's IC or phone number can attach a pregnancy/baby to her record — her own details
  are never changed, but staff should check *Pending* rows. New mamas get the outlet they picked as `outlet_id`, so that
  outlet's staff see them in the MamaBe Mama List (as well as MamaBe Admins).
- **Match rule** (same word set, order/case/repeats ignored) — one function (`MamabeVerifier::namesMatch`).
- **Retention:** uploaded pink/baby books are personal data kept indefinitely — set a rule for how long, and who may see them.

## Limits worth knowing
- **Handwriting is read less reliably than print** and varies run to run. Expect some false Pendings on handwritten books.
- **AI speed varies** (3 s – 160 s per image; two images when a baby book is given). Submitters are unaffected; results appear in the admin
  list when the worker gets to them (Refresh button).
- **Rate limit** 5 submissions / IP / 10 min, and 100 waiting rows max (`common/config.php`).
- **Ask AI to re-read** (button under each finished result in the admin list, `ajax_reread.php`, admin + CSRF): puts that one row back
  in the queue and pings the worker; the AI reads the document again and replaces the result. The list refreshes itself every 10 s
  while anything is queued / checking. The MamaBe records and NAS copy are not touched. Use it when the AI misread, or when it
  failed because the AI server was down.
- **Existing mama's details are never updated** from the form (name, phone, language stay as staff recorded them); what she typed is kept on
  the `mamabe_submission` row for staff to compare.
- More form fields: add the field to the public form + its proxy's forwarded list (`www/mamabe_api/`), a column to `mamabe_submission`,
  validation in `api/register.php`, and the write in `MamabeRecords::save`.
