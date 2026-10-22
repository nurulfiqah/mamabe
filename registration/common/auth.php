<?php
/**
 * Mamabe access control.
 *
 * The public form/API has no login. The admin side (results list + attachment images + Settings) uses the same
 * rule as the rest of the MamaBe module: staff.mamab, which lock_adv.php loads into $mamab_autho
 * (3 = Super Admin, 1 = Admin, anything else = no access here).
 *
 * 'ai' (AI Analysis tab only): staff.mamab = 1 AND staff.department contains 16 (Digital Innovation, the team that
 * tunes the AI check) - a separate rule from admin, and neither implies the other. staff.department is a
 * comma-separated list (same convention as elsewhere in odb), so this checks membership, not equality.
 */
const MAMABE_AI_VIEW_DEPARTMENT = 16;   // staff_department.id - Digital Innovation

function mamabe_is_admin($mamab_autho) {
    return in_array((string)$mamab_autho, array('1', '3'), true);
}

/** Delete a whole registration (ajax_delete.php): Admin (staff.mamab = 1) only - not Super Admin. */
function mamabe_can_delete($mamab_autho) {
    return (string)$mamab_autho === '1';
}

function mamabe_can_view_ai($mamab_autho, $department) {
    if ((int)$mamab_autho !== 1) return false;
    $ids = array_filter(array_map('intval', explode(',', (string)$department)));
    return in_array(MAMABE_AI_VIEW_DEPARTMENT, $ids, true);
}
