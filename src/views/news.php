<?php
/**
 * 最新消息公告：整合「廣告專區」與「活動發佈資訊區」（公益活動與物資蒐集）。
 */

require_once BASE_PATH . '/src/models/AdvertisementModel.php';
require_once BASE_PATH . '/src/models/ActivityModel.php';
require_once BASE_PATH . '/src/helpers/UploadHelper.php';

$adModel = new AdvertisementModel();
$canPublishAds = $authService->can('ad.publish');
$adMessage = null;
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$currentRole = $currentUser['role'] ?? 'member';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_ad') {
        if (!$canPublishAds) {
            $adMessage = ['type' => 'error', 'text' => '您沒有刊登廣告的權限。'];
        } else {
            $imagePath = null;
            if (!empty($_FILES['ad_image']['name'])) {
                $imagePath = uploadAdImage($_FILES['ad_image']);
            }

            if (!empty($_FILES['ad_image']['name']) && $imagePath === false) {
                $adMessage = ['type' => 'error', 'text' => '廣告圖片需為 JPG、PNG 或 WebP，且不超過 5MB。'];
            } else {
                $created = $adModel->create(
                    $currentUserId,
                    $_POST['title'] ?? '',
                    $_POST['content'] ?? '',
                    $_POST['link_url'] ?? '',
                    $imagePath ?: null,
                    $_POST['start_date'] ?? '',
                    $_POST['end_date'] ?? ''
                );
                $adMessage = $created
                    ? ['type' => 'success', 'text' => '廣告已刊登。']
                    : ['type' => 'error', 'text' => '請填寫標題與內容；連結需以 http:// 或 https:// 開頭，結束日期不可早於開始日期。'];
            }
        }
    } elseif ($postAction === 'remove_ad') {
        $adMessage = $adModel->remove((int) ($_POST['ad_id'] ?? 0), $currentUserId, $currentRole)
            ? ['type' => 'success', 'text' => '廣告已下架。']
            : ['type' => 'error', 'text' => '下架失敗，只有刊登者或食物銀行人員可以下架廣告。'];
    }
}

$ads = $adModel->getActiveAds(30);

// 活動區會處理活動的新增／報名等 POST，先渲染再輸出，讓上方跑馬燈反映最新資料。
$activitiesEmbedded = true;
ob_start();
include BASE_PATH . '/src/views/activities.php';
$activitiesHtml = ob_get_clean();
?>

<div class="view-header">
    <div>
        <h1 class="view-title">最新消息公告</h1>
        <p class="view-subtitle">企業廣告、公益活動與物資募集資訊</p>
    </div>
    <div class="toolbar-actions">
        <a class="btn btn-secondary btn-sm" href="#ads">廣告專區</a>
        <a class="btn btn-secondary btn-sm" href="#activities">活動發佈資訊</a>
    </div>
</div>

<?php include BASE_PATH . '/src/views/partials/marquee.php'; ?>

<section id="ads" class="news-section">
    <h2 class="news-section-title"><i class="fas fa-rectangle-ad"></i> 廣告專區</h2>

    <?php if ($adMessage): ?>
        <div class="alert alert-<?php echo $adMessage['type'] === 'success' ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($adMessage['text']); ?></div>
    <?php endif; ?>

    <?php if ($canPublishAds): ?>
        <div class="card mb-20">
            <div class="card-header"><h2>刊登廣告</h2><p>企業與食物銀行可刊登活動、商品或公益合作廣告</p></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_ad">
                    <div class="form-group"><label>標題*</label><input name="title" maxlength="150" required></div>
                    <div class="form-group"><label>內容*</label><textarea name="content" rows="3" required></textarea></div>
                    <div class="grid-2">
                        <div class="form-group"><label>連結（選填）</label><input type="url" name="link_url" maxlength="255" placeholder="https://"></div>
                        <div class="form-group"><label>圖片（選填）</label><input type="file" name="ad_image" accept="image/png,image/jpeg,image/webp"></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>開始刊登日（選填）</label><input type="date" name="start_date"></div>
                        <div class="form-group"><label>結束日（選填）</label><input type="date" name="end_date"></div>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-bullhorn"></i> 刊登廣告</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($ads): ?>
        <div class="news-ad-grid">
            <?php foreach ($ads as $ad): ?>
                <article class="news-ad-card">
                    <?php if (!empty($ad['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars(APP_URL . '/' . ltrim($ad['image_path'], '/'), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="news-ad-body">
                        <h3><?php echo htmlspecialchars($ad['title']); ?></h3>
                        <p><?php echo nl2br(htmlspecialchars($ad['content'])); ?></p>
                        <small><?php echo htmlspecialchars($ad['advertiser_name'] ?? ''); ?> · <?php echo htmlspecialchars(substr($ad['created_at'], 0, 10)); ?></small>
                        <div class="news-ad-actions">
                            <?php if (!empty($ad['link_url'])): ?><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($ad['link_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">了解更多</a><?php endif; ?>
                            <?php if ($currentRole === 'foodbank_staff' || (int) $ad['advertiser_id'] === $currentUserId): ?>
                                <form method="post" onsubmit="return confirm('確定要下架這則廣告嗎？');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="remove_ad">
                                    <input type="hidden" name="ad_id" value="<?php echo (int) $ad['ad_id']; ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">下架</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><i class="fas fa-rectangle-ad"></i><p>目前沒有刊登中的廣告</p></div>
    <?php endif; ?>
</section>

<section id="activities" class="news-section">
    <h2 class="news-section-title"><i class="fas fa-calendar-check"></i> 活動發佈資訊</h2>
    <?php echo $activitiesHtml; ?>
</section>
