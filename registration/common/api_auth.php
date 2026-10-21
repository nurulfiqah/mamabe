<?php
/**
 * Auth for this module's /api endpoints (register.php, outlets.php): username + password of an odb `api_user`
 * (password stored as SHA-256 - same scheme as the other /api endpoints), and the username must be in MAMABE_API_USERS.
 */
function mamabe_api_user_ok(mysqli $conn, $username, $password) {
    $stmt = $conn->prepare("SELECT password FROM api_user WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($hash);
    $found = $stmt->fetch();
    $stmt->close();
    return $found && hash_equals((string)$hash, hash('sha256', (string)$password)) && in_array($username, MAMABE_API_USERS, true);
}
