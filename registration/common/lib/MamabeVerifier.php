<?php
/**
 * Mamabe vision verification. Each mamabe_submission row is already one check unit (see api/register.php: a 'both'
 * registration is stored as two rows, a 'pink' one and a 'baby' one - never a single row mixing both), so unlike a
 * design that splits mother/baby into separate tables, this queue only ever needs ONE table:
 *   register_type = 'pink' -> the mother's own check: mother_name must match the name read from pink_file, and the
 *                              EDD read from the document must be today or later.
 *   register_type = 'baby' -> the baby's check: child_name must match the name read from baby_file, OR the mother's
 *                              name as read off that SAME document must match mother_name (fallback - e.g. a birth
 *                              cert's "Nama Ibu" field); AND the date of birth read from it must make the baby
 *                              younger than the admin-set age limit (mamabe_setting.baby_max_age_months).
 *
 * Statuses are workflow codes the code branches on, so they live here as constants (see sql/mamabe_submission.sql) -
 * not in a lookup table.
 */
require_once dirname(__FILE__) . '/../../../../common/ai_vision/ai_vision.php';

class MamabeVerifier {

    const STATUS_QUEUED   = 0;
    const STATUS_CHECKING = 1;
    const STATUS_PROCEED  = 2;
    const STATUS_PENDING  = 3;

    const DEFAULT_BABY_MAX_AGE_MONTHS = 24;   // used when mamabe_setting has no row for it

    /** Baby age limit in months - admin-editable (Settings tab, mamabe_setting.baby_max_age_months); see loadSettings(). */
    private static $babyMaxAgeMonths = self::DEFAULT_BABY_MAX_AGE_MONTHS;

    /**
     * Read the admin-editable rule settings from mamabe_setting. Call once per request after connecting (and the
     * worker calls it before every unit, so a change on the Settings tab applies to the next check without a restart).
     */
    public static function loadSettings($conn) {
        $res = $conn->query("SELECT setting_value FROM mamabe_setting WHERE setting_key = 'baby_max_age_months'");
        $row = $res ? $res->fetch_row() : null;
        $n = $row ? (int)$row[0] : 0;
        self::$babyMaxAgeMonths = $n >= 1 ? $n : self::DEFAULT_BABY_MAX_AGE_MONTHS;
    }

    public static function babyMaxAgeMonths() {
        return self::$babyMaxAgeMonths;
    }

    /** status code => array(label, pill colour) for the admin list */
    public static function statusMeta() {
        return array(
            self::STATUS_QUEUED   => array('Queued',   'gray'),
            self::STATUS_CHECKING => array('Checking', 'blue'),
            self::STATUS_PROCEED  => array('Proceed',  'green'),
            self::STATUS_PENDING  => array('Pending',  'yellow'),
        );
    }

    // ── Names ─────────────────────────────────────────────────────────────────

    /**
     * A name as a sorted set of lowercase words: case, spacing, punctuation, word order and repeated words
     * are all ignored. Letters of any script and digits are kept.
     * "Ng Cheng NG CHENG YUEN" -> array('cheng','ng','yuen').
     */
    public static function nameWords($s) {
        $s = mb_strtolower((string)$s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = trim($s);
        if ($s === '') return array();
        $words = array_values(array_unique(explode(' ', $s)));
        sort($words);
        return $words;
    }

    /**
     * True when the name typed on the form and the text read from the image are the same name:
     * - same words, ignoring case, punctuation, order and repeats (the image row often holds handwriting AND a
     *   printed copy of the same name) - but a missing or extra word fails ("Ng Cheng" is not "Ng Cheng Yuen");
     * - the father's-name part is optional for Malay (bin / binti / bt / bte) and Indian (a/l / a/p) names:
     *   "NURFARAH AQILLAH" matches "NURFARAH AQILLAH BINTI JAINUDIN" - only when ONE side leaves it out; when both
     *   sides write a father's name it must match too. Chinese and other names have no marker, so the full name counts;
     * - spacing inside a name does not matter: "NURFARAH" matches "NUR FARAH" (same letters in the same order).
     */
    public static function namesMatch($entered, $read) {
        if (self::nameWords($entered) === array()) return false;
        $e = self::splitFatherName($entered);
        $r = self::splitFatherName($read);
        $pairs = array(array($entered, $read));
        if ($e[1] !== $r[1]) $pairs[] = array($e[0], $r[0]);   // father's name written on one side only: compare own names
        foreach ($pairs as $p) {
            if (self::nameWords($p[0]) === self::nameWords($p[1])) return true;
            $sq = self::squashName($p[0]);
            if ($sq !== '' && $sq === self::squashName($p[1])) return true;
        }
        return false;
    }

    /**
     * array(own name, true) when the name has a father's-name part (Malay bin/binti/bt/bte, Indian a/l or a/p)
     * followed by at least one word - "own name" is everything before that marker. Otherwise array(name, false).
     * A marker needs words on both sides, so a Chinese given name like "Wang Bin" is not split.
     */
    private static function splitFatherName($s) {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        if (preg_match('/(?<=\S)\s+(?:bin|binti|bt|bte|a\s*\/\s*[lp])\.?\s+(?=\S)/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            return array(substr($s, 0, $m[0][1]), true);
        }
        return array($s, false);
    }

    /** Letters and digits only, lowercase, in order - "Nur Farah" and "NURFARAH" both become "nurfarah". */
    private static function squashName($s) {
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string)$s, 'UTF-8'));
    }

    // ── Dates ─────────────────────────────────────────────────────────────────

    /**
     * Parse a date as written on a Malaysian document into 'Y-m-d', or null when it can't be read unambiguously.
     * Numeric dates are DAY first (12/03/2025 = 12 March). Accepts / - . or spaces as separators, 2- or 4-digit years,
     * ISO 2025-03-12, and month names in English or Malay (12 Mac 2025, 5 Ogos 2024, 3rd January 2025).
     */
    public static function parseDocDate($s) {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        if ($s === '') return null;

        $d = $m = $y = null;
        if (preg_match('/\b(\d{4})[\/\-.\s](\d{1,2})[\/\-.\s](\d{1,2})\b/', $s, $x)) {             // 2025-03-12, 2025 03 12
            list(, $y, $m, $d) = $x;
        } elseif (preg_match('/\b(\d{1,2})\s*[\/\-.\s]\s*(\d{1,2})\s*[\/\-.\s]\s*(\d{2}|\d{4})\b/', $s, $x)) {   // 12/03/2025, 12.3.25, 09 04 27
            list(, $d, $m, $y) = $x;
        } elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?[\s\-\/.,]*([a-z]+)[\s\-\/.,]*(\d{2}|\d{4})\b/u', $s, $x)) {   // 12 Mac 2025
            $d = $x[1];
            $m = self::monthFromName($x[2]);
            $y = $x[3];
        } elseif (preg_match('/\b([a-z]+)[\s\-\/.,]*(\d{1,2})(?:st|nd|rd|th)?[\s,]+(\d{4})\b/u', $s, $x)) {   // March 12, 2025
            $m = self::monthFromName($x[1]);
            $d = $x[2];
            $y = $x[3];
        }
        if ($m === null || $d === null || $y === null) return null;

        $d = (int)$d; $m = (int)$m; $y = (int)$y;
        if ($y < 100) $y += 2000;                      // mamabe dates (EDD / a baby's DOB) are all this century
        if (!checkdate($m, $d, $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** Month number from an English or Malay month name / abbreviation, or null. */
    private static function monthFromName($w) {
        static $months = array(
            'jan' => 1, 'januari' => 1, 'january' => 1,
            'feb' => 2, 'februari' => 2, 'february' => 2,
            'mac' => 3, 'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'mei' => 5, 'may' => 5,
            'jun' => 6, 'june' => 6,
            'jul' => 7, 'julai' => 7, 'july' => 7,
            'ogo' => 8, 'ogos' => 8, 'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'okt' => 10, 'oct' => 10, 'oktober' => 10, 'october' => 10,
            'nov' => 11, 'november' => 11,
            'dis' => 12, 'dec' => 12, 'disember' => 12, 'december' => 12,
        );
        return isset($months[$w]) ? $months[$w] : null;
    }

    /** EDD rule: the mother is still expecting, i.e. the EDD is today or later. $iso / $today are 'Y-m-d'. */
    public static function eddOk($iso, $today) {
        return $iso !== null && $iso >= $today;
    }

    /**
     * DOB rule: born on or before today and younger than babyMaxAgeMonths() months - on the day the baby reaches
     * that age it no longer passes (24 months: born 2024-09-27 fails on 2026-09-27, born 2024-09-28 passes).
     */
    public static function dobOk($iso, $today) {
        if ($iso === null || $iso > $today) return false;
        return $iso > self::monthsBefore($today, self::$babyMaxAgeMonths);
    }

    /** 'Y-m-d' that is $months calendar months before $ymd, day clamped to the month's end (2026-08-31 - 6 = 2026-02-28). */
    public static function monthsBefore($ymd, $months) {
        list($y, $m, $d) = array_map('intval', explode('-', $ymd));
        $total = $y * 12 + ($m - 1) - (int)$months;
        $ny = intdiv($total, 12);
        $nm = $total % 12 + 1;
        $nd = min($d, (int)date('t', mktime(0, 0, 0, $nm, 1, $ny)));
        return sprintf('%04d-%02d-%02d', $ny, $nm, $nd);
    }

    /**
     * Date of birth encoded in a Malaysian MyKid number ('YYMMDD-PB-###G', 12 digits, dashes/spaces optional) as
     * 'Y-m-d', or null when it isn't exactly 12 digits or the first 6 aren't a real date. Babies here are all born 20YY.
     */
    public static function mykidDate($s) {
        $d = preg_replace('/\D+/', '', (string)$s);
        if (strlen($d) !== 12) return null;
        $y = 2000 + (int)substr($d, 0, 2);
        $m = (int)substr($d, 2, 2);
        $day = (int)substr($d, 4, 2);
        return checkdate($m, $day, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $day) : null;
    }

    // ── Decision ──────────────────────────────────────────────────────────────

    private static function nameCheck($label, $entered, $read) {
        if ($read === null) return array('label' => $label, 'ok' => null, 'problem' => null);
        if (self::namesMatch($entered, $read)) return array('label' => $label, 'ok' => true, 'problem' => null);
        return array('label' => $label, 'ok' => false, 'problem' => self::nameWords($read) === array()
            ? $label . ' unreadable'
            : $label . ' mismatch');
    }

    private static function dateCheck($label, $read, $today, $rule, $failText) {
        if ($read === null) return array('label' => $label, 'ok' => null, 'problem' => null);
        $iso = self::parseDocDate($read);
        if ($iso === null) {
            return array('label' => $label, 'ok' => false, 'problem' => $label . ' unreadable');
        }
        if (self::$rule($iso, $today)) return array('label' => $label, 'ok' => true, 'problem' => null);
        return array('label' => $label, 'ok' => false, 'problem' => $label . ' ' . $failText);
    }

    /**
     * The mother's own check (register_type = 'pink'). $s needs mother_name, extracted_mother, extracted_edd.
     * Returns field => array('label', 'ok' => true|false|null (null = nothing read yet), 'problem').
     * Used by decideMother() and by the admin list (ticks / crosses), so both always agree.
     */
    public static function motherChecks($s, $today) {
        return array(
            'mother' => self::nameCheck('Mother name', $s['mother_name'], $s['extracted_mother']),
            'edd'    => self::dateCheck('EDD', $s['extracted_edd'], $today, 'eddOk', 'past'),
        );
    }

    /** Proceed only when both the mother's name and EDD checks pass. Returns array('status','note'). */
    public static function decideMother($s, $today) {
        $problems = array();
        foreach (self::motherChecks($s, $today) as $c) {
            if ($c['ok'] !== true) $problems[] = $c['problem'] !== null ? $c['problem'] : $c['label'] . ' not checked';
        }
        if (!$problems) return array('status' => self::STATUS_PROCEED, 'note' => null, 'problems' => 0);
        return array('status' => self::STATUS_PENDING, 'note' => implode(', ', $problems), 'problems' => count($problems));
    }

    /**
     * One baby's check (register_type = 'baby'). $b needs child_name, extracted_child, extracted_dob,
     * extracted_mother, mother_name. Returns 'child' (baby name vs image), 'mother_on_doc' (fallback: mother's name
     * as read off this SAME baby document), 'dob'. 'child'/'mother_on_doc' are alternatives (either passing is
     * enough) - see decideBaby().
     */
    public static function babyChecks($b, $today) {
        $dob = self::dateCheck('DOB', $b['extracted_dob'], $today, 'dobOk', 'over ' . self::$babyMaxAgeMonths . 'm');
        $dob['via_mykid'] = false;

        // MyKid confirmation: a MyKid number starts with the date of birth as YYMMDD. The AI sometimes misreads one
        // digit of the DOB year. If the DOB as read fails but the MyKid date passes and agrees on day + month (or the
        // DOB could not be read at all), the DOB year was misread -> pass via MyKid.
        $mk = isset($b['extracted_mykid']) ? self::mykidDate($b['extracted_mykid']) : null;
        if ($dob['ok'] === false && $mk !== null && self::dobOk($mk, $today)) {
            $read = self::parseDocDate($b['extracted_dob']);
            if ($read === null || substr($read, 5) === substr($mk, 5)) {
                $dob = array('label' => 'DOB', 'ok' => true, 'problem' => null, 'via_mykid' => true);
            }
        }

        return array(
            'child'         => self::nameCheck('Baby name', $b['child_name'], $b['extracted_child']),
            'mother_on_doc' => self::nameCheck('Mother name on doc', $b['mother_name'], $b['extracted_mother']),
            'dob'           => $dob,
        );
    }

    /**
     * Proceed only when (the baby's name matches OR the mother's name on that same document matches) AND
     * the DOB passes. Returns array('status' => STATUS_PROCEED|STATUS_PENDING, 'note' => string|null).
     */
    public static function decideBaby($b, $today) {
        $c = self::babyChecks($b, $today);
        $nameOk = $c['child']['ok'] === true || $c['mother_on_doc']['ok'] === true;

        $problems = array();
        if (!$nameOk) {
            if ($c['child']['ok'] === null && $c['mother_on_doc']['ok'] === null) {
                $problems[] = 'Baby name not checked';
            } else {
                $problems[] = 'Name mismatch';
            }
        }
        if ($c['dob']['ok'] !== true) {
            $problems[] = $c['dob']['problem'] !== null ? $c['dob']['problem'] : $c['dob']['label'] . ' not checked';
        }
        if (!$problems) return array('status' => self::STATUS_PROCEED, 'note' => null, 'problems' => 0);
        return array('status' => self::STATUS_PENDING, 'note' => implode(', ', $problems), 'problems' => count($problems));
    }

    // ── AI reading ────────────────────────────────────────────────────────────

    /**
     * Mother's document (pink book): read the mother's name and the EDD. The names typed on the form are
     * deliberately NOT sent, so the model cannot be nudged into "confirming" them - it must read what is actually
     * written. Returns array('ok' => bool, 'name' => string, 'date' => string, 'error' => string, 'ai_ms' => int).
     */
    public static function readMotherDoc($absPath) {
        // Keep this SHORT. The AI server's proxy ignores num_predict and think:false, so the model reasons for as
        // long as it likes, and a long rule-heavy prompt made it reason for minutes. Add a label here only if a
        // real document needs it.
        $prompt = "Mother's antenatal record (pink book / Rekod Kesihatan Ibu). Read the mother's name (Nama Ibu / Nama Pesakit) "
                . "and the EDD (TAL / TJB / expected delivery date; if there is a RE EDD, use that). "
                . "Copy exactly as written, empty string if missing.\n"
                . "Respond ONLY JSON: {\"name\": \"\", \"date\": \"\"}";
        $r = self::readDoc($absPath, $prompt);
        return array('ok' => $r['ok'], 'name' => $r['name'], 'date' => $r['date'], 'error' => $r['error'], 'ai_ms' => $r['ai_ms']);
    }

    /**
     * Baby's document (baby health book): read the baby's own name, the date of birth, and - if the document also
     * separately labels a mother/parent's name (e.g. "Nama Ibu", after "B/O", or a hospital sticker) - that name
     * too, for the fallback match, plus the baby's MyKid number if shown.
     * Returns array('ok' => bool, 'name' => string, 'date' => string, 'mother' => string, 'mykid' => string,
     * 'error' => string, 'ai_ms' => int).
     */
    public static function readBabyDoc($absPath) {
        // Keep this SHORT - see readMotherDoc() for why (prompt length = minutes of AI reasoning on this server).
        $prompt = "Baby's document (baby health book / birth certificate / child health record). Read the baby's name "
                . "(Nama Bayi / Nama Anak), the baby's date of birth (Tarikh Lahir / DOB), and the mother's name "
                . "(Nama Ibu, or the name after 'B/O', or the patient name on a hospital sticker) if shown, and the "
                . "baby's MyKid number (No. MyKid) if shown. Copy exactly as written, empty string if missing.\n"
                . "Respond ONLY JSON: {\"name\": \"\", \"date\": \"\", \"mother\": \"\", \"mykid\": \"\"}";
        $r = self::readDoc($absPath, $prompt);
        return array('ok' => $r['ok'], 'name' => $r['name'], 'date' => $r['date'],
                     'mother' => isset($r['json']['mother']) ? trim((string)$r['json']['mother']) : '',
                     'mykid'  => isset($r['json']['mykid'])  ? trim((string)$r['json']['mykid'])  : '', 'error' => $r['error'],
                     'ai_ms'  => $r['ai_ms']);
    }

    /**
     * Shared AI call + JSON parse. Returns array('ok','name','date','json' => the full decoded reply,'error',
     * 'ai_ms' => how long the AI call itself took, 0 when it never ran).
     */
    private static function readDoc($absPath, $prompt) {
        $fail = array('ok' => false, 'name' => '', 'date' => '', 'json' => array(), 'error' => '', 'ai_ms' => 0);
        $bin = is_file($absPath) ? file_get_contents($absPath) : false;
        $b64 = ai_image_prep($bin, MAMABE_AI_MAX_PX);
        if ($b64 === false) {
            $fail['error'] = 'Attachment file is missing or empty.';
            return $fail;
        }
        $t0 = microtime(true);
        $r  = ai_vision_call($prompt, array($b64), array('num_predict' => 200, 'timeout' => 300));
        $fail['ai_ms'] = (int)round((microtime(true) - $t0) * 1000);
        if (!$r['success']) {
            $fail['error'] = $r['error'];
            return $fail;
        }
        $j = ai_extract_json($r['text']);
        if ($j === null || (!isset($j['name']) && !isset($j['date']))) {
            $fail['error'] = 'Could not parse the AI reply.';
            return $fail;
        }
        return array(
            'ok'    => true,
            'name'  => isset($j['name']) ? trim((string)$j['name']) : '',
            'date'  => isset($j['date']) ? trim((string)$j['date']) : '',
            'json'  => $j,
            'error' => '',
            'ai_ms' => $fail['ai_ms'],
        );
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    /** Take the oldest queued row and mark it checking. Returns the row, or null when none is left. */
    public static function claimNext($conn) {
        while (true) {
            $res = $conn->query("SELECT id, register_type, mother_name, child_name, pink_file, baby_file
                                  FROM mamabe_submission WHERE status = " . self::STATUS_QUEUED . " ORDER BY id LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            if (!$row) return null;

            $stmt = $conn->prepare("UPDATE mamabe_submission SET status = ? WHERE id = ? AND status = ?");
            $checking = self::STATUS_CHECKING;
            $queued   = self::STATUS_QUEUED;
            $stmt->bind_param('iii', $checking, $row['id'], $queued);
            $stmt->execute();
            $won = $stmt->affected_rows === 1;
            $stmt->close();
            if ($won) return $row;
        }
    }

    /**
     * How many times the AI may read one document. The model answers differently run to run on the same photo
     * (a handwritten name one letter off, "11/04/2026" read as "1/04/2026"), so a read that doesn't come out Proceed -
     * or an AI call that failed - is read again and the better read is kept. A clean first read costs nothing extra.
     */
    const MAX_READS = 2;

    /**
     * Verify one claimed row and store the outcome - the mother check for a 'pink' row, the baby check for a
     * 'baby' row. Reads up to MAX_READS times (see there) and keeps the read with the fewest problems; every read is
     * logged in mamabe_ai_log. Never throws.
     */
    public static function process($conn, $row) {
        $today = date('Y-m-d');
        $file  = $row['register_type'] === 'pink' ? $row['pink_file'] : $row['baby_file'];

        $best = null;
        for ($i = 1; $i <= self::MAX_READS; $i++) {
            $started = date('Y-m-d H:i:s');
            $r = self::readOnce($row, $today);
            self::logAiRead($conn, $row['id'], $file, $started, $r['ai'], $r['status']);
            if ($best === null || $r['problems'] < $best['problems']) $best = $r;
            if ($best['status'] === self::STATUS_PROCEED) break;
        }

        $f    = $best['fields'];
        $now  = date('Y-m-d H:i:s');
        $note = $best['note'] === null ? null : mb_substr($best['note'], 0, 255, 'UTF-8');
        if ($row['register_type'] === 'pink') {
            $stmt = $conn->prepare("UPDATE mamabe_submission SET status = ?, extracted_mother = ?, extracted_edd = ?, check_note = ?, checked_at = ? WHERE id = ?");
            $stmt->bind_param('issssi', $best['status'], $f['mother'], $f['edd'], $note, $now, $row['id']);
        } else {
            $stmt = $conn->prepare("UPDATE mamabe_submission SET status = ?, extracted_child = ?, extracted_dob = ?, extracted_mother = ?, extracted_mykid = ?, check_note = ?, checked_at = ? WHERE id = ?");
            $stmt->bind_param('issssssi', $best['status'], $f['child'], $f['dob'], $f['mother'], $f['mykid'], $note, $now, $row['id']);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * One AI read of the row's document + the decision on it. Returns array('ai' => the raw read, 'fields' => what to
     * store, 'status', 'note', 'problems' => count; a failed AI call counts as worse than any read).
     */
    private static function readOnce($row, $today) {
        $cut = function ($v) { return $v === null || $v === '' ? null : mb_substr($v, 0, 255, 'UTF-8'); };

        if ($row['register_type'] === 'pink') {
            $ai = self::tryReadMother($row['pink_file']);
            if (!$ai['ok']) {
                return array('ai' => $ai, 'fields' => array('mother' => null, 'edd' => null),
                             'status' => self::STATUS_PENDING, 'note' => 'AI failed: ' . $ai['error'], 'problems' => PHP_INT_MAX);
            }
            $f = array('mother' => $cut($ai['name']), 'edd' => $cut($ai['date']));
            $d = self::decideMother(array('mother_name' => $row['mother_name'], 'extracted_mother' => $f['mother'], 'extracted_edd' => $f['edd']), $today);
            return array('ai' => $ai, 'fields' => $f, 'status' => $d['status'], 'note' => $d['note'], 'problems' => $d['problems']);
        }

        // register_type === 'baby'
        $ai = self::tryReadBaby($row['baby_file']);
        if (!$ai['ok']) {
            return array('ai' => $ai, 'fields' => array('child' => null, 'dob' => null, 'mother' => null, 'mykid' => null),
                         'status' => self::STATUS_PENDING, 'note' => 'AI failed: ' . $ai['error'], 'problems' => PHP_INT_MAX);
        }
        $f = array(
            'child'  => $cut($ai['name']),
            'dob'    => $cut($ai['date']),
            'mother' => $cut($ai['mother']),
            'mykid'  => $ai['mykid'] === '' ? null : mb_substr($ai['mykid'], 0, 30, 'UTF-8'),
        );
        $d = self::decideBaby(array(
            'child_name' => $row['child_name'], 'extracted_child' => $f['child'],
            'mother_name' => $row['mother_name'], 'extracted_mother' => $f['mother'],
            'extracted_dob' => $f['dob'], 'extracted_mykid' => $f['mykid'],
        ), $today);
        return array('ai' => $ai, 'fields' => $f, 'status' => $d['status'], 'note' => $d['note'], 'problems' => $d['problems']);
    }

    private static function tryReadMother($file) {
        try {
            return self::readMotherDoc(MAMABE_UPLOAD_DIR . basename((string)$file));
        } catch (Exception $e) {
            return array('ok' => false, 'name' => '', 'date' => '', 'error' => 'unexpected error', 'ai_ms' => 0);
        }
    }

    private static function tryReadBaby($file) {
        try {
            return self::readBabyDoc(MAMABE_UPLOAD_DIR . basename((string)$file));
        } catch (Exception $e) {
            return array('ok' => false, 'name' => '', 'date' => '', 'mother' => '', 'mykid' => '', 'error' => 'unexpected error', 'ai_ms' => 0);
        }
    }

    /**
     * One row in mamabe_ai_log per AI read, for timing analysis (see README "AI timing log"). Runs after the result
     * is saved and costs one small INSERT (milliseconds, next to seconds-to-minutes of AI time). Never fails the
     * check: if the table is missing or the insert fails, logging is silently skipped.
     */
    private static function logAiRead($conn, $submissionId, $file, $started, $ai, $status) {
        $abs   = MAMABE_UPLOAD_DIR . basename((string)$file);
        $size  = is_file($abs) ? @getimagesize($abs) : false;
        $w     = $size ? (int)$size[0] : null;
        $h     = $size ? (int)$size[1] : null;
        $bytes = is_file($abs) ? (int)filesize($abs) : null;
        $model = mb_substr((string)AI_VISION_MODEL, 0, 50, 'UTF-8');
        $maxPx = (int)MAMABE_AI_MAX_PX;
        $aiMs  = (int)$ai['ai_ms'];
        $aiOk  = $ai['ok'] ? 1 : 0;
        $err   = $ai['ok'] ? null : mb_substr((string)$ai['error'], 0, 255, 'UTF-8');
        $stat  = (int)$status;

        $stmt = $conn->prepare("INSERT INTO mamabe_ai_log (submission_id, started_at, ai_ms, img_width, img_height, img_bytes,"
                             . " ai_max_px, model, ai_ok, ai_error, result_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return;
        $stmt->bind_param('isiiiiisisi', $submissionId, $started, $aiMs, $w, $h, $bytes, $maxPx, $model, $aiOk, $err, $stat);
        $stmt->execute();
        $stmt->close();
    }

    public static function hasQueued($conn) {
        $res = $conn->query("SELECT 1 FROM mamabe_submission WHERE status = " . self::STATUS_QUEUED . " LIMIT 1");
        return $res && $res->num_rows > 0;
    }
}
