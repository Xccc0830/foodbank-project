<?php
$error = $error ?? null;
$devOtpCode = $devOtpCode ?? null;
$resetCssPath = BASE_PATH . '/public/assets/css/style.css';
$resetCssVersion = file_exists($resetCssPath) ? filemtime($resetCssPath) : time();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重設密碼 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(APP_URL . '/assets/css/style.css?v=' . $resetCssVersion); ?>">
</head>
<body class="login-page">
    <main class="login-shell single-panel">
        <section class="login-panel">
            <div class="login-panel-heading"><p class="eyebrow">RESET PASSWORD</p><h2>設定新密碼</h2><p class="login-subtitle">輸入已收到的驗證碼與新密碼。</p></div>
            <?php if ($devOtpCode): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>開發環境模擬簡訊：驗證碼為 <strong><?php echo htmlspecialchars($devOtpCode); ?></strong></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <form method="post" action="?action=reset_password">
                <?php echo csrfField(); ?>
                <div class="form-group"><label for="code">驗證碼</label><input id="code" name="code" maxlength="6" pattern="\d{6}" required placeholder="000000"></div>
                <div class="form-group"><label for="new_password">新密碼</label><input id="new_password" name="new_password" type="password" minlength="8" required placeholder="至少 8 個字元"></div>
                <button class="btn btn-primary login-submit" type="submit">重設密碼</button>
            </form>
            <p class="login-hint register-back"><a href="?action=login"><i class="fa-solid fa-arrow-left"></i> 返回登入</a></p>
        </section>
    </main>
</body>
</html>
