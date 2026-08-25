<?php
/**
 * 食物銀行系統 - 主入口文件
 */

// 啟用錯誤報告（開發環境）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定義基礎路徑
define('BASE_PATH', __DIR__ . '/..');
define('ROOT_PATH', __DIR__);

// 引入配置文件
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/src/helpers/SecurityHelper.php';

// 啟用會話
session_start();
getCsrfToken();

// 登入與登出流程
$connection = $db->getConnection();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('請重新整理頁面後再提交表單。');
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ?action=login');
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $usernameEscaped = $connection->real_escape_string($username);
    $result = $connection->query("SELECT user_id, username, full_name, role, status, password FROM users WHERE username = '{$usernameEscaped}' LIMIT 1");
    $user = $result ? $result->fetch_assoc() : null;
    $passwordValid = $user && ((strlen($user['password']) === 64 && hash_equals($user['password'], hash('sha256', $password))) || password_verify($password, $user['password']));

    if ($passwordValid && $user['status'] === 'active') {
        if (strlen($user['password']) === 64) {
            $secureHash = $connection->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
            $connection->query("UPDATE users SET password = '{$secureHash}' WHERE user_id = " . (int) $user['user_id']);
        }
        unset($user['password']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        header('Location: ?page=dashboard');
        exit;
    }

    $loginError = '帳號、密碼錯誤，或帳號尚未啟用。';
}

if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $requestedRole = $_POST['role'] ?? 'volunteer';
    $allowedRegistrationRoles = ['foodbank_staff', 'volunteer', 'donor'];

    if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^09\d{8}$/', $phone) || strlen($password) < 8 || !in_array($requestedRole, $allowedRegistrationRoles, true)) {
        $registrationError = '請完整填寫資料，電話需為台灣手機號碼格式，密碼至少需要 8 個字元。';
    } else {
        $fullNameEscaped = $connection->real_escape_string($fullName);
        $usernameEscaped = $connection->real_escape_string($username);
        $emailEscaped = $connection->real_escape_string($email);
        $phoneEscaped = $connection->real_escape_string($phone);
        $roleEscaped = $connection->real_escape_string($requestedRole);
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $exists = $connection->query("SELECT user_id FROM users WHERE username = '{$usernameEscaped}' OR email = '{$emailEscaped}' LIMIT 1");

        if ($exists && $exists->num_rows > 0) {
            $registrationError = '帳號或電子郵件已經被使用。';
        } else {
            $connection->query("INSERT INTO users (username, password, email, full_name, phone, role, status) VALUES ('{$usernameEscaped}', '{$passwordHash}', '{$emailEscaped}', '{$fullNameEscaped}', '{$phoneEscaped}', '{$roleEscaped}', 'inactive')");
            $newUserId = (int) $connection->insert_id;

            if ($newUserId > 0) {
                require_once BASE_PATH . '/src/helpers/OtpHelper.php';
                $devOtpCode = generateOtpForUser($connection, $newUserId, $phone, 'phone_verification');
                $_SESSION['pending_verification_user_id'] = $newUserId;
                $_SESSION['dev_otp_code'] = APP_DEBUG ? $devOtpCode : null;
                header('Location: ?action=verify_phone');
                exit;
            }
            $registrationError = '註冊失敗，請稍後再試。';
        }
    }
}

if ($action === 'verify_phone') {
    $pendingUserId = (int) ($_SESSION['pending_verification_user_id'] ?? 0);
    if ($pendingUserId === 0) {
        header('Location: ?action=login');
        exit;
    }

    require_once BASE_PATH . '/src/helpers/OtpHelper.php';
    $verifyError = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = trim($_POST['code'] ?? '');
        if (verifyOtpForUser($connection, $pendingUserId, 'phone_verification', $code)) {
            $connection->query("UPDATE users SET phone_verified = 1 WHERE user_id = {$pendingUserId}");
            unset($_SESSION['pending_verification_user_id'], $_SESSION['dev_otp_code']);

            $roleResult = $connection->query("SELECT role FROM users WHERE user_id = {$pendingUserId} LIMIT 1");
            $verifiedUser = $roleResult ? $roleResult->fetch_assoc() : null;

            if ($verifiedUser && $verifiedUser['role'] === 'volunteer') {
                $_SESSION['consent_pending_user_id'] = $pendingUserId;
                header('Location: ?action=volunteer_consent');
                exit;
            }

            header('Location: ?action=login&registered=1');
            exit;
        }
        $verifyError = '驗證碼錯誤或已過期，請重新索取。';
    }

    $error = $verifyError;
    $devOtpCode = $_SESSION['dev_otp_code'] ?? null;
    include BASE_PATH . '/src/views/verify_phone.php';
    exit;
}

if ($action === 'volunteer_consent') {
    $consentUserId = (int) ($_SESSION['consent_pending_user_id'] ?? 0);
    if ($consentUserId === 0) {
        header('Location: ?action=login');
        exit;
    }

    $consentError = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $agreedDisclaimer = isset($_POST['agreed_disclaimer']);
        $agreedMutualAid = isset($_POST['agreed_mutual_aid']);
        $videoWatched = isset($_POST['video_watched']);

        if ($agreedDisclaimer && $agreedMutualAid && $videoWatched) {
            $connection->query("INSERT INTO volunteer_consents (user_id, agreed_disclaimer, agreed_mutual_aid, video_watched, completed_at) VALUES ({$consentUserId}, 1, 1, 1, NOW()) ON DUPLICATE KEY UPDATE agreed_disclaimer = 1, agreed_mutual_aid = 1, video_watched = 1, completed_at = NOW()");
            unset($_SESSION['consent_pending_user_id']);
            header('Location: ?action=login&registered=1');
            exit;
        }
        $consentError = '請完整觀看影片並勾選所有同意事項後才能送出申請。';
    }

    $error = $consentError;
    include BASE_PATH . '/src/views/volunteer_consent.php';
    exit;
}

if ($action === 'forgot_password') {
    $resetRequestMessage = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $identifier = trim($_POST['identifier'] ?? '');
        $identifierEscaped = $connection->real_escape_string($identifier);
        $result = $connection->query("SELECT user_id, phone FROM users WHERE (username = '{$identifierEscaped}' OR email = '{$identifierEscaped}') AND status = 'active' LIMIT 1");
        $user = $result ? $result->fetch_assoc() : null;

        if ($user && !empty($user['phone'])) {
            require_once BASE_PATH . '/src/helpers/OtpHelper.php';
            $devOtpCode = generateOtpForUser($connection, (int) $user['user_id'], $user['phone'], 'password_reset');
            $_SESSION['password_reset_user_id'] = (int) $user['user_id'];
            $_SESSION['dev_otp_code'] = APP_DEBUG ? $devOtpCode : null;
            header('Location: ?action=reset_password');
            exit;
        }
        $resetRequestMessage = '若帳號存在且已驗證電話，驗證碼將發送至登記門號。';
    }
    $error = $resetRequestMessage;
    include BASE_PATH . '/src/views/forgot_password.php';
    exit;
}

if ($action === 'reset_password') {
    $resetUserId = (int) ($_SESSION['password_reset_user_id'] ?? 0);
    if ($resetUserId === 0) {
        header('Location: ?action=forgot_password');
        exit;
    }

    require_once BASE_PATH . '/src/helpers/OtpHelper.php';
    $resetError = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = trim($_POST['code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $resetError = '新密碼至少需要 8 個字元。';
        } elseif (!verifyOtpForUser($connection, $resetUserId, 'password_reset', $code)) {
            $resetError = '驗證碼錯誤或已過期。';
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $connection->query("UPDATE users SET password = '{$newPasswordHash}' WHERE user_id = {$resetUserId}");
            unset($_SESSION['password_reset_user_id'], $_SESSION['dev_otp_code']);
            header('Location: ?action=login&password_reset=1');
            exit;
        }
    }

    $error = $resetError;
    $devOtpCode = $_SESSION['dev_otp_code'] ?? null;
    include BASE_PATH . '/src/views/reset_password.php';
    exit;
}

if ($action === 'register') {
    $error = $registrationError ?? null;
    include BASE_PATH . '/src/views/register.php';
    exit;
}

if ($action === 'login' || empty($_SESSION['user'])) {
    $error = $loginError ?? null;
    $registered = isset($_GET['registered']);
    include BASE_PATH . '/src/views/login.php';
    exit;
}

$currentUser = $_SESSION['user'];
require_once BASE_PATH . '/src/models/NotificationModel.php';
$notificationModel = new NotificationModel();
$unreadNotificationCount = 0;
if (isset($currentUser['user_id'])) {
    $unreadNotificationCount = (int) $notificationModel->getUnreadCount((int) $currentUser['user_id']);
}
$role = $currentUser['role'];
$roleLabels = [
    'admin' => '系統管理者',
    'foodbank_staff' => '食物銀行官方人員',
    'volunteer' => '平台志工／外送員',
    'donor' => '捐贈剩食店家',
];
$rolePages = [
    'admin' => ['dashboard', 'donations', 'deliveries', 'activities', 'inventory', 'beneficiaries', 'purchases', 'settings', 'users', 'carbon_report', 'reports', 'rewards', 'notifications', 'certificate', 'activity_certificate'],
    'foodbank_staff' => ['dashboard', 'donations', 'deliveries', 'activities', 'inventory', 'beneficiaries', 'purchases', 'carbon_report', 'reports', 'rewards', 'notifications', 'certificate', 'activity_certificate'],
    'volunteer' => ['dashboard', 'deliveries', 'activities', 'rewards', 'notifications', 'certificate', 'activity_certificate'],
    'donor' => ['dashboard', 'donations', 'notifications', 'certificate'],
];

// 簡單的路由系統
$page = isset($_GET['page']) ? trim($_GET['page']) : 'dashboard';

// 防止目錄遍歷
$page = basename($page);

// 菜單項配置
$menu_items = [
    'dashboard' => ['label' => '儀表板', 'icon' => 'fa-solid fa-chart-line'],
    'donations' => ['label' => '捐贈管理', 'icon' => 'fa-solid fa-gift'],
    'deliveries' => ['label' => '配送任務', 'icon' => 'fa-solid fa-route'],
    'activities' => ['label' => '活動認領', 'icon' => 'fa-solid fa-calendar-check'],
    'inventory' => ['label' => '庫存管理', 'icon' => 'fa-solid fa-boxes-stacked'],
    'beneficiaries' => ['label' => '受益者', 'icon' => 'fa-solid fa-users'],
    'purchases' => ['label' => '採購管理', 'icon' => 'fa-solid fa-cart-shopping'],
    'rewards' => ['label' => '點數兌換', 'icon' => 'fa-solid fa-gift'],
    'carbon_report' => ['label' => '減碳報表', 'icon' => 'fa-solid fa-leaf'],
    'reports' => ['label' => '數據分析', 'icon' => 'fa-solid fa-chart-pie'],
    'notifications' => ['label' => '通知中心', 'icon' => 'fa-solid fa-bell'],
    'settings' => ['label' => '設置', 'icon' => 'fa-solid fa-gear'],
    'users' => ['label' => '帳號審核', 'icon' => 'fa-solid fa-user-check'],
];
$allowedPages = $rolePages[$role] ?? ['dashboard'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-wrapper">
        <!-- 側邊欄 -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <span class="logo-icon"><i class="fa-solid fa-hand-holding-heart"></i></span>
                    <span class="logo-text"><?php echo APP_NAME; ?></span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <?php foreach ($menu_items as $key => $item):
                    if (!in_array($key, $allowedPages, true)) {
                        continue;
                    }
                    $is_active = ($page === $key) ? 'active' : '';
                    $icon = $item['icon'];
                    $label = $item['label'];
                    ?>
                    <a href="?page=<?php echo $key; ?>" class="nav-item <?php echo $is_active; ?>" title="<?php echo $label; ?>">
                        <span class="nav-icon"><i class="<?php echo $icon; ?>"></i></span>
                        <span class="nav-label"><?php echo $label; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(substr($currentUser['full_name'], 0, 2))); ?></div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($roleLabels[$role] ?? $role); ?></div>
                    </div>
                </div>
                <a href="?action=logout" class="logout-btn">登出</a>
            </div>
        </aside>

        <!-- 主內容區 -->
        <div class="main-container">
            <!-- 頂部欄 -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="breadcrumb">
                        <span class="page-title" id="pageTitle">
                            <?php echo $menu_items[$page]['label'] ?? '頁面'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="topbar-right">
                    <div class="search-box">
                        <input type="text" placeholder="搜尋紀錄、名稱、編號" class="search-input">
                        <i class="fas fa-search"></i>
                    </div>
                    <a href="?page=notifications" class="icon-btn" title="通知" aria-label="查看通知中心">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <span class="notification-badge"><?php echo (int) $unreadNotificationCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?page=settings" class="icon-btn" title="設置" aria-label="前往設置">
                        <i class="fas fa-cog"></i>
                    </a>
                </div>
            </header>

            <!-- 頁面內容 -->
            <main class="page-content">
                <div class="content-shell">
                    <?php
                    // 根據頁面加載不同的視圖
                    $view_file = BASE_PATH . '/src/views/' . $page . '.php';

                    if (file_exists($view_file)) {
                        include $view_file;
                    } else {
                        ?>
                        <section class="error-container">
                            <div class="error-box">
                                <i class="fas fa-exclamation-circle"></i>
                                <h2>頁面未找到</h2>
                                <p>抱歉，您要訪問的頁面不存在。</p>
                                <a href="?page=dashboard" class="btn btn-primary">返回首頁</a>
                            </div>
                        </section>
                        <?php
                    }
                    ?>
                </div>
            </main>
        </div>
    </div>

    <!-- 頁腳 -->
    <footer class="app-footer">
        <div class="footer-content">
            <p>&copy; 2026 <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
            <div class="footer-links">
                <a href="#">隱私政策</a>
                <a href="#">使用條款</a>
                <a href="#">聯繫我們</a>
            </div>
        </div>
    </footer>

    <script src="assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/assets/js/main.js'); ?>"></script>
</body>
</html>
