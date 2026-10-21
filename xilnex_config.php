<?php
// Xilnex Cloud API credentials, read from mamabe/.env (gitignored) - never hardcode them in a tracked file.
$_mamabeXilnexEnv = (is_file(__DIR__ . '/.env') && is_readable(__DIR__ . '/.env'))
    ? parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW)
    : array();
define('XILNEX_APP_ID', isset($_mamabeXilnexEnv['XILNEX_APP_ID']) ? $_mamabeXilnexEnv['XILNEX_APP_ID'] : '');
define('XILNEX_TOKEN',  isset($_mamabeXilnexEnv['XILNEX_TOKEN'])  ? $_mamabeXilnexEnv['XILNEX_TOKEN']  : '');
