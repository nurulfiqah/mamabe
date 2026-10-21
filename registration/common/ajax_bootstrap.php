<?php
/**
 * Include at the top of any admin AJAX/JSON/image endpoint instead of header.php - runs the same
 * login + admin gate (lock_adv.php + auth.php) but discards lock_adv.php's printed HTML (nav bar,
 * clock script) instead of buffering it, since these responses have no page to flush it into.
 * The API handler (api/register.php, api_user auth) and worker.php do NOT use this - they have no odb login.
 */
ob_start();
require_once(dirname(__FILE__) . '/../../../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../../../common/index_adv.php');
require_once(dirname(__FILE__) . '/auth.php');
ob_end_clean();
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kuala_Lumpur');

// A page picks its rule by setting $mamabe_access before including this file ('admin' default, or 'ai').
$mamabe_access = isset($mamabe_access) ? $mamabe_access : 'admin';
$mamabe_allowed = $mamabe_access === 'ai'
    ? mamabe_can_view_ai(isset($mamab_autho) ? $mamab_autho : 0, isset($department) ? $department : '')
    : mamabe_is_admin(isset($mamab_autho) ? $mamab_autho : 0);
if (!$mamabe_allowed) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'No access.'));
    exit;
}
header('Content-Type: application/json; charset=utf-8');
