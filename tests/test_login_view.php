<?php
define('BASE_PATH', dirname(__DIR__));
define('APP_NAME', 'Test');
define('APP_URL', '');
$mode = $argv[1] ?? '';
define('APP_DEBUG', in_array($mode, ['debug', 'debug-clerk'], true));
define('LOCAL_DEMO_LOGIN', in_array($mode, ['local', 'local-remote', 'local-proxy', 'local-external-server', 'local-host'], true));
$_SERVER['REMOTE_ADDR'] = $mode === 'local-remote' ? '192.0.2.1' : '127.0.0.1';
$_SERVER['SERVER_ADDR'] = $mode === 'local-external-server' ? '192.0.2.2' : '127.0.0.1';
$_SERVER['HTTP_HOST'] = $mode === 'local-host' ? 'example.test' : 'localhost:80';
if ($mode === 'local-proxy') {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1';
}
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
$shouldShowQuickLogin = APP_DEBUG || $mode === 'local';
$hasRegister = strpos($html, 'action=register') !== false;
$hasReset = strpos($html, 'action=forgot_password') !== false;
if ($hasQuickLogin !== $shouldShowQuickLogin || !$hasRegister || !$hasReset
    || (!$shouldShowQuickLogin && strpos($html, 'data-password=') !== false)) {
    fwrite(STDERR, "FAIL login view configuration\n");
    exit(1);
}
if ($shouldShowQuickLogin) {
    $hasDisabledClerk = strpos($html, '行政人員（尚未設定）') !== false;
    $hasConfiguredClerk = strpos($html, 'data-username="clerk&quot;example" data-password="example&lt;password&gt;"') !== false;
    if (substr_count($html, 'class="login-role-button"') !== 4
        || $hasDisabledClerk !== ($mode !== 'debug-clerk')
        || $hasConfiguredClerk !== ($mode === 'debug-clerk')) {
        fwrite(STDERR, "FAIL quick login roles\n");
        exit(1);
    }
}
if (!$shouldShowQuickLogin && (strpos($html, 'clerk&quot;example') !== false || strpos($html, 'example&lt;password&gt;') !== false)) {
    fwrite(STDERR, "FAIL production exposes test credentials\n");
    exit(1);
}

echo 'PASS login view ' . (APP_DEBUG ? 'debug' : 'production') . PHP_EOL;
