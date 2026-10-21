<?php
/**
 * ODB API - MamaBe website registration, called server-to-server by the public MamaBe form's proxy
 * (the form + its api/submit.php live on the education server; everything is stored here in odb).
 * Reached through /api/mamabeRegister.php (odb's API folder); this folder itself is blocked from HTTP.
 *
 * Auth: username + password of an odb `api_user` (password stored as SHA-256), and the user must be in
 * MAMABE_API_USERS - same scheme as the other /api endpoints.
 *
 * POST multipart/form-data:
 *   username, password                                  - api_user credentials (required)
 *   client_ip                                           - the visitor's IP as seen by the proxy (rate limit)
 *   register_type                                       - 'pink' (pink book), 'baby' (baby health book), or 'both'
 *   source (outlet/insurance/social_media), mother_name,
 *   ic, phone, email, address, language (1-4), outlet_id - all types (required; outlet from MamabeRecords::outlets;
 *                                                          email is where the approve/reject voucher notification goes)
 *   'pink' / 'both': edd (Y-m-d), pink_book (JPG/PNG)            - required
 *   'baby' / 'both': child_name, baby_dob (Y-m-d), baby_mykid,
 *                    baby_book (JPG/PNG)                         - required
 *   has_twin ('baby' / 'both' only) - '1' adds a second baby to this same submission: child_name_2, baby_dob_2
 *     (must equal baby_dob - a second 'baby' part with the same DOB and a different MyKid is exactly what
 *     MamabeRecords::findTwinSibling/insertTwin already auto-pairs as a twin), baby_mykid_2, baby_book_2 (JPG/PNG).
 * Response JSON: {"status":"success","message":"Submitted successfully","baby_only":bool}  |  {"status":"error","message":"..."}
 *   baby_only is true when a 'both' request was downgraded (see below) - the proxy/form use it to tell her only
 *   the baby part was actually registered.
 *   Error messages are written for the visitor (the proxy shows them as-is); nothing reveals whether the mama existed.
 *
 * Saves into mamabe_mama (matched by mobile number, created if new - never odb `customer`) and mamabe_baby (see
 * MamabeRecords; a baby health book registration uses the baby's date of birth as the EDD), keeps local image copies
 * for the AI check and queues it for worker.php. The preferred outlet also becomes the mama's outlet_id (when still 0).
 * 'both' is saved as two independent mamabe_baby rows (a pregnancy + a baby) sharing one mama, and two
 * mamabe_submission rows ('pink' then 'baby'); a twin adds a third 'baby' row/submission - the rest of the pipeline
 * (admin list, AI check, NAS sync) never needs to know about 'both' or twins, it only ever sees single-type rows.
 */
error_reporting(0);
ini_set('memory_limit', '512M');   // GD decoding of large photos
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(__FILE__) . '/../common/config.php';
require_once dirname(__FILE__) . '/../common/api_auth.php';
require_once dirname(__FILE__) . '/../common/worker_kick.php';
require_once dirname(__FILE__) . '/../common/lib/MamabeVerifier.php';
require_once dirname(__FILE__) . '/../common/lib/MamabeRecords.php';

function mamabe_reply($code, $status, $text, array $extra = array()) {
    http_response_code($code);
    echo json_encode(array('status' => $status, 'message' => $text) + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function mamabe_fail($code, $msg) { mamabe_reply($code, 'error', $msg); }
function mamabe_post($key) { return isset($_POST[$key]) ? (string)$_POST[$key] : ''; }

/**
 * Cheap checks on one uploaded image. Returns getimagesize() info, null when optional and absent; fails otherwise.
 * Judged by content, never by the client-supplied name/type.
 */
function mamabe_check_upload($key, $label, $required) {
    $f = isset($_FILES[$key]) ? $_FILES[$key] : null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) mamabe_fail(422, "Please add a photo of the $label.");
        return null;
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) mamabe_fail(413, "The $label photo is too large (max 5 MB).");
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) mamabe_fail(400, 'The upload failed. Please try again.');
    if ($f['size'] < 1 || $f['size'] > MAMABE_MAX_UPLOAD_BYTES) mamabe_fail(413, "The $label photo is too large (max 5 MB).");
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG), true)) mamabe_fail(415, "The $label must be a JPG or PNG photo.");
    if ($info[0] * $info[1] > MAMABE_MAX_PIXELS) mamabe_fail(413, "The $label photo's dimensions are too large.");
    return $info;
}

/**
 * Decode and re-save as a fresh JPEG: strips anything hidden in the upload (scripts, polyglots, metadata), applies the
 * EXIF rotation phones record, and caps the size. The stored copy gets a random name. Returns the name or fails.
 */
function mamabe_store_image($key, array $info) {
    $tmp = $_FILES[$key]['tmp_name'];
    $img = @imagecreatefromstring(file_get_contents($tmp));
    if (!$img) mamabe_fail(415, 'An image could not be read.');

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif   = @exif_read_data($tmp);
        $orient = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        $angles = array(3 => 180, 6 => -90, 8 => 90);
        if (isset($angles[$orient])) {
            $rot = imagerotate($img, $angles[$orient], 0);
            if ($rot) { imagedestroy($img); $img = $rot; }
        }
    }

    $w     = imagesx($img);
    $h     = imagesy($img);
    $scale = min(1, MAMABE_STORE_MAX_PX / max($w, $h));
    $nw    = max(1, (int)round($w * $scale));
    $nh    = max(1, (int)round($h * $scale));
    $out   = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // PNG transparency -> white
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    $name  = bin2hex(random_bytes(16)) . '.jpg';
    $saved = imagejpeg($out, MAMABE_UPLOAD_DIR . $name, 88);
    imagedestroy($out);
    if (!$saved) {
        error_log('mamabe submit: could not write ' . MAMABE_UPLOAD_DIR . $name);
        mamabe_fail(500, 'Could not save your submission. Please try again.');
    }
    return $name;
}

function mamabe_cleanup_files(array $files) {
    foreach ($files as $f) if ($f) @unlink(MAMABE_UPLOAD_DIR . $f);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mamabe_fail(405, 'Method not allowed.');
}

// ── API user (same scheme as the other /api endpoints: api_user, SHA-256 password) ──
$conn = mamabe_db();
if (!mamabe_api_user_ok($conn, mamabe_post('username'), mamabe_post('password'))) {
    mamabe_fail(401, 'Invalid User');
}

// Self-migrating column - whether THIS submission created the mamabe_baby row it points to (true) or only matched
// an already-existing pregnancy/baby and filled empty fields on it (false). Needed so ajax_reject.php never hides
// a real, pre-existing baby record just because one bogus submission happened to match onto it.
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'baby_is_new'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission ADD COLUMN baby_is_new TINYINT(1) NOT NULL DEFAULT 0 AFTER mama_is_new");
}
// Self-migrating column - the email as typed (mamabe_mama.email is the canonical one, same fill-once rule as
// IC/address/outlet; this is just the as-typed snapshot other "as typed" fields on this table already keep).
$_c = $conn->query("SHOW COLUMNS FROM mamabe_submission LIKE 'email'");
if ($_c && $_c->num_rows === 0) {
    $conn->query("ALTER TABLE mamabe_submission ADD COLUMN email VARCHAR(100) NOT NULL DEFAULT '' AFTER phone");
}

// ── Common: how she knows us, mama, preferred outlet ──────────────────────────
$type = mamabe_post('register_type');
if (!isset(MamabeRecords::registerTypes()[$type])) mamabe_fail(422, 'Please choose how you want to register (pink book, baby health book, or both).');
$source = mamabe_post('source');
if (!isset(MamabeRecords::sources($conn)[$source])) mamabe_fail(422, 'Please tell us how you know about us.');

$mother   = MamabeRecords::cleanText(mamabe_post('mother_name'));
$ic       = MamabeRecords::normalizeIc(mamabe_post('ic'));
$phone    = MamabeRecords::normalizePhone(mamabe_post('phone'));
$email    = trim(mamabe_post('email'));
$address  = MamabeRecords::cleanAddress(mamabe_post('address'));
$language = (int)mamabe_post('language');
$outletId = (int)mamabe_post('outlet_id');
if ($mother === '' || mb_strlen($mother, 'UTF-8') > 100) mamabe_fail(422, "Please enter the mother's name as in her IC (up to 100 characters).");
if ($ic === '')    mamabe_fail(422, 'Please enter a valid IC number (12 digits) or passport number.');
if ($phone === '') mamabe_fail(422, 'Please enter a valid mobile number, e.g. 0123456789.');
if ($email === '' || mb_strlen($email, 'UTF-8') > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) mamabe_fail(422, 'Please enter a valid email address - this is where your voucher will be sent once verified.');
if (mb_strlen($address, 'UTF-8') > 500) mamabe_fail(422, 'The address is too long (up to 500 characters).');   // optional - no minimum length
if (!isset(MamabeRecords::languages()[$language])) mamabe_fail(422, 'Please choose a preferred language.');
// 'confinement_center' picks from MamabeRecords::avidaOutlets() instead (the form swaps the dropdown over to it -
// see form.php) - everyone else still picks a normal Alpro Pharmacy/Baby outlet.
$validOutlets = $source === 'confinement_center' ? MamabeRecords::avidaOutlets($conn) : MamabeRecords::outlets($conn);
if (!isset($validOutlets[$outletId])) mamabe_fail(422, 'Please choose your preferred outlet to redeem.');

// A 'both' registration from a mama ALREADY in the system (matched by IC/phone, same rule as MamabeRecords::save())
// is downgraded to 'baby' only - she doesn't need a fresh pregnancy/pink-book registration, only the new baby does.
// A genuinely new mama doing 'both' is the normal case (pregnant AND already has an older baby to register in the
// same visit) and is unaffected. The pink_book photo, if one was sent, is simply never looked at or stored.
$mamaWasExisting      = MamabeRecords::mamaExists($conn, $ic, $phone);
$downgradedToBabyOnly = $type === 'both' && $mamaWasExisting;
if ($downgradedToBabyOnly) {
    $type = 'baby';
}
$wantsPink = $type === 'pink' || $type === 'both';
$wantsBaby = $type === 'baby' || $type === 'both';

// ── Pink book: EDD (up to ~10 months ahead; up to 6 years back, as before) ────
// ── Baby health book: baby name, date of birth, MyKid; the DOB is saved as the EDD ──
// 'both' requires and validates both sets - two separate events (a current pregnancy and an already-born baby).
$edd = null; $child = ''; $mykid = ''; $dob = null;
if ($wantsPink) {
    $edd = MamabeRecords::parseDate(mamabe_post('edd'), date('Y-m-d', strtotime('-6 years')), date('Y-m-d', strtotime('+10 months')));
    if ($edd === null) mamabe_fail(422, 'Please enter a valid expected delivery date (EDD).');
}
if ($wantsBaby) {
    $child = MamabeRecords::cleanText(mamabe_post('child_name'));
    $mykid = preg_replace('/\D/', '', mamabe_post('baby_mykid'));
    $dob   = MamabeRecords::parseDate(mamabe_post('baby_dob'), date('Y-m-d', strtotime('-6 years')), date('Y-m-d'));
    if ($child === '' || mb_strlen($child, 'UTF-8') > 100) mamabe_fail(422, "Please enter the baby's name as in the MyKid / birth certificate (up to 100 characters).");
    if ($dob === null) mamabe_fail(422, "Please enter the baby's date of birth (not in the future).");
    if (!MamabeRecords::validMyKad($mykid)) mamabe_fail(422, "Please enter a valid baby IC / MyKid number (12 digits).");
    if (!$wantsPink) $edd = $dob;   // 'baby' only: no real EDD, so the baby's mamabe_baby row uses its DOB as EDD (as before)
}

// ── Twin: a second baby on this same submission - only possible alongside a baby health book registration ────
$wantsTwin = $wantsBaby && mamabe_post('has_twin') === '1';
$child2 = ''; $mykid2 = ''; $dob2 = null;
if ($wantsTwin) {
    $child2 = MamabeRecords::cleanText(mamabe_post('child_name_2'));
    $mykid2 = preg_replace('/\D/', '', mamabe_post('baby_mykid_2'));
    $dob2   = MamabeRecords::parseDate(mamabe_post('baby_dob_2'), date('Y-m-d', strtotime('-6 years')), date('Y-m-d'));
    if ($child2 === '' || mb_strlen($child2, 'UTF-8') > 100) mamabe_fail(422, "Please enter the twin's name as in the MyKid / birth certificate (up to 100 characters).");
    if ($dob2 === null) mamabe_fail(422, "Please enter the twin's date of birth (not in the future).");
    if (!MamabeRecords::validMyKad($mykid2)) mamabe_fail(422, "Please enter a valid twin IC / MyKid number (12 digits).");
    if ($mykid2 === $mykid) mamabe_fail(422, "The twin's MyKid number must be different from the first baby's.");
    if ($dob2 !== $dob) mamabe_fail(422, "The twin's date of birth must be the same as the first baby's.");
}

// ── Images: cheap checks first ────────────────────────────────────────────────
$pinkInfo = $wantsPink ? mamabe_check_upload('pink_book', 'pink book', true) : null;
$babyInfo = $wantsBaby ? mamabe_check_upload('baby_book', 'baby health book', true) : null;
$twinInfo = $wantsTwin ? mamabe_check_upload('baby_book_2', "twin's baby health book", true) : null;

// ── Abuse limits (before any heavy image work) ────────────────────────────────
// Every call comes from the proxy's server, so rate-limit on the visitor IP it forwards (trusted: authenticated caller).
$ip = filter_var(mamabe_post('client_ip'), FILTER_VALIDATE_IP) ?: (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
$ip = substr($ip, 0, 45);

$since = date('Y-m-d H:i:s', time() - MAMABE_RATE_MINUTES * 60);
$stmt  = $conn->prepare("SELECT COUNT(*) FROM mamabe_submission WHERE ip_address = ? AND created_at >= ?");
$stmt->bind_param('ss', $ip, $since);
$stmt->execute();
$stmt->bind_result($recent);
$stmt->fetch();
$stmt->close();
if ($recent >= MAMABE_RATE_MAX) {
    mamabe_fail(429, 'Too many submissions. Please try again in a few minutes.');
}

$res = $conn->query("SELECT COUNT(*) FROM mamabe_submission WHERE status IN ("
     . MamabeVerifier::STATUS_QUEUED . "," . MamabeVerifier::STATUS_CHECKING . ")");
$waiting = $res ? (int)$res->fetch_row()[0] : 0;
if ($waiting >= MAMABE_QUEUE_CAP) {
    mamabe_fail(503, 'The service is busy. Please try again later.');
}

// ── Store the images ──────────────────────────────────────────────────────────
$pinkFile = $pinkInfo ? mamabe_store_image('pink_book', $pinkInfo) : null;
$babyFile = $babyInfo ? mamabe_store_image('baby_book', $babyInfo) : null;
$twinFile = $twinInfo ? mamabe_store_image('baby_book_2', $twinInfo) : null;

// ── Save into mamabe_mama / mamabe_baby + the submission, all or nothing ──────
// 'both' is two independent registrations sharing one mama: a pregnancy row (pink book) and a baby row (baby health
// book), saved and recorded exactly as two separate 'pink' / 'baby' submissions would be - the rest of the pipeline
// (admin list, AI check, NAS sync) only ever sees single-type rows, so it needs no changes for 'both'.
$parts = array();
if ($wantsPink) {
    $parts[] = array('type' => 'pink', 'edd' => $edd, 'child' => '', 'mykid' => '', 'dob' => null, 'pinkFile' => $pinkFile, 'babyFile' => null);
}
if ($wantsBaby) {
    $parts[] = array('type' => 'baby', 'edd' => $dob, 'child' => $child, 'mykid' => $mykid, 'dob' => $dob, 'pinkFile' => null, 'babyFile' => $babyFile);
}
if ($wantsTwin) {
    // Saved as a third 'baby' part, after the first baby - MamabeRecords::save() sees the first baby's row already
    // inserted (same transaction, same connection) and auto-pairs this one as its twin (same DOB, different MyKid).
    $parts[] = array('type' => 'baby', 'edd' => $dob2, 'child' => $child2, 'mykid' => $mykid2, 'dob' => $dob2, 'pinkFile' => null, 'babyFile' => $twinFile);
}

$now    = date('Y-m-d H:i:s');
$status = MamabeVerifier::STATUS_QUEUED;
$conn->begin_transaction();
try {
    // 'both' inserts two rows in this one request - link them via batch_id so the admin list can group and show
    // them as one registration event. batch_id = the first row's own id; a single-part request never gets one
    // (NULL = standalone, nothing to group).
    $batchId = null;
    foreach ($parts as $p) {
        $data = array(
            'mother_name' => $mother, 'ic' => $ic, 'phone' => $phone, 'email' => $email, 'address' => $address, 'language' => $language,
            'outlet_id' => $outletId, 'edd' => $p['edd'],
            'child_name' => $p['child'], 'baby_mykid' => $p['mykid'], 'baby_dob' => $p['dob'], 'baby_gender' => '',
        );
        // The second part's mama already exists (matched by IC/phone) - both parts end up on the same mama_id.
        $saved     = MamabeRecords::save($conn, $data);
        $isNew     = $saved['mama_is_new'] ? 1 : 0;
        $babyIsNew = $saved['baby_is_new'] ? 1 : 0;
        $stmt   = $conn->prepare(
            "INSERT INTO mamabe_submission (mama_id, baby_id, mama_is_new, baby_is_new, batch_id, register_type, source, preferred_outlet_id,
                                            mother_name, ic, phone, email, address, language, edd,
                                            child_name, baby_mykid, baby_dob, baby_gender, pink_file, baby_file,
                                            status, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiiiississsssissssssiss', $saved['mama_id'], $saved['baby_id'], $isNew, $babyIsNew, $batchId, $p['type'], $source, $outletId,
                          $mother, $ic, $phone, $email, $address, $language, $p['edd'], $p['child'], $p['mykid'], $p['dob'],
                          $p['pinkFile'], $p['babyFile'], $status, $ip, $now);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_submission insert: ' . $stmt->error);
        $newId = $stmt->insert_id;
        $stmt->close();

        if (count($parts) > 1 && $batchId === null) {
            $batchId = $newId;
            $stmt = $conn->prepare("UPDATE mamabe_submission SET batch_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $batchId, $newId);
            if (!$stmt->execute()) throw new RuntimeException('mamabe_submission batch update: ' . $stmt->error);
            $stmt->close();
        }
    }
    $conn->commit();
} catch (Throwable $e) {   // also a failed prepare() (Error), not just our RuntimeExceptions
    $conn->rollback();
    error_log('mamabe submit: ' . $e->getMessage());
    mamabe_cleanup_files(array($pinkFile, $babyFile, $twinFile));
    mamabe_fail(500, 'Could not save your submission. Please try again.');
}
$conn->close();

// The caller learns nothing about the check or whether the mama was already registered. If this ping fails the
// row simply stays queued and is picked up by the next submission or a scheduled run of worker.php.
mamabe_kick_worker();

mamabe_reply(200, 'success', 'Submitted successfully', array('baby_only' => $downgradedToBabyOnly, 'existing_mama' => $mamaWasExisting));
