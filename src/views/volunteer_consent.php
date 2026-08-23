<?php
$error = $error ?? null;
$consentCssPath = BASE_PATH . '/public/assets/css/style.css';
$consentCssVersion = file_exists($consentCssPath) ? filemtime($consentCssPath) : time();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>志工教育訓練 | <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(APP_URL . '/assets/css/style.css?v=' . $consentCssVersion); ?>">
</head>
<body class="login-page">
    <main class="login-shell single-panel">
        <section class="login-panel">
            <div class="login-panel-heading"><p class="eyebrow">VOLUNTEER ONBOARDING</p><h2>志工教育訓練與條款同意</h2><p class="login-subtitle">開通志工帳號前，請觀看食安運送短片並同意以下條款。</p></div>
            <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <video id="safetyVideo" class="consent-video" controls preload="metadata">
                <source src="<?php echo htmlspecialchars(APP_URL . '/assets/videos/food-safety-briefing.mp4'); ?>" type="video/mp4">
                您的瀏覽器不支援影片播放。
            </video>
            <p class="login-hint" id="videoHint">請完整觀看 3 分鐘食安運送短片後，才能勾選「已觀看影片」。</p>

            <form method="post" action="?action=volunteer_consent" class="consent-checklist">
                <?php echo csrfField(); ?>
                <label><input type="checkbox" name="agreed_disclaimer" value="1" required> 我已閱讀並同意《志工免責聲明》，了解配送過程中的風險由本人自行承擔一般注意義務。</label>
                <label><input type="checkbox" name="agreed_mutual_aid" value="1" required> 我同意《純志工互助條款》，了解本平台為公益互助性質，不具僱傭關係，僅提供公益點數作為回饋。</label>
                <label><input type="checkbox" id="videoWatchedCheckbox" name="video_watched" value="1" required disabled> 我已完整觀看食安運送短片。</label>
                <button class="btn btn-primary login-submit" type="submit">完成教育訓練並送出申請</button>
            </form>
        </section>
    </main>
    <script>
        (function () {
            var video = document.getElementById('safetyVideo');
            var checkbox = document.getElementById('videoWatchedCheckbox');
            var hint = document.getElementById('videoHint');

            video.addEventListener('ended', function () {
                checkbox.disabled = false;
                hint.textContent = '感謝觀看，請勾選同意事項並送出申請。';
            });

            video.addEventListener('error', function () {
                checkbox.disabled = false;
                hint.textContent = '（開發環境：影片檔案尚未上傳，暫時開放勾選，正式環境請上傳 3 分鐘食安運送短片）';
            });
        })();
    </script>
</body>
</html>
