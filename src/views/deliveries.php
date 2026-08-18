<?php
/**
 * 配送任務管理
 */

require_once BASE_PATH . '/src/models/DeliveryModel.php';

$deliveryModel = new DeliveryModel();
$message = null;

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
        ];
        $message = $deliveryModel->createDelivery($data)
            ? ['type' => 'success', 'text' => '配送任務已發布，志工可自主接單。']
            : ['type' => 'error', 'text' => '配送任務發布失敗。'];
    } elseif ($action === 'claim_delivery') {
        $message = $deliveryModel->claimDelivery((int) $_POST['delivery_id'], 1)
            ? ['type' => 'success', 'text' => '任務已由目前志工接單。']
            : ['type' => 'error', 'text' => '接單失敗，任務可能已被其他志工接取。'];
    } elseif ($action === 'complete_delivery') {
        $message = $deliveryModel->completeDelivery((int) $_POST['delivery_id'])
            ? ['type' => 'success', 'text' => '已確認送達，公益點數已記錄。']
            : ['type' => 'error', 'text' => '送達確認失敗。'];
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
                    <td><span class="status status-<?php echo htmlspecialchars($delivery['status']); ?>"><?php echo ['open' => '待接單', 'claimed' => '配送中', 'delivered' => '已配達'][$delivery['status']] ?? $delivery['status']; ?></span></td>
                    <td>
                        <?php if ($delivery['status'] === 'open'): ?><form method="post"><input type="hidden" name="action" value="claim_delivery"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><button class="btn btn-primary btn-sm">接單</button></form><?php endif; ?>
                        <?php if ($delivery['status'] === 'claimed'): ?><form method="post"><input type="hidden" name="action" value="complete_delivery"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><button class="btn btn-success btn-sm">確認送達</button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?><div class="empty-state"><i class="fas fa-route"></i><p>目前沒有配送任務</p></div><?php endif; ?>
    </div>
</div>
