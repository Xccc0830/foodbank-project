<?php
define('BASE_PATH', dirname(__DIR__));
define('APP_NAME', 'Test');
define('APP_URL', '');
define('APP_DEBUG', ($argv[1] ?? '') === 'debug');

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

echo 'PASS login view ' . (APP_DEBUG ? 'debug' : 'production') . PHP_EOL;
