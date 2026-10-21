<?php
/**
 * Writes public MamaBe-website registrations into the MamaBe module's own tables and copies their images to the NAS.
 *
 *  - mamabe_mama: matched by IC, else by mobile number (recycle = 0; dashes / 01x vs 601x are treated alike). An existing
 *    mama is only linked to - her details are never overwritten from the public form; only an empty IC / address / outlet
 *    is filled in. A new row is inserted only when neither is there yet. Her outlet_id is the outlet she picked to redeem at. odb's `customer` table is NEVER touched
 *    (customer_id is 0: not linked to a paid membership).
 *  - mamabe_baby: one row per pregnancy / baby. "Pink book" registrations give the EDD; "baby health book" ones give the
 *    baby's date of birth. findBabyRow() picks the mama's existing row it belongs to (same MyKid, the pregnancy whose EDD
 *    fits the DOB, or the same / a close EDD) and only its empty fields are filled, so a re-submission never duplicates
 *    or overwrites a pregnancy. A second baby with the same DOB and another MyKid becomes the first one's twin
 *    (twin_id, as the MamaBe page does it). No match -> a new row (a baby with no recorded pregnancy uses the DOB as the EDD).
 *  - NAS: worker.php copies the stored images to the same folders the MamaBe module uses (pink_book/, baby_health_book/)
 *    and sets mamabe_baby.pink_book / baby_health_book when those are still empty - so mamabe_mama_detail.php shows them.
 */
class MamabeRecords {

    /** mamabe_mama.language codes (same as the MamaBe module's $lang_map). */
    public static function languages() {
        return array(1 => 'English', 2 => 'Bahasa Melayu', 3 => 'Chinese (中文)', 4 => 'Tamil (தமிழ்)');
    }

    /** mamabe_baby.gender values. */
    public static function genders() {
        return array('Female' => 'Girl (Perempuan)', 'Male' => 'Boy (Lelaki)');
    }

    /** Self-migrating lookup table for "How do you know us?" (mamabe_submission.source) - admin-editable on
     *  registration/settings.php instead of a hardcoded list, same soft-delete-via-recycle convention as the
     *  rest of the app's lookup tables. Seeded once with the values the module shipped with. */
    public static function ensureSourceTable(mysqli $conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS mamabe_source (
            id INT AUTO_INCREMENT PRIMARY KEY,
            value VARCHAR(30) NOT NULL,
            label VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            recycle TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY idx_value (value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $res = $conn->query("SELECT COUNT(*) AS c FROM mamabe_source");
        if ($res && (int)$res->fetch_assoc()['c'] === 0) {
            $conn->query("INSERT INTO mamabe_source (value, label, sort_order, recycle) VALUES
                ('outlet', 'Outlet', 1, 0),
                ('social_media', 'Social media', 2, 0),
                ('insurance', 'Insurance', 3, 1)");
        }
        // Added later than the three above - a plain COUNT(*)===0 seed (the block above) only fires once, on a
        // brand-new table, so an existing deployment needs its own one-time insert instead. 'confinement_center' is
        // a fixed value the public form's JS checks for by name (see form.php) to switch the outlet picker over to
        // self::avidaOutlets() instead of the normal Alpro Pharmacy/Baby list - never rename this value without
        // updating the form too.
        $res = $conn->query("SELECT 1 FROM mamabe_source WHERE value = 'confinement_center'");
        if ($res && $res->num_rows === 0) {
            $conn->query("INSERT INTO mamabe_source (value, label, sort_order, recycle) VALUES
                ('confinement_center', 'Confinement center', 4, 0)");
        }
    }

    /**
     * "How do you know us?" answers (mamabe_submission.source), value => label. Active-only (recycle = 0) by
     * default - that is the set register.php accepts and the public form offers. Pass $includeRecycled = true to
     * also get retired ones (ajax_list.php needs this so an old submission's source still shows a real label even
     * after an admin has hidden/renamed it, instead of falling back to the raw code).
     */
    public static function sources(mysqli $conn, $includeRecycled = false) {
        self::ensureSourceTable($conn);
        $where = $includeRecycled ? '' : 'WHERE recycle = 0';
        $res = $conn->query("SELECT value, label FROM mamabe_source $where ORDER BY sort_order, label");
        $out = array();
        while ($res && ($r = $res->fetch_assoc())) $out[$r['value']] = $r['label'];
        return $out;
    }

    /**
     * Which document the mama registered with (mamabe_submission.register_type). 'both' is only ever used to
     * validate the incoming request in api/register.php - it is saved as two separate 'pink' + 'baby' rows, so
     * every stored mamabe_submission row is always one of the other two.
     */
    public static function registerTypes() {
        return array('pink' => 'Pink book', 'baby' => 'Baby health book', 'both' => 'Pink book & baby health book');
    }

    /**
     * Outlets a mama can pick to redeem at: active Alpro Pharmacy / Alpro Baby shops.
     * id => array('name' => display name without the company registration number, 'state' => stateOf(addr)),
     * sorted by state then name. Used by api/outlets.php and to validate the pick.
     */
    public static function outlets(mysqli $conn) {
        $res = $conn->query("SELECT id, comp_name, addr FROM outlet
                              WHERE recycle = 0 AND (comp_name LIKE 'ALPRO PHARMA%' OR comp_name LIKE 'ALPRO BABY%')");
        $out = array();
        while ($res && ($r = $res->fetch_assoc())) {
            $out[(int)$r['id']] = array('name' => self::outletName($r['comp_name']), 'state' => self::stateOf($r['addr']));
        }
        uasort($out, function ($a, $b) {
            return strnatcasecmp($a['state'], $b['state']) ?: strnatcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Avida confinement center outlets a mama can pick to redeem at - shown instead of the normal Alpro Pharmacy/
     * Baby outlet list when she picks "Confinement center" for "How do you know us?" (see form.php). Avida outlets
     * are identified purely by outlet.acmm_id starting with "42" (e.g. 4200 today) - not by name, so a future
     * Avida outlet with a new 42xx acmm_id is picked up automatically with no code change, the same way outlets()
     * itself is driven off comp_name rather than a hardcoded id list. NOTE: outlet.code is a different, unrelated
     * alphanumeric field (e.g. "NS2GHM") - acmm_id is the numeric one that actually carries the 42xx convention.
     * id => display name, sorted by name. Used by api/avida_outlets.php and to validate the pick in register.php.
     */
    public static function avidaOutlets(mysqli $conn) {
        $res = $conn->query("SELECT id, comp_name FROM outlet WHERE recycle = 0 AND acmm_id LIKE '42%'");
        $out = array();
        while ($res && ($r = $res->fetch_assoc())) {
            $out[(int)$r['id']] = self::outletName($r['comp_name']);
        }
        asort($out);
        return $out;
    }

    /** outlet.comp_name for display: "ALPRO PHARMACY AMPANGAN (1015456-V)" -> "Alpro Pharmacy Ampangan". */
    public static function outletName($compName) {
        return ucwords(strtolower(trim(preg_replace('/\s*\(?\d{6,8}-[A-Z]\)?\s*$/i', '', (string)$compName))));
    }

    /**
     * Malaysian state (negeri) of an outlet address - `outlet` has no state column. The state name written LAST in the
     * address wins (streets are often named after states: "Jalan Perak, ... Pulau Pinang"); otherwise the postcode's
     * first two digits; otherwise a few Sarawak town names. 'Others' when nothing matches.
     */
    public static function stateOf($addr) {
        static $states = array(
            // state => array(name regex, postcode prefixes)
            'Johor'           => array('Johor', '79,80,81,82,83,84,85,86'),
            'Kedah'           => array('Kedah', '05,06,07,08,09'),
            'Kelantan'        => array('Kelantan', '15,16,17,18'),
            'Kuala Lumpur'    => array('Kuala Lumpur', '50,51,52,53,54,55,56,57,58,59,60'),
            'Labuan'          => array('Labuan', '87'),
            'Melaka'          => array('Melaka|Malacca', '75,76,77,78'),
            'Negeri Sembilan' => array('Negeri Sembilan|N\. ?Sembilan', '70,71,72,73'),
            'Pahang'          => array('Pahang', '25,26,27,28,39,49,69'),
            'Perak'           => array('Perak', '30,31,32,33,34,35,36'),
            'Perlis'          => array('Perlis', '01,02'),
            'Pulau Pinang'    => array('Pulau Pinang|Penang|P\. ?Pinang', '10,11,12,13,14'),
            'Putrajaya'       => array('Putrajaya', '62'),
            'Sabah'           => array('Sabah', '88,89,90,91'),
            'Sarawak'         => array('Sarawak', '93,94,95,96,97,98'),
            'Selangor'        => array('Selangor', '40,41,42,43,44,45,46,47,48,63,64,68'),
            'Terengganu'      => array('Terengganu', '20,21,22,23,24'),
        );
        $addr = (string)$addr;
        $best = 'Others';
        $pos  = -1;
        foreach ($states as $state => $s) {
            if (preg_match_all('/\b(?:' . $s[0] . ')\b/i', $addr, $m, PREG_OFFSET_CAPTURE)) {
                $p = end($m[0])[1];
                if ($p > $pos) { $pos = $p; $best = $state; }
            }
        }
        if ($pos >= 0) return $best;
        if (preg_match_all('/\b(\d{5})\b/', $addr, $m)) {
            $prefix = substr(end($m[1]), 0, 2);
            foreach ($states as $state => $s) {
                if (in_array($prefix, explode(',', $s[1]), true)) return $state;
            }
        }
        if (preg_match('/\b(Kuching|KCH|Samarahan|Miri|Sibu|Bintulu)\b/i', $addr)) return 'Sarawak';
        return 'Others';
    }

    // ── Input normalising (each returns '' / null when unusable) ───────────────────────────────────────────────

    /** One-line text field: control chars + runs of whitespace become a single space. */
    public static function cleanText($raw) {
        $v = preg_replace('/[\p{Cc}\s]+/u', ' ', (string)$raw);
        return $v === null ? '' : trim($v);
    }

    /** Multi-line address: each line cleaned like cleanText, empty lines dropped, lines kept (joined with "
"). */
    public static function cleanAddress($raw) {
        $lines = preg_split('/\R/u', (string)$raw);
        if ($lines === false) return '';
        $out = array();
        foreach ($lines as $l) {
            $l = self::cleanText($l);
            if ($l !== '') $out[] = $l;
        }
        return implode("
", $out);
    }

    /**
     * IC as stored in mamabe_mama.ic: letters and digits only, uppercase. A 12-digit value is a MyKad and must start
     * with a real date (YYMMDD) and a real place-of-birth code; anything else of 6-14 characters is a passport.
     */
    public static function normalizeIc($raw) {
        $ic  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$raw));
        $len = strlen($ic);
        if ($len < 6 || $len > 14) return '';
        if (ctype_digit($ic) && $len === 12 && !self::validMyKad($ic)) return '';
        return $ic;
    }

    /** MyKad / MyKid sanity check: YYMMDD is a real date and digits 7-8 a real place-of-birth code. */
    public static function validMyKad($n) {
        if (!(ctype_digit($n) && strlen($n) === 12)) return false;
        $yy = (int)substr($n, 0, 2); $mm = (int)substr($n, 2, 2); $dd = (int)substr($n, 4, 2);
        if (!checkdate($mm, $dd, 1900 + $yy) && !checkdate($mm, $dd, 2000 + $yy)) return false;
        $c = (int)substr($n, 6, 2);   // states 01-16, foreign-born 21-68 / 71-72 / 74-79 / 82-93 / 98-99
        return ($c >= 1 && $c <= 16) || ($c >= 21 && $c <= 68) || $c === 71 || $c === 72
            || ($c >= 74 && $c <= 79) || ($c >= 82 && $c <= 93) || $c === 98 || $c === 99;
    }

    /** Malaysian mobile number, digits only, as the MamaBe module stores it (01xxxxxxxx or 601xxxxxxxx). */
    public static function normalizePhone($raw) {
        $d = preg_replace('/\D/', '', (string)$raw);
        return preg_match('/^(01\d{8,9}|601\d{8,9})$/', $d) ? $d : '';
    }

    /** Y-m-d date between $min and $max (inclusive), or null. */
    public static function parseDate($raw, $min, $max) {
        $raw = trim((string)$raw);
        $d   = DateTime::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) return null;
        return ($raw < $min || $raw > $max) ? null : $raw;
    }

    // ── Saving ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** A baby can arrive this many days after the EDD / before it and still belong to that pregnancy. */
    const BIRTH_AFTER_EDD_DAYS  = 28;    // 4 weeks late
    const BIRTH_BEFORE_EDD_DAYS = 140;   // 20 weeks premature
    /** A pink-book EDD this close to one already recorded is the same pregnancy (mama and staff typed different dates). */
    const EDD_SAME_PREGNANCY_DAYS = 30;

    /**
     * The mamabe_baby row (of this mama, active) a submission belongs to, or null for a new row. Rows are locked FOR UPDATE.
     * "Open pregnancy" = not delivered and no baby details yet - the row staff (or a pink book registration) created
     * while she was pregnant.
     *  - Baby health book (has a DOB): 1) same MyKid (re-submission) · 2) the open pregnancy whose EDD is closest to the
     *    DOB, within BIRTH_AFTER_EDD_DAYS / BIRTH_BEFORE_EDD_DAYS · 3) a row whose EDD is the DOB and has no MyKid yet.
     *    Nothing found and a sibling with the same DOB exists -> the caller adds a twin (findTwinSibling).
     *  - Pink book: 1) same EDD · 2) the open pregnancy with the closest EDD within EDD_SAME_PREGNANCY_DAYS.
     * The caller only ever fills empty fields of the row found, so matching never overwrites anything.
     */
    private static function findBabyRow(mysqli $conn, $mamaId, array $s) {
        $open = "is_delivered = 0 AND baby_name = '' AND mykid = '' AND dob IS NULL";
        $tries = array();
        if ($s['baby_dob'] !== null) {
            $dob = $s['baby_dob'];
            if ($s['baby_mykid'] !== '') {
                $tries[] = array("mykid = ?", 's', array($s['baby_mykid']), 'id');
            }
            $tries[] = array("$open AND edd BETWEEN DATE_SUB(?, INTERVAL " . self::BIRTH_AFTER_EDD_DAYS . " DAY)
                                                AND DATE_ADD(?, INTERVAL " . self::BIRTH_BEFORE_EDD_DAYS . " DAY)",
                             'sss', array($dob, $dob, $dob), 'ABS(DATEDIFF(edd, ?)), id');
            $tries[] = array("edd = ? AND mykid = ''", 's', array($dob), 'id');   // never another baby (twin) - see findTwinSibling
        } else {
            $edd = $s['edd'];
            $d   = self::EDD_SAME_PREGNANCY_DAYS;
            $tries[] = array("edd = ?", 's', array($edd), 'id');
            $tries[] = array("$open AND edd BETWEEN DATE_SUB(?, INTERVAL $d DAY) AND DATE_ADD(?, INTERVAL $d DAY)",
                             'sss', array($edd, $edd, $edd), 'ABS(DATEDIFF(edd, ?)), id');
        }

        foreach ($tries as $t) {
            list($where, $types, $params, $order) = $t;
            $stmt = $conn->prepare("SELECT id FROM mamabe_baby WHERE mama_id = ? AND recycle = 0 AND ($where)
                                     ORDER BY $order LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i' . $types, $mamaId, ...$params);
            $stmt->execute();
            $stmt->bind_result($id);
            $found = $stmt->fetch();
            $stmt->close();
            if ($found) return (int)$id;
        }
        return null;
    }

    /**
     * Save one submission into mamabe_mama / mamabe_baby (caller wraps it in a transaction).
     * $s: mother_name, ic (normalizeIc), phone (normalizePhone), email, address (cleanAddress), language, outlet_id
     *     (her preferred outlet), edd, child_name, baby_mykid,
     *     baby_dob (or null), baby_gender.
     * Returns array('mama_id' => int, 'baby_id' => int, 'mama_is_new' => bool).
     */
    /**
     * mamabe_mama.id matching this IC or phone (recycle = 0), or null - by IC first, else by mobile number. Staff
     * type ICs with or without dashes, and numbers as 01x or 601x with dashes/spaces - compare the bare characters
     * (and a number in both forms). $forUpdate locks the row for an update later in the same transaction (save()'s
     * use); omit it for a read-only existence check (mamaExists()).
     */
    private static function findMamaId(mysqli $conn, $ic, $phone, $forUpdate = false) {
        $local = preg_replace('/^60/', '0', $phone);
        $intl  = '6' . $local;
        $finds = array(
            array("UPPER(REGEXP_REPLACE(ic, '[^0-9A-Za-z]', '')) = ?", 's', array($ic)),
            array("REGEXP_REPLACE(phone, '[^0-9]', '') IN (?, ?)", 'ss', array($local, $intl)),
        );
        foreach ($finds as $f) {
            $stmt = $conn->prepare("SELECT id FROM mamabe_mama WHERE {$f[0]} AND recycle = 0 ORDER BY id LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
            $stmt->bind_param($f[1], ...$f[2]);
            $stmt->execute();
            $stmt->bind_result($id);
            $found = $stmt->fetch();
            $stmt->close();
            if ($found) return (int)$id;
        }
        return null;
    }

    /**
     * True when a mama with this IC or phone is already registered (recycle = 0). api/register.php uses this to
     * downgrade a 'both' submission from an already-existing mama to 'baby' only - she doesn't need a fresh
     * pregnancy/pink-book registration, only the new baby does.
     */
    public static function mamaExists(mysqli $conn, $ic, $phone) {
        return self::findMamaId($conn, $ic, $phone) !== null;
    }

    public static function save(mysqli $conn, array $s) {
        $now = date('Y-m-d H:i:s');
        $by  = MAMABE_REGISTERED_BY;

        // Mama: link to the existing one, or create her (never in `customer`).
        $mamaId    = self::findMamaId($conn, $s['ic'], $s['phone'], true);
        $mamaIsNew = $mamaId === null;
        $email = isset($s['email']) ? $s['email'] : '';
        if ($mamaIsNew) {
            $empty = '';
            $stmt  = $conn->prepare(
                "INSERT INTO mamabe_mama (customer_id, mama_name, ic, phone, email, address, language, outlet_id, diagnosis,
                                          receipt_price_mismatch, recycle, timestamp, registered_by)
                 VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
            $stmt->bind_param('sssssiissss', $s['mother_name'], $s['ic'], $s['phone'], $email, $s['address'], $s['language'], $s['outlet_id'],
                              $empty, $empty, $now, $by);
            if (!$stmt->execute()) throw new RuntimeException('mamabe_mama insert: ' . $stmt->error);
            $mamaId = $stmt->insert_id;
            $stmt->close();
        } else {
            // Never overwrite what staff recorded - only fill her IC, email, address and outlet when still empty (her
            // outlet is the one outlet staff see her under in the MamaBe Mama List).
            $stmt = $conn->prepare("UPDATE mamabe_mama SET ic        = IF(ic = '', ?, ic),
                                                           email     = IF(email = '', ?, email),
                                                           address   = IF(address = '', ?, address),
                                                           outlet_id = IF(outlet_id = 0, ?, outlet_id)
                                     WHERE id = ?");
            $stmt->bind_param('sssii', $s['ic'], $email, $s['address'], $s['outlet_id'], $mamaId);
            if (!$stmt->execute()) throw new RuntimeException('mamabe_mama fill: ' . $stmt->error);
            $stmt->close();
        }

        // LMP is required by the MamaBe module (its page shows it and auto-links LMP <-> EDD by 280 days, but only in the
        // browser while staff edit): derive it from the EDD the same way, so our rows look like ones staff entered.
        $lmp = date('Y-m-d', strtotime($s['edd'] . ' -280 days'));

        $hasBaby = $s['child_name'] !== '' || $s['baby_mykid'] !== '' || $s['baby_dob'] !== null || $s['baby_gender'] !== '';

        // Pregnancy/baby: reuse one of this mama's rows (see findBabyRow), else add one.
        $babyId    = self::findBabyRow($conn, (int)$mamaId, $s);
        $babyFound = $babyId !== null;

        if ($babyFound) {
            if ($hasBaby) {
                // Only fill what is still empty - the public form never overwrites what staff have recorded.
                $stmt = $conn->prepare(
                    "UPDATE mamabe_baby SET
                        lmp             = IFNULL(lmp, ?),
                        baby_name       = IF(baby_name = '', ?, baby_name),
                        mykid           = IF(mykid = '', ?, mykid),
                        dob             = IFNULL(dob, ?),
                        gender          = IF(gender = '', ?, gender),
                        is_delivered    = IF(is_delivered = 0 AND ? IS NOT NULL, 1, is_delivered),
                        baby_filled_by  = IF(baby_filled_by = '', ?, baby_filled_by),
                        baby_filled_at  = IFNULL(baby_filled_at, ?),
                        updated_at      = ?,
                        updated_by_name = ?
                      WHERE id = ?");
                $stmt->bind_param('ssssssssssi', $lmp, $s['child_name'], $s['baby_mykid'], $s['baby_dob'], $s['baby_gender'],
                                  $s['baby_dob'], $by, $now, $now, $by, $babyId);
                if (!$stmt->execute()) throw new RuntimeException('mamabe_baby update: ' . $stmt->error);
                $stmt->close();
            }
        } elseif (($twinOf = self::findTwinSibling($conn, (int)$mamaId, $s)) !== null) {
            $babyId = self::insertTwin($conn, $twinOf, $s, $by, $now);
        } else {
            $delivered   = $s['baby_dob'] !== null ? 1 : 0;   // a date of birth means the baby is here
            $babyBy      = $hasBaby ? $by : '';
            $babyAt      = $hasBaby ? $now : null;
            $empty       = '';
            $stmt = $conn->prepare(
                "INSERT INTO mamabe_baby (mama_id, lmp, edd, baby_name, mykid, dob, gender, remarks, is_delivered, recycle, timestamp,
                                          benefit_50_off_items, receipt_price_mismatch, baby_receipt_price_mismatch,
                                          benefit_free_items, benefit_free_items_baby, mismatch_remark, baby_mismatch_remark,
                                          preg_filled_by, preg_filled_at, baby_filled_by, baby_filled_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssissssssssssss', $mamaId, $lmp, $s['edd'], $s['child_name'], $s['baby_mykid'], $s['baby_dob'],
                              $s['baby_gender'], $delivered, $now, $empty, $empty, $empty, $empty, $empty, $empty, $empty,
                              $by, $now, $babyBy, $babyAt);
            if (!$stmt->execute()) throw new RuntimeException('mamabe_baby insert: ' . $stmt->error);
            $babyId = $stmt->insert_id;
            $stmt->close();
        }

        return array('mama_id' => (int)$mamaId, 'baby_id' => (int)$babyId, 'mama_is_new' => $mamaIsNew, 'baby_is_new' => !$babyFound);
    }

    /**
     * Twins: a baby health book registration that fits no row (findBabyRow) but whose DOB is the DOB of one of the
     * mama's babies with another MyKid, not yet paired, is that baby's twin. Returns the sibling's id, or null.
     */
    private static function findTwinSibling(mysqli $conn, $mamaId, array $s) {
        if ($s['baby_dob'] === null || $s['baby_mykid'] === '') return null;
        $stmt = $conn->prepare("SELECT id FROM mamabe_baby
                                 WHERE mama_id = ? AND recycle = 0 AND dob = ? AND mykid <> '' AND mykid <> ? AND twin_id = 0
                                 ORDER BY id LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iss', $mamaId, $s['baby_dob'], $s['baby_mykid']);
        $stmt->execute();
        $stmt->bind_result($id);
        $found = $stmt->fetch();
        $stmt->close();
        return $found ? (int)$id : null;
    }

    /**
     * Add the twin the way the MamaBe page's "add twin" does (mamabe_mama_detail.php): a second mamabe_baby row with the
     * sibling's pregnancy part (LMP, EDD, pink book, pregnancy benefits) copied, and twin_id pointing at each other, so
     * the page shows "Baby A & Baby B" on one pregnancy. Returns the new row's id.
     */
    private static function insertTwin(mysqli $conn, $siblingId, array $s, $by, $now) {
        $stmt = $conn->prepare(
            "INSERT INTO mamabe_baby (mama_id, lmp, edd, baby_name, mykid, dob, gender, remarks, is_delivered, recycle, timestamp,
                                      pink_book, benefit_mama_book, benefit_mama_book_at, benefit_50_off, benefit_50_off_at,
                                      benefit_50_off_items, receipt_no, benefit_free_items, benefit_free_items_at,
                                      receipt_price_mismatch, baby_receipt_price_mismatch, benefit_free_items_baby,
                                      mismatch_remark, baby_mismatch_remark,
                                      preg_filled_by, preg_filled_at, baby_filled_by, baby_filled_at, twin_id)
             SELECT mama_id, lmp, edd, ?, ?, ?, ?, '', 1, 0, ?,
                    pink_book, benefit_mama_book, benefit_mama_book_at, benefit_50_off, benefit_50_off_at,
                    benefit_50_off_items, receipt_no, benefit_free_items, benefit_free_items_at,
                    '', '', '',
                    '', '',
                    preg_filled_by, preg_filled_at, ?, ?, id
               FROM mamabe_baby WHERE id = ?");
        $stmt->bind_param('sssssssi', $s['child_name'], $s['baby_mykid'], $s['baby_dob'], $s['baby_gender'], $now, $by, $now, $siblingId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_baby twin insert: ' . $stmt->error);
        $twinId = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE mamabe_baby SET twin_id = ? WHERE id = ? AND twin_id = 0");
        $stmt->bind_param('ii', $twinId, $siblingId);
        if (!$stmt->execute()) throw new RuntimeException('mamabe_baby twin link: ' . $stmt->error);
        $stmt->close();
        return (int)$twinId;
    }

    // ── NAS copy (worker.php) ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Copy the images of new submissions to the NAS and link them on mamabe_baby - straight away, whatever the AI check
     * says (it doesn't wait for it: Proceed and Pending rows are both linked). Failures are kept in nas_note and retried
     * on the next worker run. At most $limit rows per call.
     */
    public static function syncPendingToNas(mysqli $conn, $limit = 20) {
        if (!is_file(MAMABE_NAS_CONFIG)) return;
        $res  = $conn->query("SELECT id, baby_id, pink_file, baby_file FROM mamabe_submission
                               WHERE nas_synced = 0 AND baby_id IS NOT NULL
                               ORDER BY id LIMIT " . (int)$limit);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : array();
        if (!$rows) return;

        require_once MAMABE_NAS_CONFIG;
        try {
            $nas = corpNasConnect();
        } catch (Exception $e) {
            return;
        }
        foreach ($rows as $row) {
            self::syncOne($conn, $nas, $row);
        }
    }

    private static function syncOne(mysqli $conn, $nas, array $row) {
        // column, NAS folder, file prefix, local file, the benefit the MamaBe page ticks when that book is uploaded
        // (mamabe_mama_detail.php: uploading the pink book ticks "MamaBe Mama Book", the baby book ticks "Baby Book" -
        // those checkboxes can't be ticked by hand, the upload IS the tick).
        $jobs = array();
        if (!empty($row['pink_file'])) $jobs[] = array('pink_book', 'pink_book', 'baby_', $row['pink_file'], 'benefit_mama_book');
        if (!empty($row['baby_file'])) $jobs[] = array('baby_health_book', 'baby_health_book', 'bhb_', $row['baby_file'], 'benefit_baby_book');

        $error = null;
        foreach ($jobs as $j) {
            list($column, $folder, $prefix, $file, $benefit) = $j;
            // Staff may already have attached a file: never replace it.
            $stmt = $conn->prepare("SELECT $column FROM mamabe_baby WHERE id = ?");
            $stmt->bind_param('i', $row['baby_id']);
            $stmt->execute();
            $stmt->bind_result($current);
            $stmt->fetch();
            $stmt->close();
            if ($current !== null && $current !== '') continue;

            $local = MAMABE_UPLOAD_DIR . basename($file);
            if (!is_file($local)) { $error = "$column: local image missing"; continue; }
            $name = $prefix . $row['baby_id'] . '_web' . (int)$row['id'] . '_' . time() . '.jpg';
            try {
                $path = $nas->upload($local, rtrim(CORP_NAS_FOLDER, '/') . '/' . $folder, $name);
            } catch (Exception $e) {
                $path = false;
            }
            if (!$path) { $error = "$column: NAS upload failed"; continue; }

            $now  = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE mamabe_baby SET $column = ?, {$benefit} = 1, {$benefit}_at = IFNULL({$benefit}_at, ?)
                                     WHERE id = ? AND $column = ''");
            $stmt->bind_param('ssi', $path, $now, $row['baby_id']);
            $stmt->execute();
            $stmt->close();
        }

        $synced = $error === null ? 1 : 0;
        $stmt   = $conn->prepare("UPDATE mamabe_submission SET nas_synced = ?, nas_note = ? WHERE id = ?");
        $stmt->bind_param('isi', $synced, $error, $row['id']);
        $stmt->execute();
        $stmt->close();
    }
}
