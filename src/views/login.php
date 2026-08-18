<?php
$error = $error ?? null;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-showcase">
            <div class="login-brand"><span class="login-brand-mark"><i class="fa-solid fa-hand-holding-heart"></i></span><span><?php echo htmlspecialchars(APP_NAME); ?></span></div>
            <div class="showcase-copy">
                <p class="eyebrow">FOODBANK PLATFORM / 2026</p>
                <h1>把每一份惜食，<em>送到需要的地方。</em></h1>
                <p>從商家上架、食物銀行評估，到志工配送與公益點數，讓每一步都清楚、可靠、可追蹤。</p>
            </div>
            <div class="showcase-features">
                <div><span><i class="fa-solid fa-box-open"></i></span><strong>物資媒合</strong><small>掌握每批物資的期限與狀態</small></div>
                <div><span><i class="fa-solid fa-route"></i></span><strong>公益配送</strong><small>讓志工自由接取配送任務</small></div>
                <div><span><i class="fa-solid fa-chart-line"></i></span><strong>社會成效</strong><small>累積點數與可量化的影響力</small></div>
            </div>
            <div class="showcase-orbit orbit-one"></div><div class="showcase-orbit orbit-two"></div>
        </section>
        <section class="login-panel">
            <div class="login-panel-heading"><p class="eyebrow">SECURE ACCESS</p><h2>登入工作平台</h2><p class="login-subtitle">使用你的帳號進入專屬工作頁面。</p></div>
            <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <form method="post" action="?action=login">
                <div class="form-group"><label for="username">帳號</label><div class="input-with-icon"><i class="fa-regular fa-user"></i><input id="username" name="username" autocomplete="username" placeholder="輸入你的帳號" required></div></div>
                <div class="form-group"><label for="password">密碼</label><div class="input-with-icon"><i class="fa-solid fa-lock"></i><input id="password" name="password" type="password" autocomplete="current-password" placeholder="輸入你的密碼" required></div></div>
                <button class="btn btn-primary login-submit" type="submit">登入系統 <i class="fa-solid fa-arrow-right"></i></button>
            </form>
            <div class="login-divider"><span>角色入口</span></div>
            <div class="login-roles"><span>管理者</span><span>工作人員</span><span>志工</span></div>
            <p class="login-hint">開發測試帳號：admin / admin123</p>
        </section>
    </main>
</body>
</html>
