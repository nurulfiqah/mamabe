<?php
/**
 * Mamabe shared config + standalone DB connection (used by the public API, the
 * worker and the admin endpoints alike).
 */

// ── Storage ───────────────────────────────────────────────────────────────────
define('MAMABE_UPLOAD_DIR',        dirname(__FILE__) . '/../uploads/');   // blocked from HTTP by uploads/.htaccess; served via file.php
define('MAMABE_MAX_UPLOAD_BYTES',  5 * 1024 * 1024);                      // per attachment
define('MAMABE_MAX_PIXELS',        25000000);                             // reject decompression bombs (25 megapixels)
define('MAMABE_STORE_MAX_PX',      1600);                                 // stored copy: longest side (also what admins enlarge)

// ── MamaBe records + NAS ─────────────────────────────────────────────────────
// Submissions are saved into the MamaBe module's own tables (mamabe_mama / mamabe_baby) and their images copied
// to the same NAS folders it uses (pink_book/, baby_health_book/), via that module's nas_config.php.
define('MAMABE_NAS_CONFIG',        dirname(__FILE__) . '/../../nas_config.php');
define('MAMABE_REGISTERED_BY',     'MamaBe website form');                // mamabe_mama.registered_by / mamabe_baby.*_filled_by for these rows
define('MAMABE_MAMA_DETAIL_URL',  '/odb/mamabe/mamabe_mama_detail.php?id=');  // admin list links each row to the MamaBe module's mama page

// Fixed production domain for absolute URLs built into emails (logo, redeem guide image). Deliberately NOT built
// from $_SERVER['HTTP_HOST']: most approvals happen through worker.php (background, triggered by a fire-and-forget
// ping or run from the CLI), where there is no real Host header - mamabe_db() above then forces HTTP_HOST to
// 'localhost', which an email recipient's browser obviously can't load. Same reasoning as MAMABE_PUBLIC_FORM_URL.
define('MAMABE_PUBLIC_HOST',       'octopusdb.info');

// ── AI verification ───────────────────────────────────────────────────────────
define('MAMABE_AI_MAX_PX',         1280);                                 // longest side sent to the AI - text needs more than the 200 px optiframe uses

// ── ODB API (the public form's proxy on the education server calls /api/mamabeRegister.php) ─
define('MAMABE_API_USERS',         array('mamabe_api'));                  // api_user usernames allowed to register mamas
define('MAMABE_PUBLIC_FORM_URL',   'https://edu.alpropharmacy.com/mamabe_api/form.php');   // the public form on the education server (admin menu link; '' = hide)

// ── Abuse limits per visitor IP (the proxy forwards it; every upload costs an AI call) ─
define('MAMABE_RATE_MAX',          5);                                    // submissions per IP ...
define('MAMABE_RATE_MINUTES',      10);                                   // ... per this many minutes
define('MAMABE_QUEUE_CAP',         100);                                  // refuse new submissions while this many are waiting

// ── Worker trigger ────────────────────────────────────────────────────────────
// api/register.php pings this URL (fire-and-forget) after storing a submission. Fixed on purpose: never
// build it from the Host header. Change if Apache is not on port 80 or the folder is renamed.
define('MAMABE_WORKER_URL',        'http://127.0.0.1/odb/mamabe/registration/worker.php');
// Worker token comes from mamabe/.env (gitignored) - this file is tracked in git, so it must never contain the
// real value itself. The fallback below is a deliberately non-functional placeholder (never ''), so a checkout
// with no .env fails the token check cleanly instead of either leaking the real token or accepting an empty one
// (hash_equals('', '') is true, so an empty default would be a bypass, not just "not configured").
// worker.php also only accepts loopback callers (or CLI) regardless of the token.
$_mamabeTokenEnv = (is_file(dirname(__FILE__) . '/../../.env') && is_readable(dirname(__FILE__) . '/../../.env'))
    ? parse_ini_file(dirname(__FILE__) . '/../../.env', false, INI_SCANNER_RAW)
    : array();
define('MAMABE_WORKER_TOKEN',      $_mamabeTokenEnv['MAMABE_WORKER_TOKEN'] ?? 'CHANGE_ME_SET_IN_ENV');

date_default_timezone_set('Asia/Kuala_Lumpur');

/**
 * Open the odb database using the deployment's own settings in common/index_adv.php
 * (so no credentials are duplicated here). Returns a mysqli set to utf8mb4.
 */
function mamabe_db() {
    if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';   // index_adv.php builds a URL from it
    $connect = 1;
    ob_start();
    include dirname(__FILE__) . '/../../../common/index_adv.php';                 // defines $conn (mysqli_report already OFF)
    ob_end_clean();
    $conn->set_charset('utf8mb4');
    return $conn;
}
