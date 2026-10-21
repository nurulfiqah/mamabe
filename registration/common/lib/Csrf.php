<?php
/**
 * Session-wide CSRF token for mamabe's admin POST forms - one token per session, so several open tabs or the back
 * button never invalidate it. Every POST handler calls verify() first; every <form method="post"> renders field().
 */
class MamabeCsrf {
    private static function token() {
        if (empty($_SESSION['mamabe_csrf_token'])) {
            $_SESSION['mamabe_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['mamabe_csrf_token'];
    }

    public static function field() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function verify() {
        $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            http_response_code(403);
            exit('Session expired or invalid request - please refresh the page and try again.');
        }
    }
}
