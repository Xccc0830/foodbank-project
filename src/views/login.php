<?php
$error = $error ?? null;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-panel">
            <div class="login-brand"><i class="fa-solid fa-hand-holding-heart"></i><span><?php echo htmlspecialchars(APP_NAME); ?></span></div>
            <h1>歡迎回來</h1>
            <p class="login-subtitle">登入後依照你的角色進入工作頁面。</p>
            <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post" action="?action=login">
                <div class="form-group"><label for="username">帳號</label><input id="username" name="username" autocomplete="username" required></div>
                <div class="form-group"><label for="password">密碼</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
                <button class="btn btn-primary login-submit" type="submit">登入系統</button>
            </form>
            <p class="login-hint">預設管理員：admin / admin123</p>
        </section>
    </main>
</body>
</html>
