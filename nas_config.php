<?php
// NAS credentials come from mamabe/.env (gitignored) - this file is tracked in git, so it must never contain the
// real values itself, even as a "fallback". Without a .env, CORP_NAS_USER/PASSWORD are simply empty and
// corpNasConnect() will fail to log in (a clear error) rather than silently working with a leaked password.
$_mamabeNasEnv = (is_file(__DIR__ . '/.env') && is_readable(__DIR__ . '/.env'))
    ? parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW)
    : array();

define('CORP_NAS_IP',       $_mamabeNasEnv['NAS_IP']       ?? '');
define('CORP_NAS_PORT',     (int)($_mamabeNasEnv['NAS_PORT'] ?? 5101));
define('CORP_NAS_USER',     $_mamabeNasEnv['NAS_USER']     ?? '');
define('CORP_NAS_PASSWORD', $_mamabeNasEnv['NAS_PASSWORD'] ?? '');
define('CORP_NAS_FOLDER',   $_mamabeNasEnv['NAS_FOLDER']   ?? '/DigitalInnovation/mamabe');

function corpNasConnect() {
    require_once __DIR__ . '/lib/synologynas.php';
    return new CorporateNAS(CORP_NAS_IP, CORP_NAS_PORT, CORP_NAS_USER, CORP_NAS_PASSWORD);
}
