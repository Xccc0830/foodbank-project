<?php
/**
 * 最新消息公告：廣告專區（公益活動已獨立為「公益活動」頁面）。
 */

require_once BASE_PATH . '/src/models/AdvertisementModel.php';
require_once BASE_PATH . '/src/models/ActivityModel.php';
require_once BASE_PATH . '/src/helpers/UploadHelper.php';
require_once BASE_PATH . '/src/helpers/AdPolicyHelper.php';

$adModel = new AdvertisementModel();
$canPublishAds = $authService->can('ad.publish');
$adMessage = null;
$adFormInput = [];
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
                    $_POST['end_date'] ?? '',
                    $currentRole === 'foodbank_staff',
                    !empty($_POST['agree_policy'])
                );
                if ($created['success']) {
                    $adMessage = ['type' => 'success', 'text' => ($currentRole === 'foodbank_staff' ? '廣告已刊登。' : '廣告已送出，食物銀行審核通過後才會刊登。')
                        . ($created['warnings'] ? ' 請留意：' . implode('；', $created['warnings']) . '。' : '')];
                } else {
                    $adMessage = ['type' => 'error', 'text' => $created['message']];
                    $adFormInput = $_POST; // 保留剛填的內容，方便修改
                }
            }
        }
    } elseif ($postAction === 'approve_ad' && $currentRole === 'foodbank_staff') {
        $adMessage = $adModel->approve((int) ($_POST['ad_id'] ?? 0), $currentUserId, $_POST['fee_amount'] ?? null)
            ? ['type' => 'success', 'text' => '廣告已核准並上架。']
            : ['type' => 'error', 'text' => '核准失敗，該申請可能已被處理。'];
    } elseif ($postAction === 'reject_ad' && $currentRole === 'foodbank_staff') {
        $rejectReason = trim(implode('；', array_filter(array_merge(
            array_values(array_intersect(AdPolicyHelper::REJECT_PRESETS, (array) ($_POST['reject_presets'] ?? []))),
            [trim((string) ($_POST['reject_reason'] ?? ''))]
        ))));
        $adMessage = $adModel->reject((int) ($_POST['ad_id'] ?? 0), $currentUserId, $rejectReason)
            ? ['type' => 'success', 'text' => '已退回廣告申請。']
            : ['type' => 'error', 'text' => '退回時必須填寫原因，該申請也可能已被處理。'];
    } elseif ($postAction === 'remove_ad') {
        $adMessage = $adModel->remove((int) ($_POST['ad_id'] ?? 0), $currentUserId, $currentRole)
            ? ['type' => 'success', 'text' => '廣告已下架。']
            : ['type' => 'error', 'text' => '下架失敗，只有刊登者或食物銀行人員可以下架廣告。'];
    }
}

$ads = $adModel->getActiveAds(30);
$pendingAds = $currentRole === 'foodbank_staff' ? $adModel->listPending() : [];
$ownUnpublishedAds = $currentRole === 'foodbank_staff' ? [] : $adModel->listOwnUnpublished($currentUserId);
?>

<div class="view-header">
    <div>
        <h1 class="view-title">最新消息公告</h1>
        <p class="view-subtitle">企業廣告與公益合作資訊</p>
    </div>
    <div class="toolbar-actions">
        <a class="btn btn-secondary btn-sm" href="#ads">廣告專區</a>
        <button type="button" class="btn btn-secondary btn-sm" data-modal-open="adPolicyModal"><i class="fas fa-scale-balanced"></i> 廣告刊登規範</button>
        <a class="btn btn-secondary btn-sm" href="?page=activities">公益活動</a>
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
                    <?php $adValue = static function ($key) use ($adFormInput) { return htmlspecialchars((string) ($adFormInput[$key] ?? ''), ENT_QUOTES, 'UTF-8'); }; ?>
                    <div class="form-group"><label>標題*（<?php echo AdPolicyHelper::TITLE_MAX; ?> 字內）</label><input name="title" maxlength="<?php echo AdPolicyHelper::TITLE_MAX; ?>" required value="<?php echo $adValue('title'); ?>"></div>
                    <div class="form-group"><label>內容*（<?php echo AdPolicyHelper::CONTENT_MIN; ?>–<?php echo AdPolicyHelper::CONTENT_MAX; ?> 字）</label><textarea name="content" rows="3" required minlength="<?php echo AdPolicyHelper::CONTENT_MIN; ?>" maxlength="<?php echo AdPolicyHelper::CONTENT_MAX; ?>"><?php echo $adValue('content'); ?></textarea>
                        <p class="form-hint">食品不得宣稱療效；酒類須加註「禁止酒駕」與「飲酒過量，有害健康」；募款須寫勸募許可字號。</p></div>
                    <div class="grid-2">
                        <div class="form-group"><label>連結（選填，須 https://）</label><input type="url" name="link_url" maxlength="255" placeholder="https://" pattern="https://.+" value="<?php echo $adValue('link_url'); ?>"></div>
                        <div class="form-group"><label>圖片（選填，JPG／PNG／WebP，5MB 內）</label><input type="file" name="ad_image" accept="image/png,image/jpeg,image/webp"></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>開始刊登日（選填，預設今天）</label><input type="date" name="start_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo $adValue('start_date'); ?>"></div>
                        <div class="form-group"><label>結束日（選填，最長 <?php echo AdPolicyHelper::MAX_DAYS; ?> 天；不填自動刊登 <?php echo AdPolicyHelper::DEFAULT_DAYS; ?> 天）</label><input type="date" name="end_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo $adValue('end_date'); ?>"></div>
                    </div>
                    <div class="form-group form-check-row"><label><input type="checkbox" name="agree_policy" value="1" required> 我已閱讀並同意 <button type="button" class="link-button" data-modal-open="adPolicyModal">廣告刊登規範</button>，並保證內容真實、圖片已取得授權。</label></div>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-bullhorn"></i> 刊登廣告</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($pendingAds): ?>
        <div class="card mb-20">
            <div class="card-header"><h2>待審核廣告</h2><p><?php echo count($pendingAds); ?> 筆申請</p></div>
            <div class="card-body">
                <?php foreach ($pendingAds as $pendingAd): ?>
                    <div class="ad-review-item">
                        <strong><?php echo htmlspecialchars($pendingAd['title']); ?></strong>
                        <small> · <?php echo htmlspecialchars($pendingAd['advertiser_name'] ?? ''); ?> · 檔期 <?php echo htmlspecialchars(($pendingAd['start_date'] ?: '即日') . ' ~ ' . ($pendingAd['end_date'] ?: '不限')); ?></small>
                        <p><?php echo nl2br(htmlspecialchars($pendingAd['content'])); ?></p>
                        <?php if (!empty($pendingAd['link_url'])): ?><p class="form-hint">連結：<?php echo htmlspecialchars($pendingAd['link_url']); ?></p><?php endif; ?>
                        <?php if (!empty($pendingAd['image_path'])): ?><img class="ad-review-image" src="<?php echo htmlspecialchars(storedPublicImageUrl($pendingAd['image_path']), ENT_QUOTES, 'UTF-8'); ?>" alt="廣告圖片"><?php endif; ?>
                        <?php $policyFlags = json_decode((string) ($pendingAd['policy_flags'] ?? ''), true); ?>
                        <?php if ($policyFlags): ?>
                            <div class="ad-policy-flags"><strong><i class="fas fa-triangle-exclamation"></i> 系統提醒審核注意</strong><ul><?php foreach ($policyFlags as $flag): ?><li><?php echo htmlspecialchars($flag); ?></li><?php endforeach; ?></ul></div>
                        <?php endif; ?>
                        <form method="post" class="delivery-action-form ad-review-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="ad_id" value="<?php echo (int) $pendingAd['ad_id']; ?>">
                            <details class="ad-reject-presets"><summary>退回原因快捷選項</summary>
                                <?php foreach (AdPolicyHelper::REJECT_PRESETS as $preset): ?>
                                    <label><input type="checkbox" name="reject_presets[]" value="<?php echo htmlspecialchars($preset, ENT_QUOTES); ?>"> <?php echo htmlspecialchars($preset); ?></label>
                                <?php endforeach; ?>
                            </details>
                            <input type="number" name="fee_amount" min="0" step="1" placeholder="刊登費用（選填）">
                            <input name="reject_reason" placeholder="其他退回原因（退回時快捷選項或這裡擇一必填）">
                            <button class="btn btn-primary btn-sm" type="submit" name="action" value="approve_ad">核准上架</button>
                            <button class="btn btn-danger btn-sm" type="submit" name="action" value="reject_ad">退回</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($ownUnpublishedAds): ?>
        <div class="card mb-20">
            <div class="card-header"><h2>我的廣告申請</h2></div>
            <div class="card-body">
                <?php foreach ($ownUnpublishedAds as $ownAd): ?>
                    <p>
                        <strong><?php echo htmlspecialchars($ownAd['title']); ?></strong>
                        <?php if ($ownAd['status'] === 'pending'): ?>
                            <span class="status status-pending">審核中</span>
                        <?php else: ?>
                            <span class="status status-suspended">未通過</span> <?php echo htmlspecialchars($ownAd['reject_reason'] ?? ''); ?>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($ads): ?>
        <div class="news-ad-grid">
            <?php foreach ($ads as $ad): ?>
                <article class="news-ad-card">
                    <?php if (!empty($ad['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars(storedPublicImageUrl($ad['image_path']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8'); ?>">
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

<div class="app-modal-overlay" id="adPolicyModal" role="dialog" aria-modal="true" aria-labelledby="adPolicyTitle" hidden>
    <div class="app-modal app-modal-wide">
        <div class="app-modal-header">
            <div><h2 id="adPolicyTitle">廣告刊登規範</h2><p>刊登前請詳閱；違反規範的廣告會被退回或下架</p></div>
            <button type="button" class="icon-btn" data-modal-close aria-label="關閉"><i class="fas fa-times"></i></button>
        </div>
        <div class="app-modal-body">
            <ol class="ad-policy-list">
                <?php foreach (AdPolicyHelper::RULES as $rule): ?>
                    <li><strong><?php echo htmlspecialchars($rule[0]); ?></strong><p><?php echo htmlspecialchars($rule[1]); ?></p><small>依據：<?php echo htmlspecialchars($rule[2]); ?></small></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</div>

<style>
    .ad-policy-list { margin: 0; padding-left: 22px; display: grid; gap: 12px; }
    .ad-policy-list p { margin: 2px 0; color: #334155; }
    .ad-policy-list small { color: #64748b; }
    .ad-policy-flags { margin: 8px 0; padding: 10px 14px; border-left: 4px solid #f59e0b; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 13px; }
    .ad-policy-flags ul { margin: 4px 0 0; padding-left: 18px; }
    .ad-review-image { max-width: 220px; margin: 6px 0; border: 1px solid #e2e8f0; border-radius: 8px; }
    .ad-reject-presets { flex-basis: 100%; font-size: 13px; }
    .ad-reject-presets summary { cursor: pointer; color: #0f766e; font-weight: 600; }
    .ad-reject-presets label { display: block; margin: 4px 0; }
    .link-button { padding: 0; border: 0; background: none; color: #0f766e; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }
</style>
