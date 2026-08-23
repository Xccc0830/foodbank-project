<?php
$error = $error ?? null;
$forgotCssPath = BASE_PATH . '/public/assets/css/style.css';
$forgotCssVersion = file_exists($forgotCssPath) ? filemtime($forgotCssPath) : time();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>忘記密碼 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(APP_URL . '/assets/css/style.css?v=' . $forgotCssVersion); ?>">
</head>
<body class="login-page">
    <main class="login-shell single-panel">
        <section class="login-panel">
            <div class="login-panel-heading"><p class="eyebrow">RESET PASSWORD</p><h2>忘記密碼</h2><p class="login-subtitle">輸入帳號或電子郵件，我們會將驗證碼發送至已驗證的門號。</p></div>
            <?php if ($error): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
            <form method="post" action="?action=forgot_password">
                <?php echo csrfField(); ?>
                <div class="form-group"><label for="identifier">帳號或電子郵件</label><input id="identifier" name="identifier" required placeholder="輸入帳號或電子郵件"></div>
                <button class="btn btn-primary login-submit" type="submit">發送驗證碼</button>
            </form>
            <p class="login-hint register-back"><a href="?action=login"><i class="fa-solid fa-arrow-left"></i> 返回登入</a></p>
        </section>
    </main>
</body>
</html>
