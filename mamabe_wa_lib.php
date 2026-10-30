<?php
/**
 * Shared WhatsApp message helpers for the campaign blast - used by mamabe_blast_export.php (data.js for the sending
 * tool) and mamabe_blast.php (the one-by-one "Send manually" list), so both build exactly the same message.
 */

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

/**
 * The campaign's template for this mama's language (mamabe_mama.language: 1 BI, 2 BM, 3 CN, else Tamil → BM).
 * If that language's message was left empty on the campaign, falls back BM → BI → CN instead of sending a blank
 * message. Returns array(template, column used: 'wa_bi'|'wa_bm'|'wa_cn'), or array('', '') if all three are empty.
 */
function mamabe_wa_pick($wa, $lang) {
    $lang  = (int)$lang;
    $first = $lang == 1 ? 'wa_bi' : ($lang == 3 ? 'wa_cn' : 'wa_bm');
    foreach (array_unique(array($first, 'wa_bm', 'wa_bi', 'wa_cn')) as $col) {
        $tpl = isset($wa[$col]) ? (string)$wa[$col] : '';
        if (trim(strip_tags(html_entity_decode($tpl, ENT_QUOTES, 'UTF-8'))) !== '') return array($tpl, $col);
    }
    return array('', '');
}

function mamabe_wa_template($wa, $lang) {
    $pick = mamabe_wa_pick($wa, $lang);
    return $pick[0];
}

/** TinyMCE HTML template → plain WhatsApp text with [mama]/[baby] filled in and {a|b} spintax resolved. */
function mamabe_wa_message($msg_tpl, $mama_name, $baby_name) {
    $msg_tpl = preg_replace('/<br\s*\/?>/i', "\n", $msg_tpl);
    $msg_tpl = preg_replace('/<\/p>/i', "\n", $msg_tpl);
    $msg_tpl = strip_tags(html_entity_decode(mamabe_fix_emoji($msg_tpl), ENT_QUOTES, 'UTF-8'));
    $msg_tpl = preg_replace("/\n{3,}/", "\n\n", $msg_tpl); // collapse excess blank lines
    $msg = str_replace('[mama]', (string)$mama_name, $msg_tpl);
    $msg = str_replace('[baby]', (string)$baby_name, $msg);
    return trim(mamabe_spintax($msg));
}
