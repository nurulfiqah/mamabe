<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<link rel="stylesheet" href="/odb/common/css/layout.css">
<link rel="stylesheet" href="/odb/common/css/page.css">
<link rel="stylesheet" href="/odb/common/css/alpro-core.css">
<link rel="stylesheet" href="/odb/common/css/alpro-components.css">
<link rel="stylesheet" href="/odb/common/css/alpro-utils.css">
<style>
.dropdown {
    position: relative;
    display: inline-block;
}
.dropdown-content {
    display: none;
    position: absolute;
    background: #fff;
    min-width: 180px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    z-index: 1000;
    border-radius: 4px;
    border: 1px solid #ddd;
    top: 100%;
    left: 0;
}
.dropdown-content a {
    color: #333;
    padding: 10px 15px;
    text-decoration: none;
    display: block;
    font-size: 13px;
    border-bottom: 1px solid #eee;
}
.dropdown-content a:last-child { border-bottom: none; }
.dropdown-content a:hover { background: #f5f9ff; color: #2a6496; }
.dropdown:hover .dropdown-content { display: block; }
</style>

<div class="alpro-box" style="margin-bottom:20px;background:#fff;overflow:visible;">
  <div class="alpro-actions" style="margin-bottom:0;display:flex;align-items:center;flex-wrap:wrap;gap:5px;overflow:visible;">

    <!-- <strong style="margin-right:10px;font-size:16px;"><img src="/odb/mamabe/images/icon.gif" style="height:30px;vertical-align:middle;margin-right:4px"> MamaBe</strong> -->

    <!-- <a href="index.php"
       class="alpro-btn <?php echo ($current_page === 'index.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
      Dashboard
    </a> -->

    <div class="dropdown">
      <button class="alpro-btn <?php echo (strpos($current_page, 'mama') !== false) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
              style="cursor:pointer;border:none;outline:none;display:inline-flex;align-items:center;gap:4px;">
        Mama &#9662;
      </button>
      <div class="dropdown-content">
        <a href="/odb/mamabe/mamabe_mama_list.php"><img src="/odb/mamabe/images/mom.png" style="width:16px;vertical-align:middle;margin-right:5px;"> Mama List</a>
        <a href="/odb/mamabe/mamabe_mama_add.php">&#43; Add Mama</a>
      </div>
    </div>

    <?php
    $_mamabe_dept_ids  = isset($department) ? array_filter(array_map('intval', explode(',', (string)$department))) : array();
    $_mamabe_can_ai    = isset($mamab_autho) && $mamab_autho == '1' && in_array(16, $_mamabe_dept_ids, true);   // 16 = Digital Innovation
    ?>
    <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1', '3'), true)): ?>
    <div class="dropdown">
      <button class="alpro-btn <?php echo (strpos($_SERVER['PHP_SELF'], '/mamabe/registration/') !== false) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
              style="cursor:pointer;border:none;outline:none;display:inline-flex;align-items:center;gap:4px;">
        Website Registrations &#9662;
      </button>
      <div class="dropdown-content">
        <a href="/odb/mamabe/registration/index.php">&#128203; Submissions</a>
        <?php if ($_mamabe_can_ai): ?>
        <a href="/odb/mamabe/registration/ai_analysis.php">&#128202; AI Analysis</a>
        <?php endif; ?>
      </div>
    </div>

    <a href="/odb/mamabe/voucher/index.php"
       class="alpro-btn <?php echo (strpos($_SERVER['PHP_SELF'], '/mamabe/voucher/') !== false) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
      &#127381; Voucher
    </a>

    <div class="dropdown">
      <button class="alpro-btn <?php echo (strpos($current_page, 'campaign') !== false || strpos($current_page, 'blast') !== false) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
              style="cursor:pointer;border:none;outline:none;display:inline-flex;align-items:center;gap:4px;">
        Campaign &#9662;
      </button>
      <div class="dropdown-content">
        <a href="/odb/mamabe/mamabe_campaign_list.php"><img src="/odb/mamabe/images/chat.png" style="width:16px;vertical-align:middle;margin-right:5px;"> Campaign List</a>
        <a href="/odb/mamabe/mamabe_add_campaign.php">&#43; Add Campaign</a>
      </div>
    </div>

    <div class="dropdown">
      <button class="alpro-btn <?php echo (strpos($current_page, 'sku') !== false || $current_page === 'mamabe_price_mismatch.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
              style="cursor:pointer;border:none;outline:none;display:inline-flex;align-items:center;gap:4px;">
        Settings &#9662;
      </button>
      <div class="dropdown-content">
        <a href="/odb/mamabe/mamabe_sku_master.php"><img src="/odb/mamabe/images/view.png" style="width:16px;vertical-align:middle;margin-right:5px;"> SKU Master</a>
        <?php if (isset($mamab_autho) && in_array($mamab_autho, array('1', '3'), true)): ?>
        <a href="/odb/mamabe/mamabe_price_mismatch.php" style="color:#c62828;">&#9888; Mismatch Report</a>
        <a href="/odb/mamabe/registration/settings.php">&#9881; Website Registration Settings</a>
        <a href="/odb/mamabe/registration/email_templates.php">&#9993; Email Templates</a>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>
