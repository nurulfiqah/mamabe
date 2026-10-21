<?php
// Mamabe mail utility. Same approach as atem/mailer.php (and odb/voucher/email_helper.php before it): hand-rolled
// SMTP over a raw SSL socket - no PHPMailer/Composer dependency, nothing to `composer install` on deploy. Sending
// is always best-effort: callers must not let a mail failure block the underlying action (e.g. a reject already
// committed to the DB).

/** Daily mail-attempt log, independent of PHP's own error_log() path. */
function mamabeLogMailOperation($event, $message, $data = null, $level = 'INFO') {
    $log_dir  = __DIR__ . '/../../logs';
    $log_file = $log_dir . '/mail_operations-' . date('Y-m-d') . '.log';

    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) @mkdir($log_dir, 0755);
    }
    if (!is_dir($log_dir)) return false;
    if (!is_writable($log_dir)) @chmod($log_dir, 0755);

    $line = '[' . date('Y-m-d H:i:s') . "] [$level] [$event] $message";
    if ($data !== null) $line .= ' | ' . (is_array($data) ? json_encode($data) : $data);
    $line .= "\n";

    $result = @file_put_contents($log_file, $line, FILE_APPEND);
    if ($result === false && !file_exists($log_file)) {
        @touch($log_file);
        @chmod($log_file, 0644);
        $result = @file_put_contents($log_file, $line, FILE_APPEND);
    }
    return $result !== false;
}

/**
 * SMTP credentials, sourced from .env (production mailbox) when present, falling back to mail_config.local.php
 * (e.g. a local Mailtrap sandbox). Both files are gitignored - never commit real credentials. Same file layout
 * as atem/mailer.php, just rooted at this module's own common/ folder instead.
 */
function mamabeGetMailConfig() {
    static $config = null;
    if ($config === null) {
        $envPath = __DIR__ . '/../../.env';
        $env = (is_file($envPath) && is_readable($envPath))
            ? parse_ini_file($envPath, false, INI_SCANNER_RAW)
            : false;

        if (!empty($env['MAIL_HOST'])) {
            $port = isset($env['MAIL_PORT']) ? (int)$env['MAIL_PORT'] : 465;
            $config = array(
                'host'       => $env['MAIL_HOST'],
                'port'       => $port,
                'username'   => isset($env['MAIL_USERNAME']) ? $env['MAIL_USERNAME'] : '',
                'password'   => isset($env['MAIL_PASSWORD']) ? $env['MAIL_PASSWORD'] : '',
                'secure'     => isset($env['MAIL_ENCRYPTION']) ? $env['MAIL_ENCRYPTION'] : (($port === 465) ? 'ssl' : 'tls'),
                'from_email' => !empty($env['MAIL_FROM_ADDRESS']) ? $env['MAIL_FROM_ADDRESS'] : (isset($env['MAIL_USERNAME']) ? $env['MAIL_USERNAME'] : 'noreply@mamabe.local'),
                'from_name'  => !empty($env['MAIL_FROM_NAME']) ? $env['MAIL_FROM_NAME'] : 'MamaBe',
            );
        } else {
            $path = __DIR__ . '/../../mail_config.local.php';
            $config = file_exists($path) ? include $path : array();
        }
    }
    return $config;
}

/**
 * Absolute URL to the Alpro logo (common/img/alprologo.png) - email clients can't resolve a relative path, so
 * scheme+host are added. Uses the fixed MAMABE_PUBLIC_HOST, not $_SERVER['HTTP_HOST']: most approvals run through
 * worker.php in the background, where there is no real Host header to read (see config.php's comment on it).
 */
function mamabeLogoUrl() {
    return 'https://' . MAMABE_PUBLIC_HOST . '/odb/common/img/alprologo.png';
}

/**
 * Absolute URL to the admin-uploaded "How to redeem your voucher" guide image (settings.php's Redeem Voucher
 * Guide Image, same file the public form's success screen shows via api/redeem_guide.php), or '' when no image
 * is configured - callers then simply leave it out of the email, same as the public form hides that section.
 * public_assets/ is openly servable (not behind the api_user proxy the form needs, since the recipient's mail
 * client fetches this URL directly - no server-to-server credentials involved).
 */
function mamabeRedeemGuideUrl(mysqli $conn) {
    $ext = '';
    $res = $conn->query("SELECT setting_value FROM mamabe_setting WHERE setting_key = 'redeem_guide_ext' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) $ext = (string)$row['setting_value'];
    if ($ext === '' || !is_file(__DIR__ . '/../public_assets/redeem_guide.' . $ext)) return '';

    return 'https://' . MAMABE_PUBLIC_HOST . '/odb/mamabe/registration/public_assets/redeem_guide.' . $ext;
}

/** The guide image block (caption + picture), or '' when none is configured - append to an approval email's $innerHtml. */
function mamabeRedeemGuideBlock(mysqli $conn) {
    $url = mamabeRedeemGuideUrl($conn);
    if ($url === '') return '';
    return '<div style="margin: 20px 0 0; text-align: center;">'
        . '<p style="font-size: 12px; font-weight: bold; color: #6c757d; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 10px;">How to redeem your voucher</p>'
        . '<img src="' . htmlspecialchars($url) . '" alt="How to redeem your voucher" style="max-width: 100%; border-radius: 8px;">'
        . '</div>';
}

/**
 * Shared HTML email shell, branded with the Alpro logo and MamaBe's own teal (same colours as the public
 * registration form, common/mamabe_api/css/style.css's --mamabe-teal) rather than a generic solid colour band.
 * $accentColor only tints the heading text and the top border - lets a specific email (e.g. a rejection notice)
 * use a warning colour for that one line while the overall branding (logo, borders, footer) stays consistent.
 */
function mamabeEmailShell($headerTitle, $innerHtml, $accentColor = '#1bb8d4') {
    $logo = mamabeLogoUrl();
    return "
    <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; border: 1px solid #e3e8ee; border-radius: 8px; overflow: hidden;\">
        <div style=\"background: #ffffff; padding: 20px; text-align: center; border-bottom: 3px solid {$accentColor};\">
            <img src=\"{$logo}\" alt=\"Alpro\" style=\"height: 38px; display: inline-block;\">
        </div>
        <div style=\"background-color: #ffffff; padding: 24px;\">
            <h2 style=\"margin: 0 0 16px; font-size: 18px; color: {$accentColor};\">" . htmlspecialchars($headerTitle) . "</h2>
            {$innerHtml}
        </div>
        <div style=\"text-align: center; padding: 16px; font-size: 12px; color: #6c757d; background: #f9f9f9;\">
            This is an automated message from Alpro MamaBe. Please do not reply to this email.
        </div>
    </div>
    ";
}

/** Raw-socket SMTP send over implicit TLS - identical protocol handling to atem/mailer.php's _atemSmtpSend(). */
function _mamabeSmtpSend($host, $port, $user, $pass, $fromName, $toEmail, $toName, $subject, $htmlBody, $useTLS = true) {
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    )));
    $target = ($useTLS ? 'ssl://' : '') . $host . ':' . $port;
    $sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return array('ok' => false, 'error' => "Could not connect to {$host}:{$port} - [{$errno}] {$errstr}");
    }
    stream_set_timeout($sock, 15);

    $read = function () use ($sock) {
        $r = '';
        while ($l = fgets($sock, 515)) {
            $r .= $l;
            if (isset($l[3]) && $l[3] === ' ') break;
        }
        return $r;
    };
    $cmd = function ($c) use ($sock, &$read) { fwrite($sock, $c . "\r\n"); return $read(); };

    $read(); // server greeting
    $cmd('EHLO localhost');
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $reply = $cmd(base64_encode($pass));
    if (strpos($reply, '235') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'SMTP auth failed: ' . trim($reply));
    }

    $reply = $cmd('MAIL FROM:<' . $user . '>');
    if (strpos($reply, '250') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'MAIL FROM rejected: ' . trim($reply));
    }
    $reply = $cmd('RCPT TO:<' . trim($toEmail) . '>');
    if (strpos($reply, '250') === false && strpos($reply, '251') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'RCPT TO rejected: ' . trim($reply));
    }
    $reply = $cmd('DATA');
    if (strpos($reply, '354') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'DATA rejected: ' . trim($reply));
    }

    $msg = 'Date: ' . date('r') . "\r\n"
        . 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $user . '>' . "\r\n"
        . 'To: =?UTF-8?B?' . base64_encode($toName) . '?= <' . trim($toEmail) . '>' . "\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "\r\n"
        . chunk_split(base64_encode($htmlBody))
        . "\r\n.\r\n";
    fwrite($sock, $msg);
    $reply = $read();
    $cmd('QUIT');
    fclose($sock);

    if (strpos($reply, '250') === false) {
        return array('ok' => false, 'error' => 'Message not accepted: ' . trim($reply));
    }
    return array('ok' => true, 'error' => '');
}

/** True when running on a local dev host - mail is never actually sent while developing/testing. */
function isMamabeLocalEnvironment() {
    $serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
    $httpHost   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

    return in_array($serverName, array('localhost', '127.0.0.1'), true) ||
        strpos($serverName, 'localhost') !== false ||
        strpos($httpHost, 'localhost') !== false ||
        strpos($httpHost, '127.0.0.1') !== false;
}

/**
 * Low-level sender shared by every mamabe notification email. Never throws - returns
 * array('success' => bool, 'message' => string) and logs every attempt.
 */
function dispatchMamabeEmail($toEmail, $toName, $subject, $htmlBody, $altBody, $refId) {
    if (isMamabeLocalEnvironment()) {
        mamabeLogMailOperation('dispatchMamabeEmail', 'Skipped - local environment', array('ref_id' => (int)$refId, 'to' => $toEmail, 'subject' => $subject), 'INFO');
        return array('success' => false, 'message' => 'Mail sending is disabled in the local environment.');
    }

    $cfg = mamabeGetMailConfig();
    if (empty($cfg['host']) || empty($cfg['username']) || empty($cfg['password'])) {
        error_log('Mamabe email skipped: mail is not configured (missing .env/mail_config.local.php values).');
        mamabeLogMailOperation('dispatchMamabeEmail', 'Skipped - mail not configured', array('ref_id' => (int)$refId, 'subject' => $subject), 'ERROR');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        mamabeLogMailOperation('dispatchMamabeEmail', 'Skipped - recipient has no email on file', array('ref_id' => (int)$refId, 'to_name' => $toName, 'subject' => $subject), 'WARNING');
        return array('success' => false, 'message' => 'Recipient has no email on file.');
    }

    $secure = isset($cfg['secure']) ? strtolower((string)$cfg['secure']) : '';
    $useTLS = !in_array($secure, array('', 'none', 'false', '0'), true);

    try {
        $result = _mamabeSmtpSend(
            $cfg['host'], (int)$cfg['port'], $cfg['username'], $cfg['password'],
            !empty($cfg['from_name']) ? $cfg['from_name'] : 'MamaBe',
            $toEmail, $toName, $subject, $htmlBody, $useTLS
        );
    } catch (Throwable $e) {
        error_log('Mamabe email failed (ref_id=' . (int)$refId . '): ' . $e->getMessage());
        mamabeLogMailOperation('dispatchMamabeEmail', 'Failed - exception', array('ref_id' => (int)$refId, 'to' => $toEmail, 'subject' => $subject, 'error' => $e->getMessage()), 'ERROR');
        return array('success' => false, 'message' => $e->getMessage());
    }

    if ($result['ok']) {
        mamabeLogMailOperation('dispatchMamabeEmail', 'Sent', array('ref_id' => (int)$refId, 'to' => $toEmail, 'subject' => $subject), 'INFO');
        return array('success' => true);
    }

    error_log('Mamabe email failed (ref_id=' . (int)$refId . '): ' . $result['error']);
    mamabeLogMailOperation('dispatchMamabeEmail', 'Failed - SMTP error', array('ref_id' => (int)$refId, 'to' => $toEmail, 'subject' => $subject, 'error' => $result['error']), 'ERROR');
    return array('success' => false, 'message' => $result['error']);
}

// ── Editable templates ───────────────────────────────────────────────────────────────────────────────────────
// Subject / header title / message are editable from registration/email_templates.php without touching code -
// stored in mamabe_email_template, keyed by template_key. A missing row (never edited, or a fresh deployment)
// falls back to the hardcoded default below, so sending never breaks even before anyone has opened that admin
// page. The "body" is now a PLAIN TEXT message only (no HTML) - staff who don't know code can't break the email's
// look. The actual visual design (logo shell, borders, the highlighted voucher-code box) stays hardcoded in
// sendMamabeApprovalEmail()/sendMamabeRejectionEmail() below; the editable text is just dropped into it as a
// paragraph. {{name}} is filled in at send time by mamabeFillPlaceholders().

const MAMABE_EMAIL_TEMPLATE_DEFAULTS = array(
    'rejection' => array(
        'subject'      => 'MamaBe Registration - Action Needed',
        'header_title' => 'MamaBe Registration Not Verified',
        'accent_color' => '#c82333',
        'body' => "Hi {{name}},\n\n"
            . "We're sorry, but we were unable to verify your recent MamaBe registration on our website.\n\n"
            . "Please visit your nearest Alpro outlet with your pink book or baby health book, and our staff will help you complete your registration in person.\n\n"
            . "Thank you for your patience.",
    ),
    'approval' => array(
        'subject'      => 'MamaBe Registration Approved!',
        'header_title' => 'MamaBe Registration Approved',
        'accent_color' => '#128C7E',
        'body' => "Hi {{name}},\n\n"
            . "Great news - your MamaBe registration has been verified and approved! Your voucher code is shown below.\n\n"
            . "Thank you for joining MamaBe!",
    ),
    // Sent instead of 'approval' when she already has a PHYSICAL voucher from registering at an outlet (a receipt
    // number on file - see MamabeApproval.php) - verified and approved same as 'approval', just no code to show,
    // since she already has one in hand.
    'approval_physical' => array(
        'subject'      => 'MamaBe Registration Approved!',
        'header_title' => 'MamaBe Registration Approved',
        'accent_color' => '#128C7E',
        'body' => "Hi {{name}},\n\n"
            . "Great news - your MamaBe registration has been verified and approved! Since you already received your voucher at the outlet, no digital code is needed.\n\n"
            . "Thank you for joining MamaBe!",
    ),
);

/** Self-migrating template-override table - same pattern as every other table in this module. */
function mamabeEnsureEmailTemplateTable(mysqli $conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS mamabe_email_template (
        template_key VARCHAR(50) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        header_title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (template_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** The current subject/header_title/body for $key - the DB override if staff saved one, else the hardcoded default. */
function mamabeGetEmailTemplate(mysqli $conn, $key) {
    $default = MAMABE_EMAIL_TEMPLATE_DEFAULTS[$key];
    mamabeEnsureEmailTemplateTable($conn);
    $stmt = $conn->prepare("SELECT subject, header_title, body FROM mamabe_email_template WHERE template_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($subject, $headerTitle, $body);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) return $default;
    return array('subject' => $subject, 'header_title' => $headerTitle, 'accent_color' => $default['accent_color'], 'body' => $body);
}

/** Replaces {{key}} tokens in plain-text $text with $vars['key'] (raw - caller escapes for HTML output if needed). */
function mamabeFillPlaceholders($text, array $vars) {
    foreach ($vars as $key => $value) {
        $text = str_replace('{{' . $key . '}}', (string)$value, $text);
    }
    return $text;
}

/** Turns an admin-edited plain-text message into safe HTML: escape everything, then turn line breaks into <br>. */
function mamabeTextToHtml($text) {
    return nl2br(htmlspecialchars($text));
}

/**
 * Notify a mama that her MamaBe website registration was rejected and she needs to complete it in person.
 * $submissionId is just for the mail log, same role as atem's $atemId.
 */
function sendMamabeRejectionEmail(mysqli $conn, $toEmail, $toName, $submissionId) {
    $tpl      = mamabeGetEmailTemplate($conn, 'rejection');
    $vars     = array('name' => $toName);
    $message  = mamabeFillPlaceholders($tpl['body'], $vars);
    $innerHtml = '<p>' . mamabeTextToHtml($message) . '</p>';

    return dispatchMamabeEmail(
        $toEmail,
        $toName,
        $tpl['subject'],
        mamabeEmailShell($tpl['header_title'], $innerHtml, $tpl['accent_color']),
        $message,
        $submissionId
    );
}

/**
 * Notify a mama that her MamaBe website registration was approved, with her voucher code.
 * $submissionId is just for the mail log, same role as atem's $atemId.
 */
function sendMamabeApprovalEmail(mysqli $conn, $toEmail, $toName, $voucherCode, $submissionId) {
    $tpl     = mamabeGetEmailTemplate($conn, 'approval');
    $vars    = array('name' => $toName, 'voucher_code' => $voucherCode);
    $message = mamabeFillPlaceholders($tpl['body'], $vars);

    // The voucher-code callout is fixed design, not admin-editable - it must always be present and correct.
    $voucherBox = '<div style="background: #e8fff5; border-left: 4px solid ' . $tpl['accent_color'] . '; border-radius: 4px; padding: 14px 16px; margin: 16px 0;">'
        . '<strong>Your voucher code</strong><br><span style="font-size: 20px; font-weight: bold; letter-spacing: 1px;">' . htmlspecialchars($voucherCode) . '</span>'
        . '</div>';
    $innerHtml = '<p>' . mamabeTextToHtml($message) . '</p>' . $voucherBox . mamabeRedeemGuideBlock($conn);

    $altBody = $message . "\n\nYour voucher code: {$voucherCode}";

    return dispatchMamabeEmail(
        $toEmail,
        $toName,
        $tpl['subject'],
        mamabeEmailShell($tpl['header_title'], $innerHtml, $tpl['accent_color']),
        $altBody,
        $submissionId
    );
}

/**
 * Notify a mama that her MamaBe website registration was approved, when she already has a PHYSICAL voucher from
 * registering at an outlet (no digital code to show) - see MamabeApproval.php's $hasPhysicalVoucher case.
 * $submissionId is just for the mail log, same role as atem's $atemId.
 */
function sendMamabePhysicalApprovalEmail(mysqli $conn, $toEmail, $toName, $submissionId) {
    $tpl     = mamabeGetEmailTemplate($conn, 'approval_physical');
    $message = mamabeFillPlaceholders($tpl['body'], array('name' => $toName));
    $innerHtml = '<p>' . mamabeTextToHtml($message) . '</p>' . mamabeRedeemGuideBlock($conn);

    return dispatchMamabeEmail(
        $toEmail,
        $toName,
        $tpl['subject'],
        mamabeEmailShell($tpl['header_title'], $innerHtml, $tpl['accent_color']),
        $message,
        $submissionId
    );
}
