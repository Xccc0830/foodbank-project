<?php
require_once BASE_PATH . '/src/models/DonationModel.php';

$trackingModel = new DonationModel();
$currentRole = $currentUser['role'] ?? 'member';
$isOfficial = $currentRole === 'foodbank_staff';
$trackingOrders = $trackingModel->getOrderTracking(
    $isOfficial ? null : (int) ($currentUser['user_id'] ?? 0),
    $_GET['status'] ?? null
);
$statusLabels = [
    'pending' => '待處理',
    'processing' => '處理中',
    'in_transit' => '配送中',
    'completed' => '已完成',
    'rejected' => '已婉拒',
];
$statusClasses = [
    'pending' => 'pending',
    'processing' => 'approved',
    'in_transit' => 'in-transit',
    'completed' => 'completed',
    'rejected' => 'rejected',
];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">訂單追蹤</h1>
        <p class="view-subtitle">集中查看每筆訂單的處理與配送狀態</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="toolbar-row">
            <div>
                <h2>訂單狀態總覽</h2>
                <p class="toolbar-meta">共 <?php echo count($trackingOrders); ?> 筆訂單</p>
            </div>
            <form method="get" class="toolbar-actions">
                <input type="hidden" name="page" value="order_tracking">
                <select name="status" class="filter-select" onchange="this.form.submit()" aria-label="篩選訂單狀態">
                    <option value="">全部狀態</option>
                    <?php foreach ($statusLabels as $statusValue => $statusLabel): ?>
                        <option value="<?php echo $statusValue; ?>" <?php echo ($_GET['status'] ?? '') === $statusValue ? 'selected' : ''; ?>><?php echo $statusLabel; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="card-body">
        <?php if ($trackingOrders): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>訂單編號</th>
                        <?php if ($isOfficial): ?><th>捐贈者</th><?php endif; ?>
                        <th>物資</th>
                        <th>建立日期</th>
                        <th>配送任務</th>
                        <th>完成進度</th>
                        <th>目前狀態</th>
                        <th>明細</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trackingOrders as $order): ?>
                        <?php $orderStatus = $order['order_status'] ?? 'processing'; ?>
                        <tr>
                            <td><strong><code><?php echo htmlspecialchars($order['order_number']); ?></code></strong></td>
                            <?php if ($isOfficial): ?><td><?php echo htmlspecialchars($order['donor_name']); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($order['item_name'] ?? '-'); ?><br><small><?php echo htmlspecialchars($order['quantity'] . ' ' . $order['unit']); ?></small></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['donation_date']))); ?></td>
                            <td><?php echo (int) $order['delivery_count']; ?> 個任務</td>
                            <td><?php echo (int) $order['completed_delivery_count']; ?> / <?php echo (int) $order['delivery_count']; ?> 完成</td>
                            <td><span class="status status-<?php echo htmlspecialchars($statusClasses[$orderStatus] ?? 'approved'); ?>"><?php echo htmlspecialchars($statusLabels[$orderStatus] ?? $orderStatus); ?></span></td>
                            <td><a href="?page=<?php echo $isOfficial ? 'donation_materials_review' : 'donation_materials'; ?>" class="btn btn-secondary btn-sm">查看物資</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-box-open"></i><p>目前沒有符合條件的訂單</p></div>
        <?php endif; ?>
    </div>
</div>
