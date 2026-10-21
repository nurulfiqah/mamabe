<?php
$connect = 1;
include('../common/index_adv.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}

$campaign_id = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
$blast_date  = (isset($_POST['blast_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['blast_date']))
               ? $_POST['blast_date'] : date('Y-m-d');
$send_min    = isset($_POST['send_min'])    ? max(5,  (int)$_POST['send_min'])    : 8;
$send_max    = isset($_POST['send_max'])    ? max(10, (int)$_POST['send_max'])    : 20;
$pic_min     = isset($_POST['pic_min'])     ? max(1,  (int)$_POST['pic_min'])     : 2;
$pic_max     = isset($_POST['pic_max'])     ? max(1,  (int)$_POST['pic_max'])     : 5;
if ($send_min > $send_max) { $tmp = $send_min; $send_min = $send_max; $send_max = $tmp; }
if ($pic_min > $pic_max)   { $tmp = $pic_min;  $pic_min  = $pic_max;  $pic_max  = $tmp; }
$max_session = isset($_POST['max_session']) ? max(1,  (int)$_POST['max_session']) : 150;
$op          = isset($_POST['op'])         ? (int)$_POST['op']                   : 0;

if (!$campaign_id) {
    die("Invalid campaign.");
}

// Load campaign
$stmt = $conn->prepare("SELECT * FROM mamabe_campaign WHERE id=? AND recycle=0 LIMIT 1");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$campaign = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$campaign) {
    die("Campaign not found.");
}

// Load WA messages
$stmt = $conn->prepare("SELECT wa_bi, wa_bm, wa_cn FROM mamabe_wa WHERE campaign_id=? LIMIT 1");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$wa = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$wa) {
    die("No WhatsApp messages set for this campaign.");
}

$date_type = (int)$campaign['date_type'];
$duration  = (int)$campaign['duration'];

if ($date_type == 1) {
    $sql = "
        SELECT m.id AS mama_id, m.mama_name, m.phone, m.language,
               b.id AS baby_id, b.baby_name, b.lmp AS ref_date
        FROM mamabe_mama m
        JOIN mamabe_baby b ON b.mama_id = m.id
        LEFT JOIN mamabe_blast_log bl
            ON bl.campaign_id = $campaign_id AND bl.mama_id = m.id
            AND bl.baby_id = b.id AND bl.blast_date = '$blast_date'
        WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered != 2
          AND b.lmp IS NOT NULL AND b.lmp != '0000-00-00'
          AND DATEDIFF('$blast_date', b.lmp) >= $duration
          AND DATEDIFF(DATE(m.timestamp), b.lmp) <= $duration
          AND (bl.wa_sent IS NULL OR bl.wa_sent = 0)
        ORDER BY m.mama_name ASC
        LIMIT $max_session
    ";
} elseif ($date_type == 2) {
    $sql = "
        SELECT m.id AS mama_id, m.mama_name, m.phone, m.language,
               b.id AS baby_id, b.baby_name, b.dob AS ref_date
        FROM mamabe_mama m
        JOIN mamabe_baby b ON b.mama_id = m.id
        LEFT JOIN mamabe_blast_log bl
            ON bl.campaign_id = $campaign_id AND bl.mama_id = m.id
            AND bl.baby_id = b.id AND bl.blast_date = '$blast_date'
        WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered=1
          AND b.dob IS NOT NULL AND b.dob != '0000-00-00'
          AND DATEDIFF('$blast_date', b.dob) >= $duration
          AND DATEDIFF(DATE(m.timestamp), b.dob) <= $duration
          AND (bl.wa_sent IS NULL OR bl.wa_sent = 0)
        ORDER BY m.mama_name ASC
        LIMIT $max_session
    ";
} else {
    $sql = "
        SELECT m.id AS mama_id, m.mama_name, m.phone, m.language,
               b.id AS baby_id, b.baby_name, b.edd AS ref_date
        FROM mamabe_mama m
        JOIN mamabe_baby b ON b.mama_id = m.id
        LEFT JOIN mamabe_blast_log bl
            ON bl.campaign_id = $campaign_id AND bl.mama_id = m.id
            AND bl.baby_id = b.id AND bl.blast_date = '$blast_date'
        WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered != 2
          AND b.edd IS NOT NULL AND b.edd != '0000-00-00'
          AND DATEDIFF(b.edd, '$blast_date') <= $duration
          AND DATEDIFF(b.edd, DATE(m.timestamp)) >= $duration
          AND (bl.wa_sent IS NULL OR bl.wa_sent = 0)
        ORDER BY m.mama_name ASC
        LIMIT $max_session
    ";
}

$result = $conn->query($sql);
if (!$result || $result->num_rows == 0) {
    die("No unsent contacts found for this date.");
}

// TinyMCE stores emoji as surrogate-pair decimal entities e.g. &#55357;&#56470;
// php html_entity_decode treats those as invalid code points → replacement char
// This converts them to the correct single code-point entity first.
function mamabe_surr_cb($m) {
    $hi = (int)$m[1]; $lo = (int)$m[2];
    if ($hi >= 55296 && $hi <= 56319 && $lo >= 56320 && $lo <= 57343) {
        return '&#' . (65536 + ($hi - 55296) * 1024 + ($lo - 56320)) . ';';
    }
    return $m[0];
}
function mamabe_fix_emoji($str) {
    return preg_replace_callback('/&#(\d+);&#(\d+);/', 'mamabe_surr_cb', $str);
}

function mamabe_spintax($str) {
    return preg_replace_callback('/\{(.*?)\}/', function($m) {
        $words = explode('|', $m[1]);
        return $words[array_rand($words)];
    }, $str);
}

function mamabe_wa_phone_export($phone) {
    $hp = preg_replace('/\D/', '', $phone);
    if (substr($hp, 0, 3) === '601') { return $hp; }
    if (substr($hp, 0, 2) === '01')  { return '6' . $hp; }
    if (substr($hp, 0, 1) === '1')   { return '6' . $hp; }
    return $hp;
}

$rows        = array();
$contacts    = array();
$send_min_ms = $send_min * 1000;
$send_max_ms = $send_max * 1000;
$pic_min_ms  = $pic_min  * 1000;
$pic_max_ms  = $pic_max  * 1000;
$send_time2  = 0;
$pic_time2   = 1000;
$count       = 0;

while ($row = $result->fetch_assoc()) {
    $lang = (int)$row['language'];
    if ($lang == 1)      { $msg_tpl = (string)$wa['wa_bi']; }
    elseif ($lang == 2)  { $msg_tpl = (string)$wa['wa_bm']; }
    elseif ($lang == 3)  { $msg_tpl = (string)$wa['wa_cn']; }
    else                 { $msg_tpl = (string)$wa['wa_bm']; } // Tamil fallback

    // DEBUG – remove after fixing
    if ($count == 0) {
        $dbg = '';
        for ($di = 0; $di < min(200, strlen($msg_tpl)); $di++) {
            $dbg .= sprintf('%02X ', ord($msg_tpl[$di]));
        }
        file_put_contents(__DIR__ . '/mamabe_debug.txt', "RAW DB bytes:\n" . $dbg . "\n\nRAW string:\n" . $msg_tpl . "\n");
    }

    $msg_tpl = preg_replace('/<br\s*\/?>/i', "\n", $msg_tpl);
    $msg_tpl = preg_replace('/<\/p>/i', "\n", $msg_tpl);
    $msg_tpl = strip_tags(html_entity_decode(mamabe_fix_emoji($msg_tpl), ENT_QUOTES, 'UTF-8'));
    $msg_tpl = preg_replace("/\n{3,}/", "\n\n", $msg_tpl); // collapse excess blank lines
    $msg = str_replace('[mama]', (string)$row['mama_name'], $msg_tpl);
    $msg = str_replace('[baby]', (string)$row['baby_name'], $msg);

    $msg = trim(mamabe_spintax($msg));

    $phone = mamabe_wa_phone_export((string)$row['phone']);
    if ($phone == '') { continue; }

    if ($count == 0) {
        $send_time2 = 0;
        $pic_time2  = 1000;
    } else {
        $send_time2 = rand($send_min_ms, $send_max_ms);
        $pic_time2  = rand($pic_min_ms,  $pic_max_ms);
        if ($count % 30 == 0) { $send_time2 += 120000; $pic_time2 += 120000; }
    }

    $msg_safe = json_encode($msg, JSON_UNESCAPED_UNICODE);
    $msg_safe = substr($msg_safe, 1, -1);    
    $msg_safe = str_replace("'", "\\'", $msg_safe); 

    $rows[] = "['+$phone', '$msg_safe', $send_time2, $pic_time2]";

    $contacts[] = array(
        'no'      => $count + 1,
        'name'    => $row['mama_name'],
        'phone'   => $phone,
        'link'    => 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg),
        'mama_id' => (int)$row['mama_id'],
        'baby_id' => (int)$row['baby_id'],
    );

    $count++;
}

if ($count == 0) {
    die("No valid phone numbers found.");
}

$data  = "var data=[" . implode(",\n", $rows) . "];\n";
$data .= "var path='./alpro.jpg';\n";
$data .= "module.exports.data=data;\n";
$data .= "module.exports.path=path;\n";

$data .= "\n/*\n";
$data .= "==========================================\n";
$data .= " BLAST LIST — " . date('d/m/Y') . " — " . $count . " contact(s)\n";
$data .= "==========================================\n";
foreach ($contacts as $c) {
    $data .= $c['no'] . ". " . $c['name'] . " | " . $c['phone'] . "\n";
    $data .= "   " . $c['link'] . "\n\n";
}
$data .= "==========================================\n";
$data .= "*/\n";

// Mark all exported contacts as sent
$now = date('Y-m-d H:i:s');
$stmt_log = $conn->prepare("
    INSERT INTO mamabe_blast_log (campaign_id, mama_id, baby_id, blast_date, wa_sent, wa_op, wa_datetime, timestamp)
    VALUES (?, ?, ?, ?, 1, ?, ?, ?)
    ON DUPLICATE KEY UPDATE wa_sent=1, wa_op=?, wa_datetime=?
");
foreach ($contacts as $c) {
    $log_mama_id = $c['mama_id'];
    $log_baby_id = $c['baby_id'];
    $stmt_log->bind_param("iiisissis",
        $campaign_id, $log_mama_id, $log_baby_id, $blast_date,
        $op, $now, $now,
        $op, $now
    );
    $stmt_log->execute();
}
$stmt_log->close();

$filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($campaign['instruction'])) . '_' . date('Y-m-d') . '.js';
header('Content-type: application/javascript; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $data;
exit;
?>
