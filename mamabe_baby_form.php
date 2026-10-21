<?php
/*
 * Public baby birth detail form.
 * Linked via: mamabe_baby_form.php?mid=MAMA_ID&bid=BABY_ID
 * Mama fills in birth details after delivery; submits to update mamabe_baby.
 */
date_default_timezone_set('Asia/Kuala_Lumpur');

$connect = 1;
include('../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Service temporarily unavailable. Please try again later.");
}
header('Content-Type: text/html; charset=UTF-8');

$mama_id = isset($_GET['mid']) ? (int)$_GET['mid'] : 0;
$baby_id = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;

if (!$mama_id || !$baby_id) {
    die('<p style="padding:30px;font-family:sans-serif">Invalid link. Please contact your clinic.</p>');
}

// Load baby + mama
$stmt = $conn->prepare("
    SELECT b.*, m.mama_name, m.phone
    FROM mamabe_baby b
    JOIN mamabe_mama m ON b.mama_id = m.id
    WHERE b.id = ? AND b.mama_id = ? AND b.recycle = 0
    LIMIT 1
");
$stmt->bind_param("ii", $baby_id, $mama_id);
$stmt->execute();
$baby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$baby) {
    die('<p style="padding:30px;font-family:sans-serif">Record not found. Please contact your clinic.</p>');
}

$submitted = false;
$error     = '';

if (isset($_POST['submit_birth'])) {
    $baby_name       = trim($_POST['baby_name'] ?? '');
    $mykid           = trim($_POST['mykid'] ?? '');
    $dob             = trim($_POST['dob'] ?? '');
    $gender          = trim($_POST['gender'] ?? '');
    if (!$baby_name || !$dob || !$gender) {
        $error = 'Please fill in Baby Name, Date of Birth and Gender.';
    } else {
        $updated_at = date('Y-m-d H:i:s');

        // Try with audit columns first
        try {
            $stmt = $conn->prepare("
                UPDATE mamabe_baby
                SET baby_name=?, mykid=?, dob=?, gender=?,
                    is_delivered=1, updated_at=?, updated_by=0, updated_by_name='Mama (Self)'
                WHERE id=? AND mama_id=?
            ");
        } catch (mysqli_sql_exception $e) {
            $stmt = false;
        }
        if (!$stmt) {
            // Fallback without audit columns
            $stmt = $conn->prepare("
                UPDATE mamabe_baby
                SET baby_name=?, mykid=?, dob=?, gender=?,
                    is_delivered=1
                WHERE id=? AND mama_id=?
            ");
            $stmt->bind_param("ssssii",
                $baby_name, $mykid, $dob, $gender,
                $baby_id, $mama_id
            );
        } else {
            $stmt->bind_param("sssssii",
                $baby_name, $mykid, $dob, $gender,
                $updated_at,
                $baby_id, $mama_id
            );
        }

        if ($stmt->execute()) {
            $submitted = true;
        } else {
            $error = 'Something went wrong. Please try again.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MamaBe – Baby Birth Details</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Segoe UI', Arial, sans-serif; background:#f5f7fa; color:#333; min-height:100vh; }

.page-wrap { max-width:520px; margin:30px auto; padding:0 16px 40px; }

.brand { text-align:center; margin-bottom:24px; }
.brand img { height:48px; }
.brand h2 { font-size:1.3em; color:#2a6496; margin-top:8px; }
.brand p  { font-size:0.9em; color:#888; margin-top:4px; }

.card { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.08); padding:28px; }

.mama-tag { background:#e8f4fd; border-left:4px solid #2a6496; padding:10px 14px;
            border-radius:4px; margin-bottom:22px; font-size:0.95em; }
.mama-tag strong { color:#2a6496; }

.field { margin-bottom:18px; }
.field label { display:block; font-weight:600; font-size:0.88em; color:#444; margin-bottom:6px; }
.field input, .field select {
    width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px;
    font-size:0.95em; color:#333; background:#fafafa;
    transition:border-color 0.2s;
}
.field input:focus, .field select:focus {
    border-color:#2a6496; outline:none; background:#fff;
}
.field-row { display:flex; gap:12px; flex-wrap:wrap; }
.field-row .field { flex:1 1 140px; }

.required { color:#c82333; }

.section-sep { font-weight:700; font-size:0.82em; color:#2a6496; text-transform:uppercase;
               letter-spacing:1px; border-bottom:2px solid #d0dfe8; padding-bottom:5px;
               margin:20px 0 16px; }

.btn-submit { width:100%; padding:13px; background:#2a6496; color:#fff; border:none;
              border-radius:6px; font-size:1em; font-weight:bold; cursor:pointer;
              margin-top:8px; transition:background 0.2s; }
.btn-submit:hover { background:#1e4d7a; }

.error-box { background:#f8d7da; border:1px solid #f5c6cb; color:#721c24;
             border-radius:6px; padding:12px 16px; margin-bottom:18px; font-size:0.92em; }

/* Success screen */
.success-wrap { text-align:center; padding:20px 0; }
.success-icon { font-size:4em; margin-bottom:16px; }
.success-wrap h3 { color:#155724; font-size:1.4em; margin-bottom:10px; }
.success-wrap p  { color:#666; font-size:0.95em; line-height:1.7; }

.footer-note { text-align:center; font-size:0.78em; color:#bbb; margin-top:20px; }
</style>
</head>
<body>

<div class="page-wrap">

  <div class="brand">
    <img src="images/mamabe.gif" alt="MamaBe">
    <h2>Baby Birth Details</h2>
    <p>Please fill in your newborn's information</p>
  </div>

  <div class="card">

    <?php if ($submitted): ?>

    <div class="success-wrap">
      <div class="success-icon">&#127881;</div>
      <h3>Thank you, <?php echo htmlspecialchars($baby['mama_name']); ?>!</h3>
      <p>Your baby's birth details have been submitted successfully.<br>
         Our team will review and update the records shortly.<br><br>
         <strong>Congratulations on your newborn!</strong> &#128149;
      </p>
    </div>

    <?php else: ?>

    <div class="mama-tag">
      Mama: <strong><?php echo htmlspecialchars($baby['mama_name']); ?></strong>
      &nbsp;|&nbsp; EDD: <strong><?php echo htmlspecialchars($baby['edd']); ?></strong>
    </div>

    <?php if ($error): ?>
    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">

      <div class="section-sep">Baby Information</div>

      <div class="field">
        <label>Baby Name <span class="required">*</span></label>
        <input type="text" name="baby_name"
               value="<?php echo htmlspecialchars(isset($_POST['baby_name']) ? $_POST['baby_name'] : ''); ?>"
               placeholder="Full name as per MyKid">
      </div>

      <div class="field-row">
        <div class="field">
          <label>Date of Birth <span class="required">*</span></label>
          <input type="date" name="dob"
                 value="<?php echo htmlspecialchars(isset($_POST['dob']) ? $_POST['dob'] : ''); ?>">
        </div>
        <div class="field">
          <label>Gender <span class="required">*</span></label>
          <select name="gender">
            <option value="">-- Select --</option>
            <option value="Male"   <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Male')   ? 'selected' : ''; ?>>Male</option>
            <option value="Female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label>MyKid Number (IC)</label>
        <input type="text" name="mykid"
               value="<?php echo htmlspecialchars(isset($_POST['mykid']) ? $_POST['mykid'] : ''); ?>"
               placeholder="e.g. 260401010101">
      </div>

      <button class="btn-submit" type="submit" name="submit_birth">Submit Baby Details</button>

    </form>

    <?php endif; ?>

  </div><!-- end card -->

  <div class="footer-note">MamaBe &copy; <?php echo date('Y'); ?> &mdash; ALPRO Pharmacy</div>

</div>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
