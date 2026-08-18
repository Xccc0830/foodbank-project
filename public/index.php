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

// 啟用會話
session_start();

// 登入與登出流程
$connection = $db->getConnection();
$action = $_GET['action'] ?? '';

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
    $passwordHash = hash('sha256', $password);
    $result = $connection->query("SELECT user_id, username, full_name, role, status FROM users WHERE username = '{$usernameEscaped}' AND password = '{$passwordHash}' LIMIT 1");
    $user = $result ? $result->fetch_assoc() : null;

    if ($user && $user['status'] === 'active') {
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
    $password = $_POST['password'] ?? '';
    $requestedRole = $_POST['role'] ?? 'volunteer';
    $allowedRegistrationRoles = ['manager', 'staff', 'volunteer'];

    if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($requestedRole, $allowedRegistrationRoles, true)) {
        $registrationError = '請完整填寫資料，密碼至少需要 8 個字元。';
    } else {
        $fullNameEscaped = $connection->real_escape_string($fullName);
        $usernameEscaped = $connection->real_escape_string($username);
        $emailEscaped = $connection->real_escape_string($email);
        $roleEscaped = $connection->real_escape_string($requestedRole);
        $passwordHash = hash('sha256', $password);
        $exists = $connection->query("SELECT user_id FROM users WHERE username = '{$usernameEscaped}' OR email = '{$emailEscaped}' LIMIT 1");

        if ($exists && $exists->num_rows > 0) {
            $registrationError = '帳號或電子郵件已經被使用。';
        } else {
            $created = $connection->query("INSERT INTO users (username, password, email, full_name, role, status) VALUES ('{$usernameEscaped}', '{$passwordHash}', '{$emailEscaped}', '{$fullNameEscaped}', '{$roleEscaped}', 'inactive')");
            if ($created) {
                header('Location: ?action=login&registered=1');
                exit;
            }
            $registrationError = '註冊失敗，請稍後再試。';
        }
    }
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
$role = $currentUser['role'];
$roleLabels = [
    'admin' => '系統管理者',
    'manager' => '食物銀行管理者',
    'staff' => '食物銀行工作人員',
    'volunteer' => '平台志工',
];
$rolePages = [
    'admin' => ['dashboard', 'donations', 'deliveries', 'activities', 'inventory', 'beneficiaries', 'purchases', 'settings', 'users'],
    'manager' => ['dashboard', 'donations', 'deliveries', 'activities', 'inventory', 'beneficiaries', 'purchases'],
    'staff' => ['dashboard', 'donations', 'inventory', 'beneficiaries'],
    'volunteer' => ['dashboard', 'deliveries', 'activities'],
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
                    <button class="icon-btn" title="通知">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">3</span>
                    </button>
                    <button class="icon-btn" title="設置">
                        <i class="fas fa-cog"></i>
                    </button>
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
