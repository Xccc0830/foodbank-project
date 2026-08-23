<?php
$error = $error ?? null;
$success = $success ?? null;
$registerCssPath = BASE_PATH . '/public/assets/css/style.css';
$registerCssVersion = file_exists($registerCssPath) ? filemtime($registerCssPath) : time();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申請帳號 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(APP_URL . '/assets/css/style.css?v=' . $registerCssVersion); ?>">
</head>
<body class="login-page">
    <main class="login-shell register-shell">
        <section class="login-showcase">
            <div class="login-brand"><span class="login-brand-mark"><i class="fa-solid fa-hand-holding-heart"></i></span><span><?php echo htmlspecialchars(APP_NAME); ?></span></div>
            <div class="showcase-copy"><p class="eyebrow">JOIN THE MOVEMENT</p><h1>一起讓惜食，<em>流向更好的地方。</em></h1><p>成為平台夥伴，參與物資媒合、公益配送與社區活動。</p></div>
            <div class="showcase-features"><div><span><i class="fa-solid fa-user-check"></i></span><strong>安全審核</strong><small>每個帳號都經過角色確認</small></div><div><span><i class="fa-solid fa-people-group"></i></span><strong>一起協作</strong><small>和食物銀行與志工合作</small></div><div><span><i class="fa-solid fa-award"></i></span><strong>累積貢獻</strong><small>記錄你的公益參與</small></div></div>
        </section>
        <section class="login-panel">
            <div class="login-panel-heading"><p class="eyebrow">CREATE ACCOUNT</p><h2>申請平台帳號</h2><p class="login-subtitle">送出後由系統管理者審核開通。</p></div>
            <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <form method="post" action="?action=register">
                <?php echo csrfField(); ?>
                <div class="form-group"><label for="full_name">姓名</label><div class="input-with-icon"><i class="fa-regular fa-id-card"></i><input id="full_name" name="full_name" required placeholder="輸入姓名"></div></div>
                <div class="form-group"><label for="username">帳號</label><div class="input-with-icon"><i class="fa-regular fa-user"></i><input id="username" name="username" required autocomplete="username" placeholder="設定登入帳號"></div></div>
                <div class="form-group"><label for="email">電子郵件</label><div class="input-with-icon"><i class="fa-regular fa-envelope"></i><input id="email" name="email" type="email" required placeholder="name@example.com"></div></div>
                <div class="form-group"><label for="phone">手機號碼</label><div class="input-with-icon"><i class="fa-solid fa-mobile-screen"></i><input id="phone" name="phone" type="tel" pattern="09\d{8}" required placeholder="09xxxxxxxx"></div></div>
                <div class="form-group"><label for="role">申請角色</label><select id="role" name="role" required><option value="foodbank_staff">食物銀行官方人員</option><option value="volunteer">平台志工／外送員</option><option value="donor">捐贈剩食店家</option></select></div>
                <div class="form-group"><label for="password">設定密碼</label><div class="input-with-icon"><i class="fa-solid fa-lock"></i><input id="password" name="password" type="password" minlength="8" required placeholder="至少 8 個字元"></div></div>
                <button class="btn btn-primary login-submit" type="submit">送出申請 <i class="fa-solid fa-arrow-right"></i></button>
            </form>
            <p class="login-hint register-back"><a href="?action=login"><i class="fa-solid fa-arrow-left"></i> 返回登入</a></p>
        </section>
    </main>
</body>
</html>
