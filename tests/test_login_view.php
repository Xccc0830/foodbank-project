<?php
define('BASE_PATH', dirname(__DIR__));
define('APP_NAME', 'Test');
define('APP_URL', '');
$mode = $argv[1] ?? '';
define('APP_DEBUG', in_array($mode, ['debug', 'debug-clerk'], true));
if (in_array($mode, ['debug-clerk', 'production-clerk'], true)) {
    define('DEMO_CLERK_USERNAME', 'clerk"example');
    define('DEMO_CLERK_PASSWORD', 'example<password>');
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="test">';
}

ob_start();
include BASE_PATH . '/src/views/login.php';
$html = ob_get_clean();

$hasQuickLogin = strpos($html, 'class="login-role-button"') !== false;
$hasRegister = strpos($html, 'action=register') !== false;
$hasReset = strpos($html, 'action=forgot_password') !== false;
if ($hasQuickLogin !== APP_DEBUG || !$hasRegister || !$hasReset
    || (!APP_DEBUG && strpos($html, 'data-password=') !== false)) {
    fwrite(STDERR, "FAIL login view configuration\n");
    exit(1);
}
if (APP_DEBUG) {
    $hasDisabledClerk = strpos($html, '行政人員（尚未設定）') !== false;
    $hasConfiguredClerk = strpos($html, 'data-username="clerk&quot;example" data-password="example&lt;password&gt;"') !== false;
    if (substr_count($html, 'class="login-role-button"') !== 4
        || $hasDisabledClerk !== ($mode === 'debug')
        || $hasConfiguredClerk !== ($mode === 'debug-clerk')) {
        fwrite(STDERR, "FAIL quick login roles\n");
        exit(1);
    }
}
if (!APP_DEBUG && (strpos($html, 'clerk&quot;example') !== false || strpos($html, 'example&lt;password&gt;') !== false)) {
    fwrite(STDERR, "FAIL production exposes test credentials\n");
    exit(1);
}

echo 'PASS login view ' . (APP_DEBUG ? 'debug' : 'production') . PHP_EOL;
