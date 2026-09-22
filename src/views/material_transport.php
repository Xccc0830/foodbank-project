<?php
require_once BASE_PATH . '/src/models/DeliveryModel.php';

$deliveryModel = new DeliveryModel();
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$transportMessage = null;
$viewingTask = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);

    if ($action === 'view_material_transport' && $deliveryId > 0) {
        $viewingTask = $deliveryModel->getMaterialTransportTask($deliveryId);
    } elseif ($action === 'accept_material_transport' && $deliveryId > 0) {
        $task = $deliveryModel->getMaterialTransportTask($deliveryId);
        $accepted = $task && $deliveryModel->claimDelivery($deliveryId, $currentUserId);
        $transportMessage = $accepted
            ? ['type' => 'success', 'text' => '運送任務已接受，請依任務資訊前往取貨。']
            : ['type' => 'error', 'text' => '任務接受失敗，可能已被其他志工接受。'];
        $viewingTask = $deliveryModel->getMaterialTransportTask($deliveryId);
    } elseif ($action === 'cancel_material_transport' && $deliveryId > 0) {
        $cancelled = $deliveryModel->cancelClaimedDelivery($deliveryId, $currentUserId);
        $transportMessage = $cancelled
            ? ['type' => 'success', 'text' => '運送任務已取消，已重新開放給其他志工接受。']
            : ['type' => 'error', 'text' => '取消失敗，只能取消自己尚未取貨的運送任務。'];
        $viewingTask = $deliveryModel->getMaterialTransportTask($deliveryId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['view_delivery_id'])) {
    $viewingTask = $deliveryModel->getMaterialTransportTask((int) $_GET['view_delivery_id']);
}

$tasks = $deliveryModel->getMaterialTransportTasks();
$tasksByDonation = [];
foreach ($tasks as $task) {
    $donationId = (int) ($task['donation_id'] ?? 0);
    if (!isset($tasksByDonation[$donationId]) || ($task['status'] ?? '') === 'open') {
        $tasksByDonation[$donationId] = $task;
    }
}
$donationTypeLabels = [
    'food' => '食物',
    'supplies' => '民生用品',
    'money' => '金錢',
    'other' => '其他',
];
$photoUrl = static function ($photoPath) {
    if (empty($photoPath)) {
        return null;
    }
    $firstPhoto = trim((string) preg_split('/\s*,\s*/', $photoPath)[0]);
    return APP_URL . '/' . ltrim($firstPhoto, '/');
};
?>

<div class="view-header">
    <div>
        <h1 class="view-title">物資運送</h1>
        <p class="view-subtitle">查看食物銀行已發布的物資運送任務</p>
    </div>
</div>

<?php if ($transportMessage): ?>
    <div class="alert alert-<?php echo $transportMessage['type']; ?>"><?php echo htmlspecialchars($transportMessage['text']); ?></div>
<?php endif; ?>

<div class="material-transport-grid">
    <?php foreach ($tasksByDonation as $task): ?>
        <?php $imageUrl = $photoUrl($task['photo_path'] ?? null); ?>
        <article class="material-transport-card" role="button" tabindex="0" onclick="window.location.href='?page=material_transport&amp;view_delivery_id=<?php echo (int) $task['delivery_id']; ?>'" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }">
            <?php if ($imageUrl): ?>
                <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="物資照片">
            <?php else: ?>
                <div class="material-transport-placeholder"><i class="fas fa-box-open"></i></div>
            <?php endif; ?>
            <div class="material-transport-card-body">
                <h2><?php echo htmlspecialchars($task['donor_name'] ?? '未提供店家名稱'); ?></h2>
                <p><?php echo htmlspecialchars($donationTypeLabels[$task['donation_type'] ?? 'other'] ?? '其他'); ?></p>
                <?php
                $isAcceptedByCurrentUser = ($task['status'] ?? '') === 'claimed'
                    && (int) ($task['volunteer_id'] ?? 0) === $currentUserId;
                $taskStatusLabel = $isAcceptedByCurrentUser
                    ? '接受運送'
                    : (($task['status'] ?? '') === 'claimed' ? '已被接受' : '待接受');
                $taskStatusClass = $isAcceptedByCurrentUser ? 'accepted' : (($task['status'] ?? '') === 'claimed' ? 'claimed' : 'open');
                ?>
                <span class="material-transport-status material-transport-status-<?php echo $taskStatusClass; ?>">
                    <?php echo htmlspecialchars($taskStatusLabel); ?>
                </span>
                <span class="material-transport-card-link">查看任務資訊 <i class="fas fa-arrow-right"></i></span>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php if (empty($tasksByDonation)): ?>
    <div class="empty-state"><i class="fas fa-truck-fast"></i><p>目前沒有已發布的物資運送任務</p></div>
<?php endif; ?>

<?php if ($viewingTask): ?>
    <div class="material-transport-overlay">
        <div class="material-transport-modal">
            <div class="card-header">
                <div>
                    <h2>運送任務資訊</h2>
                    <p><?php echo htmlspecialchars($viewingTask['donor_name'] ?? ''); ?></p>
                </div>
                <a class="icon-btn" href="?page=material_transport" aria-label="關閉"><i class="fas fa-times"></i></a>
            </div>
            <div class="card-body">
                <?php $imageUrl = $photoUrl($viewingTask['photo_path'] ?? null); ?>
                <?php if ($imageUrl): ?><img class="material-transport-detail-photo" src="<?php echo htmlspecialchars($imageUrl); ?>" alt="物資照片"><?php endif; ?>
                <div class="material-transport-details">
                    <p><strong>店家名稱：</strong><?php echo htmlspecialchars($viewingTask['donor_name'] ?? '未提供'); ?></p>
                    <p><strong>店家地址：</strong><?php echo htmlspecialchars($viewingTask['donor_address'] ?? $viewingTask['pickup_address'] ?? '未提供'); ?></p>
                    <p><strong>物資類別：</strong><?php echo htmlspecialchars($donationTypeLabels[$viewingTask['donation_type'] ?? 'other'] ?? '其他'); ?></p>
                    <p><strong>物資名稱：</strong><?php echo htmlspecialchars($viewingTask['item_name'] ?? '未提供'); ?></p>
                    <p><strong>數量：</strong><?php echo htmlspecialchars($viewingTask['quantity'] ?? ''); ?> <?php echo htmlspecialchars($viewingTask['unit'] ?? ''); ?></p>
                    <p><strong>重量：</strong><?php echo htmlspecialchars($viewingTask['published_weight_kg'] ?? '0'); ?> 公斤</p>
                    <p><strong>大小：</strong><?php echo htmlspecialchars($viewingTask['size_description'] ?? '未提供'); ?></p>
                    <p><strong>領取期限：</strong><?php echo htmlspecialchars($viewingTask['pickup_deadline'] ?? '未提供'); ?></p>
                    <p><strong>送達地點：</strong><?php echo htmlspecialchars($viewingTask['delivery_address'] ?? '忠信食物銀行'); ?></p>
                    <p><strong>本單重量：</strong><?php echo htmlspecialchars($viewingTask['weight_kg'] ?? '0'); ?> 公斤</p>
                </div>
                <?php if (($viewingTask['status'] ?? '') === 'open'): ?>
                    <form method="post" class="material-transport-accept-form">
                        <input type="hidden" name="action" value="accept_material_transport">
                        <input type="hidden" name="delivery_id" value="<?php echo (int) $viewingTask['delivery_id']; ?>">
                        <button type="submit" class="btn btn-primary">接受任務</button>
                    </form>
                <?php elseif (($viewingTask['status'] ?? '') === 'claimed' && (int) ($viewingTask['volunteer_id'] ?? 0) === $currentUserId): ?>
                    <form method="post" class="material-transport-accept-form">
                        <input type="hidden" name="action" value="cancel_material_transport">
                        <input type="hidden" name="delivery_id" value="<?php echo (int) $viewingTask['delivery_id']; ?>">
                        <button type="submit" class="btn btn-danger">取消運送任務</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">此任務已被其他志工接受。</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
    .material-transport-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
    .material-transport-card { overflow: hidden; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(15, 23, 42, .06); cursor: pointer; transition: transform .18s ease, box-shadow .18s ease; }
    .material-transport-card:hover, .material-transport-card:focus-visible { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, .12); outline: none; }
    .material-transport-card > img, .material-transport-placeholder { width: 100%; height: 170px; object-fit: cover; }
    .material-transport-placeholder { display: grid; place-items: center; background: #f1f5f9; color: #94a3b8; font-size: 42px; }
    .material-transport-card-body { padding: 16px; }
    .material-transport-card-body h2 { margin: 0 0 6px; font-size: 18px; }
    .material-transport-card-body p { margin: 0 0 14px; color: #64748b; }
    .material-transport-status { display: inline-block; margin-bottom: 14px; padding: 5px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
    .material-transport-status-open { background: #eef2ff; color: #4f46e5; }
    .material-transport-status-accepted { background: #dcfce7; color: #15803d; }
    .material-transport-status-claimed { background: #fef3c7; color: #a16207; }
    .material-transport-card-link { color: #4f46e5; font-weight: 700; }
    .material-transport-overlay { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(15, 23, 42, .5); }
    .material-transport-modal { width: min(680px, 100%); max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 10px; }
    .material-transport-modal > .card-header { position: relative; padding-right: 56px; }
    .material-transport-modal > .card-header .icon-btn { position: absolute; top: 16px; right: 16px; }
    .material-transport-detail-photo { width: 100%; max-height: 260px; object-fit: contain; margin-bottom: 18px; }
    .material-transport-details p { margin: 0 0 10px; line-height: 1.6; }
    .material-transport-accept-form { margin-top: 22px; }
</style>
