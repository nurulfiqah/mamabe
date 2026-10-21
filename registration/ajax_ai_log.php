<?php
/**
 * JSON for ai_analysis.php (AI Analysis viewers only - see common/auth.php's 'ai' rule): the AI timing log
 * (mamabe_ai_log - one row per AI read, see MamabeVerifier::logAiRead). Each row already belongs to exactly one
 * mamabe_submission row, whose register_type ('pink' = mother's document, 'baby' = baby's document) is the "who".
 * GET filters: from, to ('Y-m-d', on started_at), who ('' | pink | baby), result ('' | 2 | 3), ai ('' | ok | fail),
 *              sort ('new' | 'slow'), page. summary=1 also returns the tiles + breakdowns (sent on filter change, not on paging).
 * Deliberately no names or images - timing analysis only needs the submission id and which document.
 */
$mamabe_access = 'ai';
require_once(dirname(__FILE__) . '/common/ajax_bootstrap.php');
require_once(dirname(__FILE__) . '/common/config.php');
require_once(dirname(__FILE__) . '/common/lib/MamabeVerifier.php');

$per_page = 50;

$where  = array('1=1');
$params = array();
$types  = '';

$isDate = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)); };
$from = isset($_GET['from']) ? $_GET['from'] : '';
$to   = isset($_GET['to'])   ? $_GET['to']   : '';
if ($isDate($from)) { $where[] = 'l.started_at >= ?'; $params[] = $from . ' 00:00:00'; $types .= 's'; }
if ($isDate($to))   { $where[] = 'l.started_at <= ?'; $params[] = $to . ' 23:59:59';   $types .= 's'; }

$who = isset($_GET['who']) ? $_GET['who'] : '';
if ($who === 'pink' || $who === 'baby') { $where[] = 's.register_type = ?'; $params[] = $who; $types .= 's'; }

$result = isset($_GET['result']) ? $_GET['result'] : '';
if ($result === (string)MamabeVerifier::STATUS_PROCEED || $result === (string)MamabeVerifier::STATUS_PENDING) {
    $where[] = 'l.result_status = ?'; $params[] = (int)$result; $types .= 'i';
}

$ai = isset($_GET['ai']) ? $_GET['ai'] : '';
if ($ai === 'ok')   $where[] = 'l.ai_ok = 1';
if ($ai === 'fail') $where[] = 'l.ai_ok = 0';

$whereSql = implode(' AND ', $where);
$fromSql  = "FROM mamabe_ai_log l JOIN mamabe_submission s ON s.id = l.submission_id WHERE $whereSql";
$queueSql = 'GREATEST(0, TIMESTAMPDIFF(SECOND, s.created_at, l.started_at))';

/** Run a prepared SELECT with the filter params; returns all rows as assoc arrays. */
$fetchAll = function ($sql) use ($conn, $params, $types) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return array();
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : array();
    $stmt->close();
    return $rows;
};

$sec = function ($ms) { return $ms === null ? null : round($ms / 1000, 1); };

$out = array();

// ── Summary (tiles + breakdowns) ─────────────────────────────────────────────
if (!empty($_GET['summary'])) {
    $t = $fetchAll("SELECT COUNT(*) AS n, AVG(l.ai_ms) AS avg_ms, MAX(l.ai_ms) AS max_ms, SUM(l.ai_ok = 0) AS failed,
                           AVG($queueSql) AS avg_queue_s, AVG($queueSql + l.ai_ms / 1000) AS avg_total_s $fromSql");
    $t = $t ? $t[0] : array('n' => 0);
    $n = (int)$t['n'];

    // median / 90th percentile of the AI time (nearest-rank): pick the row at that position (no window functions needed)
    $at = function ($p) use ($n, $fetchAll, $fromSql) {
        if (!$n) return null;
        $r = $fetchAll("SELECT l.ai_ms $fromSql ORDER BY l.ai_ms LIMIT 1 OFFSET " . (int)max(0, min($n - 1, ceil($n * $p) - 1)));
        return $r ? (int)$r[0]['ai_ms'] : null;
    };

    $out['summary'] = array(
        'n'           => $n,
        'avg_s'       => $sec($n ? (float)$t['avg_ms'] : null),
        'median_s'    => $sec($at(0.5)),
        'p90_s'       => $sec($at(0.9)),
        'max_s'       => $sec($n ? (float)$t['max_ms'] : null),
        'avg_queue_s' => $n ? round((float)$t['avg_queue_s'], 1) : null,
        'avg_total_s' => $n ? round((float)$t['avg_total_s'], 1) : null,
        'failed'      => $n ? (int)$t['failed'] : 0,
    );

    $group = function ($keySql, $order) use ($fetchAll, $fromSql, $sec) {
        $rows = $fetchAll("SELECT $keySql AS k, COUNT(*) AS n, AVG(l.ai_ms) AS avg_ms, MAX(l.ai_ms) AS max_ms,
                                  SUM(l.ai_ok = 0) AS failed
                           $fromSql GROUP BY k ORDER BY $order");
        return array_map(function ($r) use ($sec) {
            return array('key' => $r['k'], 'n' => (int)$r['n'], 'avg_s' => $sec((float)$r['avg_ms']), 'max_s' => $sec((float)$r['max_ms']),
                         'failed' => (int)$r['failed']);
        }, $rows);
    };
    // Banded by the SHORTER side: a WhatsApp-compressed 608 x 1080 page is low-res even though its long side passes 1000.
    $out['by_photo'] = $group("CASE WHEN l.img_width IS NULL THEN '(unknown)'
                                    WHEN LEAST(l.img_width, l.img_height) < 700  THEN 'Under 700 px (low)'
                                    WHEN LEAST(l.img_width, l.img_height) < 1000 THEN '700 - 999 px'
                                    ELSE '1000 px and above' END", 'MIN(LEAST(COALESCE(l.img_width, 0), COALESCE(l.img_height, 0)))');
    $out['by_who']   = $group("IF(s.register_type = 'pink', 'Mother document (pink book)', 'Baby document (baby book)')", 'k DESC');
    $out['by_setup'] = $group("CONCAT(l.model, ' @ ', l.ai_max_px, ' px')", 'MIN(l.started_at)');
    $out['by_day']   = $group('DATE(l.started_at)', 'k DESC');
}

// ── Page of reads ────────────────────────────────────────────────────────────
$tot   = $fetchAll("SELECT COUNT(*) AS n $fromSql");
$total = $tot ? (int)$tot[0]['n'] : 0;
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($pages, max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1));
$order = (isset($_GET['sort']) && $_GET['sort'] === 'slow') ? 'l.ai_ms DESC, l.id DESC' : 'l.id DESC';

$rows = $fetchAll("SELECT l.id, l.submission_id, s.register_type, l.started_at, l.ai_ms, l.img_width, l.img_height, l.img_bytes,
                          l.ai_max_px, l.model, l.ai_ok, l.ai_error, l.result_status, $queueSql AS queue_s
                   FROM mamabe_ai_log l JOIN mamabe_submission s ON s.id = l.submission_id
                   WHERE $whereSql ORDER BY $order LIMIT " . (int)$per_page . " OFFSET " . (int)(($page - 1) * $per_page));

$meta = MamabeVerifier::statusMeta();
$out['rows'] = array_map(function ($r) use ($meta, $sec) {
    $m = isset($meta[(int)$r['result_status']]) ? $meta[(int)$r['result_status']] : array('Unknown', 'gray');
    return array(
        'id'            => (int)$r['id'],
        'submission_id' => (int)$r['submission_id'],
        'who'           => $r['register_type'] === 'pink' ? 'Mother' : 'Baby',
        'started_at'    => $r['started_at'],
        'queue_s'       => (int)$r['queue_s'],
        'ai_s'          => $sec((int)$r['ai_ms']),
        'total_s'       => round((int)$r['queue_s'] + $r['ai_ms'] / 1000, 1),
        'photo'         => $r['img_width'] === null ? null : (int)$r['img_width'] . ' × ' . (int)$r['img_height'],
        'kb'            => $r['img_bytes'] === null ? null : (int)round($r['img_bytes'] / 1024),
        'setup'         => $r['model'] . ' @ ' . (int)$r['ai_max_px'] . ' px',
        'ai_ok'         => (int)$r['ai_ok'] === 1,
        'ai_error'      => $r['ai_error'],
        'result_label'  => $m[0],
        'result_pill'   => $m[1],
    );
}, $rows);
$out['total']    = $total;
$out['page']     = $page;
$out['pages']    = $pages;
$out['per_page'] = $per_page;

echo json_encode($out, JSON_UNESCAPED_UNICODE);
