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

date_default_timezone_set('Asia/Taipei');

// 啟用會話
session_start();
getCsrfToken();

// 登入與登出流程
$connection = $db->getConnection();
$action = $_GET['action'] ?? '';

// 修復舊版初始化資料曾以錯誤編碼寫入的示範帳號資料。
$demoPasswordHashes = [
    'manager' => '866485796cfa8d7c0cf7111640205b83076433547577511d81f8030ae99ecea5',
    'staff' => '10176e7b7b24d317acfcf8d2064cfd2f24e154f7b5a96603077d5ef813d6a6b6',
];
foreach ($demoPasswordHashes as $demoUsername => $demoPasswordHash) {
    $demoUsernameEscaped = $connection->real_escape_string($demoUsername);
    $connection->query(
        "UPDATE users SET password = '{$demoPasswordHash}' WHERE username = '{$demoUsernameEscaped}' AND CHAR_LENGTH(password) <> 60 AND CHAR_LENGTH(password) <> 64"
    );
}
$connection->query(
    "UPDATE users SET full_name = CASE username
        WHEN 'official' THEN '食物銀行官方人員'
        WHEN 'manager' THEN '忠信食物銀行'
        WHEN 'staff' THEN '食物銀行官方人員'
        WHEN 'volunteer' THEN '忠信GO RIDER'
        WHEN 'donor' THEN '捐贈剩食店家'
        ELSE full_name
    END
    WHERE username IN ('official', 'manager', 'staff', 'volunteer', 'donor')
      AND (full_name LIKE '%?%' OR full_name = '')"
);
if (!empty($_SESSION['user']['username'])) {
    $sessionUsername = $connection->real_escape_string($_SESSION['user']['username']);
    $sessionUserResult = $connection->query("SELECT user_id, username, full_name, role, member_type, enterprise_name, status, email, phone, phone_verified FROM users WHERE username = '{$sessionUsername}' LIMIT 1");
    if ($sessionUserResult && ($sessionUser = $sessionUserResult->fetch_assoc())) {
        $_SESSION['user'] = $sessionUser;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    if ($action === 'delivery_location') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '登入狀態已逾時，請重新登入後再分享位置。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    $result = $connection->query("SELECT user_id, username, full_name, role, member_type, enterprise_name, status, password FROM users WHERE username = '{$usernameEscaped}' LIMIT 1");
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
    $memberType = $_POST['member_type'] ?? '';
    $enterpriseName = trim($_POST['enterprise_name'] ?? '');
    $allowedMemberTypes = ['general', 'enterprise'];

    if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^09\d{8}$/', $phone) || strlen($password) < 8 || !in_array($memberType, $allowedMemberTypes, true) || ($memberType === 'enterprise' && $enterpriseName === '')) {
        $registrationError = '請完整填寫資料；企業會員須填寫企業／組織名稱，電話需為台灣手機號碼格式，密碼至少需要 8 個字元。';
    } else {
        $fullNameEscaped = $connection->real_escape_string($fullName);
        $usernameEscaped = $connection->real_escape_string($username);
        $emailEscaped = $connection->real_escape_string($email);
        $phoneEscaped = $connection->real_escape_string($phone);
        $memberTypeEscaped = $connection->real_escape_string($memberType);
        $enterpriseNameEscaped = $connection->real_escape_string($enterpriseName);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $exists = $connection->query("SELECT user_id FROM users WHERE username = '{$usernameEscaped}' OR email = '{$emailEscaped}' LIMIT 1");

        if ($exists && $exists->num_rows > 0) {
            $registrationError = '帳號或電子郵件已經被使用。';
        } else {
            $connection->query("INSERT INTO users (username, password, email, full_name, phone, role, member_type, enterprise_name, status) VALUES ('{$usernameEscaped}', '{$passwordHash}', '{$emailEscaped}', '{$fullNameEscaped}', '{$phoneEscaped}', 'member', '{$memberTypeEscaped}', " . ($memberType === 'enterprise' ? "'{$enterpriseNameEscaped}'" : 'NULL') . ", 'inactive')");
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

            $memberResult = $connection->query("SELECT role, member_type FROM users WHERE user_id = {$pendingUserId} LIMIT 1");
            $verifiedUser = $memberResult ? $memberResult->fetch_assoc() : null;

            if ($verifiedUser && $verifiedUser['role'] === 'member' && $verifiedUser['member_type'] === 'general') {
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

if (in_array($action, ['delivery_tracking', 'delivery_location'], true) && empty($_SESSION['user'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '登入狀態已逾時，請重新登入後查看配送資訊。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'calculate_distance' && empty($_SESSION['user'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '登入狀態已逾時，請重新登入後再計算距離。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'login' || empty($_SESSION['user'])) {
    $error = $loginError ?? null;
    $registered = isset($_GET['registered']);
    include BASE_PATH . '/src/views/login.php';
    exit;
}

$currentUser = $_SESSION['user'];
require_once BASE_PATH . '/src/services/AuthService.php';
$authService = AuthService::forCurrentUser($currentUser);

if (in_array($action, ['delivery_tracking', 'delivery_location'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    require_once BASE_PATH . '/src/models/DeliveryModel.php';
    $deliveryModel = new DeliveryModel();
    $currentUserId = (int) ($currentUser['user_id'] ?? 0);
    $currentRole = $currentUser['role'] ?? 'member';

    if ($action === 'delivery_tracking' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $deliveryId = filter_input(INPUT_GET, 'delivery_id', FILTER_VALIDATE_INT);
        if (!$deliveryId || $deliveryId < 1) {
            http_response_code(400);
            echo json_encode(['error' => '配送任務編號無效。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tracking = $deliveryModel->getLiveTracking($deliveryId, $currentUserId, $currentRole);
        if ($tracking === false) {
            http_response_code(500);
            echo json_encode(['error' => '目前無法取得配送位置。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($tracking === null) {
            http_response_code(404);
            echo json_encode(['error' => '找不到配送任務或您無權查看。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode($tracking, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($action === 'delivery_location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $deliveryId = filter_var($_POST['delivery_id'] ?? null, FILTER_VALIDATE_INT);
        $operation = $_POST['operation'] ?? '';
        if (!$deliveryId || $deliveryId < 1) {
            http_response_code(400);
            echo json_encode(['error' => '配送任務編號無效。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($operation === 'stop') {
            if (!$authService->can('rider.accept_task')) {
                http_response_code(403);
                echo json_encode(['error' => '只有接單的配送會員可以停止分享位置。'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!$deliveryModel->stopLiveLocation($deliveryId, $currentUserId)) {
                http_response_code(500);
                echo json_encode(['error' => '停止分享位置失敗。'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;
        $accuracy = $_POST['accuracy'] ?? null;
        if ($operation !== 'update' || !$authService->can('rider.accept_task')) {
            http_response_code(403);
            echo json_encode(['error' => '只有接單的配送會員可以分享位置。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!$deliveryModel->updateLiveLocation($deliveryId, $currentUserId, $latitude, $longitude, $accuracy)) {
            http_response_code(400);
            echo json_encode(['error' => '位置更新失敗；請確認任務已取貨且座標有效。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    header('Allow: ' . ($action === 'delivery_tracking' ? 'GET' : 'POST'));
    echo json_encode(['error' => '不支援的請求方式。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'calculate_distance') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['error' => '不支援的請求方式。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once BASE_PATH . '/src/helpers/DistanceHelper.php';
    $origin = trim((string) ($_GET['origin'] ?? ''));
    $destination = trim((string) ($_GET['destination'] ?? ''));
    if ($origin === '' || $destination === '') {
        http_response_code(400);
        echo json_encode(['error' => '請先輸入取貨地址與送達地址。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = calculateAddressDistance($origin, $destination);
    if (isset($result['error'])) {
        http_response_code(422);
        echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'distance_km' => $result['distance_km'],
        'duration_minutes' => $result['duration_minutes'],
        'distance_text' => formatDistanceText($result['distance_km']),
        'duration_text' => formatDurationText($result['duration_minutes']),
        'approximate' => !empty($result['approximate']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once BASE_PATH . '/src/models/NotificationModel.php';
$notificationModel = new NotificationModel();
$unreadNotificationCount = 0;
if (isset($currentUser['user_id'])) {
    $unreadNotificationCount = (int) $notificationModel->getUnreadCount((int) $currentUser['user_id']);
}
$role = $currentUser['role'];
$memberType = $currentUser['member_type'] ?? null;
$roleLabels = [
    'foodbank_staff' => '忠信食物銀行',
    'member' => $memberType === 'enterprise' ? '企業會員' : ($memberType === 'general' ? '一般會員' : '會員'),
];

// 簡單的路由系統
$page = isset($_GET['page']) ? trim($_GET['page']) : 'dashboard';

// 防止目錄遍歷
$page = basename($page);

// 菜單項配置
$menu_items = [
    'dashboard' => ['label' => '儀表板', 'icon' => 'fa-solid fa-chart-line'],
    'order_tracking' => ['label' => '訂單追蹤', 'icon' => 'fa-solid fa-list-check'],
    'deliveries' => ['label' => '配送任務', 'icon' => 'fa-solid fa-route'],
    'material_transport' => ['label' => '物資運送', 'icon' => 'fa-solid fa-truck-fast'],
    'activities' => ['label' => $role === 'member' ? '活動報名' : '活動發布', 'icon' => 'fa-solid fa-calendar-check'],
    'item_categories' => ['label' => '物資分類', 'icon' => 'fa-solid fa-layer-group'],
    'rewards' => ['label' => '興毅幣兌換', 'icon' => 'fa-solid fa-gift'],
    'carbon_report' => ['label' => '永續報告', 'icon' => 'fa-solid fa-leaf'],
    'reports' => ['label' => '數據分析', 'icon' => 'fa-solid fa-chart-pie'],
    'notifications' => ['label' => '通知中心', 'icon' => 'fa-solid fa-bell'],
    'donation_materials' => ['label' => '物資捐贈', 'icon' => 'fa-solid fa-box-open'],
    'donation_materials_review' => ['label' => '物資捐贈審查', 'icon' => 'fa-solid fa-clipboard-check'],
    'settings' => ['label' => '設置', 'icon' => 'fa-solid fa-gear'],
    'users' => ['label' => '帳號審核', 'icon' => 'fa-solid fa-user-check'],
    'volunteer_management' => ['label' => '一般會員管理', 'icon' => 'fa-solid fa-people-group'],
];

// 頁面存取控制：以 Permission (page.<slug>) 為準，不再使用寫死的角色判斷。
// certificate / activity_certificate 未列在側邊選單，但仍需納入頁面白名單。
$routablePages = array_merge(array_keys($menu_items), ['certificate', 'activity_certificate']);
$allowedPages = array_values(array_filter($routablePages, function ($slug) use ($authService) {
    return $authService->can('page.' . $slug);
}));
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}
ob_start();
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
                    $label = ($key === 'reports' && $role === 'member') ? '榮譽榜' : $item['label'];
                    ?>
                    <a href="?page=<?php echo $key; ?>" class="nav-item <?php echo $is_active; ?>" title="<?php echo $label; ?>">
                        <span class="nav-icon"><i class="<?php echo $icon; ?>"></i></span>
                        <span class="nav-label"><?php echo $label; ?></span>
                        <?php if ($key === 'notifications' && $unreadNotificationCount > 0): ?>
                            <span class="notification-badge sidebar-notification-badge"><?php echo (int) $unreadNotificationCount; ?></span>
                        <?php endif; ?>
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
                    if ($page === 'volunteer_management') {
                        $view_file = BASE_PATH . '/src/views/users.php';
                    }

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
