<?php
require_once('../lock_adv.php');

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');

include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
header('Content-Type: text/html; charset=UTF-8');

// ── Stat queries ──────────────────────────────────────────────
$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_mama WHERE recycle=0");
$total_mama = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_mama WHERE recycle=0 AND DATE(timestamp)=CURDATE()");
$today_mama = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_baby WHERE recycle=0 AND is_delivered=0");
$expecting = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_baby WHERE recycle=0 AND is_delivered=1");
$delivered = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_baby WHERE recycle=0 AND is_delivered=2");
$miscarriage = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM mamabe_campaign WHERE recycle=0");
$total_campaigns = $res ? $res->fetch_assoc()['cnt'] : 0;

// ── Recent mama registrations (last 8) ────────────────────────
$recent_mamas = $conn->query("
    SELECT m.id, m.mama_name, m.phone, m.timestamp,
           o.comp_name AS outlet_name
    FROM mamabe_mama m
    LEFT JOIN outlet o ON m.outlet_id = o.id
    WHERE m.recycle = 0
    ORDER BY m.timestamp DESC
    LIMIT 8
");

// ── Upcoming EDD – next 30 days ───────────────────────────────
$upcoming_edd = $conn->query("
    SELECT m.id AS mama_id, m.mama_name, b.id AS baby_id,
           b.baby_name, b.edd,
           DATEDIFF(b.edd, CURDATE()) AS days_left
    FROM mamabe_baby b
    JOIN mamabe_mama m ON b.mama_id = m.id
    WHERE b.recycle=0 AND m.recycle=0
      AND b.is_delivered=0
      AND b.edd IS NOT NULL AND b.edd > '1000-01-01'
      AND b.edd >= CURDATE()
      AND b.edd <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY b.edd ASC
");

// ── Today's blast targets across all campaigns ─────────────────
$blast_today = 0;
$campaigns_r = $conn->query("SELECT id, duration, date_type FROM mamabe_campaign WHERE recycle=0");
if ($campaigns_r) {
    while ($camp = $campaigns_r->fetch_assoc()) {
        $dur = (int)$camp['duration'];
        $dt  = (int)$camp['date_type'];
        if ($dt == 1) {
            $cr = $conn->query("SELECT COUNT(DISTINCT b.mama_id) AS cnt FROM mamabe_baby b
                JOIN mamabe_mama m ON b.mama_id=m.id
                WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered!=2
                  AND b.lmp IS NOT NULL AND b.lmp > '1000-01-01'
                  AND DATEDIFF(CURDATE(),b.lmp)=$dur");
        } elseif ($dt == 2) {
            $cr = $conn->query("SELECT COUNT(DISTINCT b.mama_id) AS cnt FROM mamabe_baby b
                JOIN mamabe_mama m ON b.mama_id=m.id
                WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered=1
                  AND b.dob IS NOT NULL AND b.dob > '1000-01-01'
                  AND DATEDIFF(CURDATE(),b.dob)=$dur");
        } else {
            $cr = $conn->query("SELECT COUNT(DISTINCT b.mama_id) AS cnt FROM mamabe_baby b
                JOIN mamabe_mama m ON b.mama_id=m.id
                WHERE m.recycle=0 AND b.recycle=0 AND b.is_delivered!=2
                  AND b.edd IS NOT NULL AND b.edd > '1000-01-01'
                  AND DATEDIFF(b.edd,CURDATE())=$dur");
        }
        if ($cr) { $row = $cr->fetch_assoc(); $blast_today += (int)$row['cnt']; }
    }
}


function mamabe_formatWA_idx($phone) {
    $hp = preg_replace('/\D/', '', $phone);
    if (substr($hp, 0, 3) === '601') { return $hp; }
    if (substr($hp, 0, 2) === '01')  { return '6' . $hp; }
    if (substr($hp, 0, 1) === '1')   { return '6' . $hp; }
    return $hp;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MamaBe – Dashboard</title>
<style>
/* ── Main stat cards ───────────────────── */
.mb-stats-main { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.mb-card-main {
    flex:1 1 180px; background:#fff; border-radius:10px;
    border-left:6px solid #ccc; padding:18px 20px;
    box-shadow:0 2px 6px rgba(0,0,0,0.08); display:flex; align-items:center; gap:16px;
}
.mb-card-main .card-icon img { width:48px; height:48px; object-fit:contain; }
.mb-card-main .card-body .num { font-size:2.4em; font-weight:700; line-height:1.1; }
.mb-card-main .card-body .lbl { font-size:0.85em; color:#888; margin-top:4px; }
.mb-card-main.c-blue   { border-color:#2a6496; } .mb-card-main.c-blue   .num { color:#2a6496; }
.mb-card-main.c-green  { border-color:#128C7E; } .mb-card-main.c-green  .num { color:#128C7E; }
.mb-card-main.c-red    { border-color:#c82333; } .mb-card-main.c-red    .num { color:#c82333; }
.mb-card-main.c-orange { border-color:#e67e22; } .mb-card-main.c-orange .num { color:#e67e22; }

/* ── Secondary stat cards ──────────────── */
.mb-stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px; }
.mb-card {
    flex:1 1 120px; background:#fff; border-radius:8px;
    border-left:4px solid #ccc; padding:12px 16px;
    box-shadow:0 1px 4px rgba(0,0,0,0.07); text-align:center;
}
.mb-card .num { font-size:1.6em; font-weight:700; line-height:1.1; }
.mb-card .lbl { font-size:0.78em; color:#888; margin-top:4px; }
.mb-card.c-purple { border-color:#8e44ad; } .mb-card.c-purple .num { color:#8e44ad; }
.mb-card.c-dark   { border-color:#555; }   .mb-card.c-dark   .num { color:#555; }

/* ── Two-column panels ─────────────────── */
.mb-panels { display:flex; gap:16px; flex-wrap:wrap; }
.mb-panel  { flex:1 1 340px; background:#fff; border-radius:6px;
             border:1px solid #e4e4e4; box-shadow:0 1px 4px rgba(0,0,0,0.05); overflow:hidden; }
.mb-panel-head { background:#2a6496; color:#fff; padding:9px 14px;
                 font-weight:bold; font-size:0.92em; }
.mb-panel-head.green { background:#128C7E; }
.mb-panel-head.red   { background:#c82333; }
.mb-panel table { width:100%; border-collapse:collapse; font-size:0.88em; }
.mb-panel table td, .mb-panel table th { padding:7px 10px; border-bottom:1px solid #f0f0f0; }
.mb-panel table th { background:#f7f7f7; color:#555; font-weight:600; font-size:0.82em; }
.mb-panel table tr:last-child td { border-bottom:none; }
.mb-panel table tr:hover td { background:#fafcff; }
.mb-empty { padding:16px; color:#aaa; text-align:center; font-size:0.88em; }

/* ── EDD badge ─────────────────────────── */
.edd-urgent { background:#c82333; color:#fff; border-radius:10px; padding:1px 7px; font-size:0.78em; font-weight:bold; }
.edd-soon   { background:#e67e22; color:#fff; border-radius:10px; padding:1px 7px; font-size:0.78em; font-weight:bold; }
.edd-ok     { background:#27ae60; color:#fff; border-radius:10px; padding:1px 7px; font-size:0.78em; font-weight:bold; }
</style>
</head>
<body>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="images/mom.png" style="height:22px;vertical-align:middle;margin-right:4px"> MamaBe – Dashboard</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('mamabe_sidebar.php'); ?>

<div class="alpro-box">

  <!-- ── Main Stat Cards ── -->
  <div class="mb-stats-main">
    <div class="mb-card-main c-blue">
      <div class="card-icon"><img src="images/mom.png" alt=""></div>
      <div class="card-body">
        <div class="num"><?php echo $total_mama; ?></div>
        <div class="lbl">Total Mama</div>
      </div>
    </div>
    <div class="mb-card-main c-orange">
      <div class="card-icon"><img src="images/milk.gif" alt=""></div>
      <div class="card-body">
        <div class="num"><?php echo $expecting; ?></div>
        <div class="lbl">Expecting</div>
      </div>
    </div>
    <div class="mb-card-main c-green">
      <div class="card-icon"><img src="images/footprint.gif" alt=""></div>
      <div class="card-body">
        <div class="num"><?php echo $delivered; ?></div>
        <div class="lbl">Safely Delivered</div>
      </div>
    </div>
    <div class="mb-card-main c-red">
      <div class="card-icon"><img src="images/love.gif" alt=""></div>
      <div class="card-body">
        <div class="num"><?php echo $miscarriage; ?></div>
        <div class="lbl">Miscarriage</div>
      </div>
    </div>
  </div>


<!-- ── Panels ── -->
  <div class="mb-panels">

    <!-- Recent Mama -->
    <div class="mb-panel">
      <div class="mb-panel-head">Recent Mama Registrations</div>
      <?php if ($recent_mamas && $recent_mamas->num_rows > 0): ?>
      <table>
        <tr>
          <th>Mama Name</th>
          <th>Phone</th>
          <th>Outlet</th>
          <th>Registered</th>
        </tr>
        <?php while ($rm = $recent_mamas->fetch_assoc()): ?>
        <tr>
          <td><a href="mamabe_mama_detail.php?id=<?php echo (int)$rm['id']; ?>"><?php echo htmlspecialchars($rm['mama_name']); ?></a></td>
          <td>
            <?php if ($rm['phone'] != ''): $wap = mamabe_formatWA_idx($rm['phone']); ?>
            <a href="javascript:void(0);"
               onclick="window.open('https://api.whatsapp.com/send?phone=<?php echo $wap; ?>&language=en','wa','width=1,height=1,left=1,top=1,toolbar=no,scrollbars=no,menubar=no,resizable=no');"
               style="color:#128C7E;font-weight:bold;font-size:0.88em;">
              <img src="../common/img/wa.png" width="13"> <?php echo htmlspecialchars($rm['phone']); ?>
            </a>
            <?php else: ?><span style="color:#ccc">–</span><?php endif; ?>
          </td>
          <td style="font-size:0.85em;color:#666"><?php echo htmlspecialchars($rm['outlet_name'] ? $rm['outlet_name'] : '–'); ?></td>
          <td style="font-size:0.8em;color:#999"><?php echo htmlspecialchars(substr($rm['timestamp'], 0, 10)); ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
      <?php else: ?>
      <div class="mb-empty">No mama registered yet.</div>
      <?php endif; ?>
    </div>

    <!-- Upcoming EDD -->
    <div class="mb-panel">
      <div class="mb-panel-head red">Upcoming EDD – Next 30 Days</div>
      <?php if ($upcoming_edd && $upcoming_edd->num_rows > 0): ?>
      <table>
        <tr>
          <th>Mama</th>
          <th>EDD</th>
          <th>Days Left</th>
        </tr>
        <?php while ($ue = $upcoming_edd->fetch_assoc()):
          $days = (int)$ue['days_left'];
          if ($days <= 7)       { $badge_class = 'edd-urgent'; }
          elseif ($days <= 14)  { $badge_class = 'edd-soon'; }
          else                  { $badge_class = 'edd-ok'; }
        ?>
        <tr>
          <td>
            <a href="mamabe_mama_detail.php?id=<?php echo (int)$ue['mama_id']; ?>">
              <?php echo htmlspecialchars($ue['mama_name']); ?>
            </a>
            <?php if ($ue['baby_name'] != ''): ?>
            <br><span style="font-size:0.8em;color:#999"><?php echo htmlspecialchars($ue['baby_name']); ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.88em"><?php echo htmlspecialchars($ue['edd']); ?></td>
          <td><span class="<?php echo $badge_class; ?>"><?php echo $days; ?>d</span></td>
        </tr>
        <?php endwhile; ?>
      </table>
      <?php else: ?>
      <div class="mb-empty">No upcoming EDD in the next 30 days.</div>
      <?php endif; ?>
    </div>

  </div><!-- end panels -->

</div>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
