<?php
/**
 * 配送任務管理
 */

require_once BASE_PATH . '/src/models/DeliveryModel.php';

$deliveryModel = new DeliveryModel();
$message = null;
$editingDelivery = null;
$currentRole = $currentUser['role'] ?? 'volunteer';
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$isOfficial = in_array($currentRole, ['admin', 'foodbank_staff'], true);
$isVolunteer = $currentRole === 'volunteer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_delivery') {
        $data = [
            'donation_id' => (int) ($_POST['donation_id'] ?? 0),
            'vehicle_type' => $_POST['vehicle_type'] ?? 'motorcycle',
            'total_distance_km' => (float) ($_POST['total_distance_km'] ?? 0),
            'weight_kg' => (float) ($_POST['weight_kg'] ?? 0),
            'urgency' => $_POST['urgency'] ?? 'normal',
            'pickup_address' => trim($_POST['pickup_address'] ?? ''),
            'delivery_address' => trim($_POST['delivery_address'] ?? '忠信食物銀行'),
            'created_by' => $currentUserId,
        ];
        $message = $deliveryModel->createDelivery($data)
            ? ['type' => 'success', 'text' => '配送任務已發布，志工可自主接單。']
            : ['type' => 'error', 'text' => '配送任務發布失敗。'];
    } elseif ($action === 'claim_delivery') {
        $message = $isVolunteer && $deliveryModel->claimDelivery((int) $_POST['delivery_id'], $currentUserId)
            ? ['type' => 'success', 'text' => '任務已由目前志工接單。']
            : ['type' => 'error', 'text' => '接單失敗，任務可能已被其他志工接取。'];
    } elseif ($action === 'confirm_pickup') {
        $message = $isVolunteer && $deliveryModel->confirmPickup((int) $_POST['delivery_id'], $currentUserId, isset($_POST['seal_intact']), isset($_POST['item_count_confirmed']))
            ? ['type' => 'success', 'text' => '取貨已確認，請將物資送往食物銀行。']
            : ['type' => 'error', 'text' => '取貨確認失敗，請確認防拆貼紙與物資數量。'];
    } elseif ($action === 'report_exception') {
        $message = $isVolunteer && $deliveryModel->reportException((int) $_POST['delivery_id'], $currentUserId, $_POST['exception_notes'] ?? '')
            ? ['type' => 'success', 'text' => '異常已回報，食物銀行官方人員會進行處理。']
            : ['type' => 'error', 'text' => '請填寫異常原因後再送出。'];
    } elseif ($action === 'complete_delivery') {
        $message = $isOfficial && $deliveryModel->completeDelivery((int) $_POST['delivery_id'])
            ? ['type' => 'success', 'text' => '已確認送達，公益點數已記錄。']
            : ['type' => 'error', 'text' => '送達確認失敗。'];
    } elseif ($action === 'load_edit_delivery') {
        $editingDelivery = $deliveryModel->getDeliveryById((int) $_POST['delivery_id']);
        if (!$editingDelivery || !$deliveryModel->canManageDelivery((int) $_POST['delivery_id'], $currentUserId, $currentRole)) {
            $message = ['type' => 'error', 'text' => '您無權編輯此配送任務。'];
            $editingDelivery = null;
        }
    } elseif ($action === 'update_delivery') {
        $updated = $deliveryModel->updateDelivery((int) $_POST['delivery_id'], $currentUserId, $currentRole, [
            'donation_id' => (int) ($_POST['donation_id'] ?? 0),
            'vehicle_type' => $_POST['vehicle_type'] ?? 'motorcycle',
            'total_distance_km' => (float) ($_POST['total_distance_km'] ?? 0),
            'weight_kg' => (float) ($_POST['weight_kg'] ?? 0),
            'urgency' => $_POST['urgency'] ?? 'normal',
            'pickup_address' => trim($_POST['pickup_address'] ?? ''),
            'delivery_address' => trim($_POST['delivery_address'] ?? '忠信食物銀行'),
        ]);
        $message = $updated
            ? ['type' => 'success', 'text' => '配送任務已更新。']
            : ['type' => 'error', 'text' => '更新失敗，只有任務發起人或食物銀行／管理者可編輯。'];
    } elseif ($action === 'delete_delivery') {
        $message = $deliveryModel->deleteDelivery((int) $_POST['delivery_id'], $currentUserId, $currentRole)
            ? ['type' => 'success', 'text' => '已刪除配送任務。']
            : ['type' => 'error', 'text' => '刪除失敗，僅能刪除自己發布的配送任務，或由食物銀行／管理者處理。'];
    }
}

$deliveries = $deliveryModel->getAllDeliveries();
?>

<div class="view-header">
    <div>
        <h1 class="view-title">配送任務</h1>
        <p class="view-subtitle">發布、接取與追蹤惜食配送任務</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>發布配送任務</h2><p>依規劃書公式預先計算公益點數</p></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="create_delivery">
                <div class="form-group"><label>關聯捐贈編號</label><input type="number" name="donation_id" min="0"></div>
                <div class="grid-2">
                    <div class="form-group"><label>交通工具</label><select name="vehicle_type"><option value="motorcycle">機車</option><option value="car">汽車</option></select></div>
                    <div class="form-group"><label>總配送距離（公里）*</label><input type="number" name="total_distance_km" min="0" step="0.1" required></div>
                </div>
                <div class="grid-2">
                    <div class="form-group"><label>物資重量（公斤）*</label><input type="number" name="weight_kg" min="0" step="0.1" required></div>
                    <div class="form-group"><label>任務類型</label><select name="urgency"><option value="normal">一般配送</option><option value="priority">優先配送</option><option value="urgent">急件配送</option></select></div>
                </div>
                <div class="form-group"><label>取貨地址*</label><input type="text" name="pickup_address" required></div>
                <div class="form-group"><label>送達地址*</label><input type="text" name="delivery_address" value="忠信食物銀行" required></div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-bullhorn"></i> 發布任務</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>點數規則</h2><p>公益點數不具現金兌換功能</p></div>
        <div class="card-body">
            <ul class="feature-list">
                <li>汽車基本 10 點，機車基本 5 點</li>
                <li>距離、重量與急件程度會增加點數</li>
                <li>總距離為接單位置至商家，加上商家至食物銀行</li>
                <li>完成送達後才會記錄點數</li>
            </ul>
        </div>
    </div>
</div>

<?php if ($editingDelivery): ?>
<div class="card mt-32">
    <div class="card-header"><h2>編輯配送任務</h2><p>更新任務內容與點數計算</p></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="update_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo (int) $editingDelivery['delivery_id']; ?>">
            <div class="grid-2">
                <div class="form-group"><label>關聯捐贈編號</label><input type="number" name="donation_id" min="0" value="<?php echo (int) ($editingDelivery['donation_id'] ?? 0); ?>"></div>
                <div class="form-group"><label>交通工具</label><select name="vehicle_type">
                    <option value="motorcycle" <?php echo $editingDelivery['vehicle_type'] === 'motorcycle' ? 'selected' : ''; ?>>機車</option>
                    <option value="car" <?php echo $editingDelivery['vehicle_type'] === 'car' ? 'selected' : ''; ?>>汽車</option>
                </select></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>總配送距離（公里）*</label><input type="number" name="total_distance_km" min="0" step="0.1" value="<?php echo htmlspecialchars((string) $editingDelivery['total_distance_km']); ?>" required></div>
                <div class="form-group"><label>物資重量（公斤）*</label><input type="number" name="weight_kg" min="0" step="0.1" value="<?php echo htmlspecialchars((string) $editingDelivery['weight_kg']); ?>" required></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>任務類型</label><select name="urgency">
                    <option value="normal" <?php echo $editingDelivery['urgency'] === 'normal' ? 'selected' : ''; ?>>一般配送</option>
                    <option value="priority" <?php echo $editingDelivery['urgency'] === 'priority' ? 'selected' : ''; ?>>優先配送</option>
                    <option value="urgent" <?php echo $editingDelivery['urgency'] === 'urgent' ? 'selected' : ''; ?>>急件配送</option>
                </select></div>
                <div class="form-group"><label>送達地址*</label><input type="text" name="delivery_address" value="<?php echo htmlspecialchars($editingDelivery['delivery_address']); ?>" required></div>
            </div>
            <div class="form-group"><label>取貨地址*</label><input type="text" name="pickup_address" value="<?php echo htmlspecialchars($editingDelivery['pickup_address']); ?>" required></div>
            <div class="btn-group">
                <button class="btn btn-primary" type="submit">儲存變更</button>
                <a class="btn btn-secondary" href="?page=deliveries">取消</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mt-32">
    <div class="card-header"><h2>任務列表</h2><p>目前共 <?php echo count($deliveries); ?> 筆任務</p></div>
    <div class="card-body">
        <?php if ($deliveries): ?>
            <table class="data-table"><thead><tr><th>路線</th><th>交通</th><th>距離</th><th>重量</th><th>任務類型</th><th>點數</th><th>狀態</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <tr>
                    <td><?php echo htmlspecialchars($delivery['pickup_address']); ?> → <?php echo htmlspecialchars($delivery['delivery_address']); ?></td>
                    <td><?php echo $delivery['vehicle_type'] === 'car' ? '汽車' : '機車'; ?></td>
                    <td><?php echo htmlspecialchars($delivery['total_distance_km']); ?> km</td>
                    <td><?php echo htmlspecialchars($delivery['weight_kg']); ?> kg</td>
                    <td><?php echo ['normal' => '一般', 'priority' => '優先', 'urgent' => '急件'][$delivery['urgency']] ?? '一般'; ?></td>
                    <td><strong><?php echo (int) $delivery['points']; ?> 點</strong></td>
                    <td><span class="status status-<?php echo htmlspecialchars($delivery['status']); ?>"><?php echo ['open' => '待接單', 'claimed' => '已接單', 'picked_up' => '已取貨', 'delivered' => '已配達', 'exception' => '異常待處理'][$delivery['status']] ?? $delivery['status']; ?></span></td>
                    <td>
                        <div class="delivery-action-stack">
                            <?php if ($isVolunteer && $delivery['status'] === 'open'): ?><form method="post" class="delivery-action-form"><input type="hidden" name="action" value="claim_delivery"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><button class="btn btn-primary btn-sm">接單</button></form><?php endif; ?>
                            <?php if ($isVolunteer && $delivery['status'] === 'claimed' && (int) $delivery['volunteer_id'] === $currentUserId): ?><form method="post" class="delivery-action-form delivery-action-form-check"><input type="hidden" name="action" value="confirm_pickup"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><label><input type="checkbox" name="seal_intact" required> 防拆貼紙完整</label><label><input type="checkbox" name="item_count_confirmed" required> 已清點物資</label><button class="btn btn-primary btn-sm">確認取貨</button></form><form method="post" class="delivery-action-form"><input type="hidden" name="action" value="report_exception"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><input name="exception_notes" placeholder="異常原因" required><button class="btn btn-danger btn-sm">回報異常</button></form><?php endif; ?>
                            <?php if ($isOfficial && in_array($delivery['status'], ['claimed', 'picked_up'], true)): ?><form method="post" class="delivery-action-form"><input type="hidden" name="action" value="complete_delivery"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><button class="btn btn-success btn-sm">確認收貨</button></form><?php endif; ?>
                            <?php $canManageDelivery = ((int) ($delivery['created_by'] ?? 0) === $currentUserId) || $isOfficial; if ($canManageDelivery): ?>
                                <div class="inline-action-group">
                                    <form method="post" class="delivery-action-form">
                                        <input type="hidden" name="action" value="load_edit_delivery">
                                        <input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit">編輯</button>
                                    </form>
                                    <form method="post" class="delivery-action-form" onsubmit="return confirm('確定要刪除這個配送任務嗎？');">
                                        <input type="hidden" name="action" value="delete_delivery">
                                        <input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">刪除</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?><div class="empty-state"><i class="fas fa-route"></i><p>目前沒有配送任務</p></div><?php endif; ?>
    </div>
</div>
