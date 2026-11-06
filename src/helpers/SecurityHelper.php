<?php
/**
 * Session-based security helpers
 */

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(?string $token): bool {
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** 站內轉址：附上目前分頁的登入代碼（_t），讓各分頁保有自己的登入狀態。 */
function redirectTo(string $url): void {
    $token = session_id();
    if ($token !== '') {
        $separator = strpos($url, '?') === false ? '?' : '&';
        $url .= $separator . '_t=' . rawurlencode($token);
    }
    header('Location: ' . $url);
    exit;
}
