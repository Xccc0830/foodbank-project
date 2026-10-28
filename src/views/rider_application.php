<?php
/**
 * 一般會員申請成為 GO Rider（上傳駕照、完成教育訓練與條款同意，待食物銀行審核）
 */

require_once BASE_PATH . '/src/models/RiderApplicationModel.php';
require_once BASE_PATH . '/src/helpers/UploadHelper.php';

$riderModel = new RiderApplicationModel();
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$applicationMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_rider_application') {
    $consented = isset($_POST['agreed_disclaimer'], $_POST['agreed_mutual_aid'], $_POST['video_watched']);
    $licensePath = !empty($_FILES['license_photo']['name']) ? uploadRiderLicensePhoto($_FILES['license_photo']) : false;

    if (!$consented) {
        $applicationMessage = ['type' => 'error', 'text' => '請完整觀看影片並勾選所有同意事項後才能送出申請。'];
    } elseif ($licensePath === false) {
        $applicationMessage = ['type' => 'error', 'text' => '請上傳駕照照片（JPG、PNG 或 WebP，5MB 以內）。'];
    } else {
        $result = $riderModel->submit($currentUserId, $_POST['vehicle_type'] ?? '', $licensePath);
        $applicationMessage = ['type' => $result['success'] ? 'success' : 'error', 'text' => $result['message']];
        if (!$result['success']) {
            @unlink(BASE_PATH . '/storage/' . $licensePath);
        }
    }
}

$isRider = $riderModel->isRider($currentUserId);
$latestApplication = $riderModel->getLatestForUser($currentUserId);
$isPending = $latestApplication && $latestApplication['status'] === 'pending';
$vehicleLabels = ['motorcycle' => '機車', 'car' => '汽車', 'both' => '機車與汽車'];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">申請 GO Rider</h1>
        <p class="view-subtitle">成為 GO Rider 後，即可在「配送任務」接單協助運送物資</p>
    </div>
</div>

<?php if ($applicationMessage): ?>
    <div class="alert alert-<?php echo $applicationMessage['type'] === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $applicationMessage['type'] === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($applicationMessage['text']); ?>
    </div>
<?php endif; ?>

<?php if ($isRider): ?>
    <div class="card"><div class="card-body">
        <p><i class="fas fa-circle-check"></i> 您已具備 GO Rider 資格，請前往「配送任務」接單。</p>
    </div></div>
<?php elseif ($isPending): ?>
    <div class="card"><div class="card-body">
        <p><i class="fas fa-hourglass-half"></i> 您的申請已於 <?php echo htmlspecialchars($latestApplication['created_at']); ?> 送出（<?php echo htmlspecialchars($vehicleLabels[$latestApplication['vehicle_type']] ?? ''); ?>），正在等待食物銀行審核。</p>
    </div></div>
<?php else: ?>
    <?php if ($latestApplication && $latestApplication['status'] === 'rejected'): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-exclamation"></i>
            上次申請未通過：<?php echo htmlspecialchars($latestApplication['review_notes'] ?? '未提供原因'); ?>。您可以補齊資料後重新申請。
        </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header"><h2>GO Rider 申請</h2><p>駕照照片僅供食物銀行審核人員查看。</p></div>
        <div class="card-body">
            <video id="safetyVideo" class="consent-video" controls preload="metadata">
                <source src="<?php echo htmlspecialchars(APP_URL . '/assets/videos/food-safety-briefing.mp4'); ?>" type="video/mp4">
                您的瀏覽器不支援影片播放。
            </video>
            <p class="login-hint" id="videoHint">請完整觀看 3 分鐘食安運送短片後，才能勾選「已觀看影片」。</p>

            <form method="post" enctype="multipart/form-data" class="consent-checklist">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="submit_rider_application">
                <div class="form-group">
                    <label for="vehicleType">可駕駛的車種*</label>
                    <select id="vehicleType" name="vehicle_type" required>
                        <option value="motorcycle">機車</option>
                        <option value="car">汽車</option>
                        <option value="both">機車與汽車</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="licensePhoto">駕照照片*</label>
                    <input id="licensePhoto" type="file" name="license_photo" accept="image/png,image/jpeg,image/webp" required>
                </div>
                <label><input type="checkbox" name="agreed_disclaimer" value="1" required> 我已閱讀並同意《GO Rider 免責聲明》，了解配送過程中的風險由本人自行承擔一般注意義務。</label>
                <label><input type="checkbox" name="agreed_mutual_aid" value="1" required> 我同意《GO Rider 互助條款》，了解本平台為公益互助性質，不具僱傭關係，僅提供興毅幣作為回饋。</label>
                <label><input type="checkbox" id="videoWatchedCheckbox" name="video_watched" value="1" required disabled> 我已完整觀看食安運送短片。</label>
                <button class="btn btn-primary" type="submit">送出申請</button>
            </form>
        </div>
    </div>
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
<?php endif; ?>
