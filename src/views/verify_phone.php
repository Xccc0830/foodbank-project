<?php
$error = $error ?? null;
$devOtpCode = $devOtpCode ?? null;
$verifyCssPath = BASE_PATH . '/public/assets/css/style.css';
$verifyCssVersion = file_exists($verifyCssPath) ? filemtime($verifyCssPath) : time();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>驗證手機號碼 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(APP_URL . '/assets/css/style.css?v=' . $verifyCssVersion); ?>">
</head>
<body class="login-page">
    <main class="login-shell single-panel">
        <section class="login-panel">
            <div class="login-panel-heading"><p class="eyebrow">PHONE VERIFICATION</p><h2>驗證手機號碼</h2><p class="login-subtitle">請輸入簡訊驗證碼（OTP），確認手機號碼後才會送出審核。</p></div>
            <?php if ($devOtpCode): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>開發環境模擬簡訊：驗證碼為 <strong><?php echo htmlspecialchars($devOtpCode); ?></strong>（正式環境將由簡訊服務商發送）</span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <form method="post" action="?action=verify_phone">
                <?php echo csrfField(); ?>
                <div class="form-group"><label for="code">6 位數驗證碼</label><input id="code" name="code" maxlength="6" pattern="\d{6}" required placeholder="000000"></div>
                <button class="btn btn-primary login-submit" type="submit">確認驗證</button>
            </form>
            <p class="login-hint register-back"><a href="?action=login"><i class="fa-solid fa-arrow-left"></i> 返回登入</a></p>
        </section>
    </main>
</body>
</html>
