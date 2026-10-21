<?php
/**
 * Mamabe admin layout - top partial (odb-module-design pattern).
 *
 * A page includes this after optionally setting:
 *   $page_title - shown in <title> and as the page heading
 *   $page_js    - optional page-specific script path, emitted by footer.php
 *   $active_tab - 'submissions' (highlights the nav)
 *
 * Only MamaBe Admin / Super Admin (staff.mamab 1 or 3) get past this; everyone else is sent back to the odb home page.
 */
$page_title = isset($page_title) ? $page_title : 'Mamabe';

if (!defined('MAMABE_BASE')) {
    define('MAMABE_BASE', '/odb/mamabe/' . basename(dirname(__FILE__)) . '/');   // lives inside the MamaBe module folder
}

// lock_adv.php echoes odb's shared HTML as soon as the session check passes - buffer the whole page
// (flushed in footer.php) so a later header('Location: ...') still works. Its hardcoded "../../odb/"
// asset paths are rewritten to absolute so they resolve at any page depth.
ob_start();
ob_start();
require_once(dirname(__FILE__) . '/../../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../../common/index_adv.php');
echo str_replace('../../odb/', '/odb/', ob_get_clean());
require_once(dirname(__FILE__) . '/common/auth.php');
require_once(dirname(__FILE__) . '/common/config.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

// A page picks its rule by setting $mamabe_access before including this file ('admin' default, or 'ai' for the
// AI Analysis tab - see common/auth.php).
$mamabe_access = isset($mamabe_access) ? $mamabe_access : 'admin';
$mamabe_allowed = $mamabe_access === 'ai'
    ? mamabe_can_view_ai(isset($mamab_autho) ? $mamab_autho : 0, isset($department) ? $department : '')
    : mamabe_is_admin(isset($mamab_autho) ? $mamab_autho : 0);
if (!$mamabe_allowed) {
    header('Location: /odb/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- odb shared framework (same set as the MamaBe / AAP modules) -->
    <link rel="stylesheet" href="/odb/common/css/layout.css">
    <link rel="stylesheet" href="/odb/common/css/page.css">
    <link rel="stylesheet" href="/odb/common/css/alpro-core.css">
    <link rel="stylesheet" href="/odb/common/css/alpro-components.css">
    <link rel="stylesheet" href="/odb/common/css/alpro-utils.css">
    <link rel="stylesheet" href="<?php echo MAMABE_BASE; ?>common/css/style.css?v=<?php echo time(); ?>">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>

<body>
    <div class="header">
        <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
        <h1 class="headerH1"><img src="<?php echo MAMABE_BASE; ?>images/mamabe-logo.png" alt="" class="mamabe-header-logo"> <?php echo htmlspecialchars($page_title); ?></h1>
        <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
    </div>
    <?php include(dirname(__FILE__) . '/navbar.php'); ?>
