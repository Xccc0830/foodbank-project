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
$subOrderStatusLabels = [
    'open' => '待接單',
    'claimed' => '已接單',
    'picked_up' => '配送中',
    'delivered' => '已送達',
    'exception' => '異常待處理',
    'cancelled' => '已取消',
];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">訂單追蹤</h1>
        <p class="view-subtitle">集中查看每筆訂單的處理與配送狀態</p>
    </div>
</div>

<style>
    .customer-order-list { display: grid; gap: 22px; }
    .customer-order-card { overflow: hidden; border: 1px solid #e5e7eb; border-radius: 18px; background: #fff; box-shadow: 0 8px 24px rgba(15, 23, 42, .06); }
    .customer-order-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 24px; border-bottom: 1px solid #eef2f7; }
    .customer-order-heading h2 { margin: 0 0 4px; font-size: 18px; }
    .customer-order-heading p { margin: 0; color: #64748b; font-size: 13px; }
    .customer-suborder-summary { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 24px; border-bottom: 1px solid #eef2f7; background: #f8fafc; }
    .customer-suborder-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: #fff; border: 1px solid #e2e8f0; font-size: 12px; }
    .customer-suborder-chip strong { color: #0f766e; font-family: monospace; }
    .customer-delivery-card { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr); }
    .customer-delivery-main { min-width: 0; padding: 24px; }
    .customer-delivery-summary { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; }
    .customer-delivery-summary h3 { margin: 0 0 6px; font-size: 18px; }
    .customer-delivery-summary p { margin: 0; color: #64748b; font-size: 13px; }
    .customer-delivery-eta { flex: 0 0 auto; text-align: right; }
    .customer-delivery-eta strong { display: block; color: #0f766e; font-size: 20px; }
    .customer-delivery-eta span { color: #64748b; font-size: 12px; }
    .customer-progress { margin: 24px 0; }
    .customer-progress-title { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; font-weight: 700; }
    .customer-progress-title i { color: #0f766e; }
    .customer-progress-track { position: relative; height: 8px; overflow: visible; border-radius: 99px; background: #e5e7eb; }
    .customer-progress-fill { position: relative; display: block; width: 0; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #34d399, #0f766e); transition: width .7s ease; }
    .customer-progress-fill.is-moving::after { position: absolute; top: 0; right: 0; width: 36px; height: 100%; border-radius: inherit; background: linear-gradient(90deg, transparent, rgba(255,255,255,.8)); content: ""; animation: customer-progress-shimmer 1.4s ease-in-out infinite; }
    @keyframes customer-progress-shimmer { 0%, 100% { opacity: .25; } 50% { opacity: 1; } }
    .customer-progress-steps { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 12px; }
    .customer-progress-step { color: #94a3b8; font-size: 12px; }
    .customer-progress-step.is-active, .customer-progress-step.is-complete { color: #0f766e; font-weight: 700; }
    .customer-delivery-map { position: relative; display: grid; min-height: 270px; overflow: hidden; place-items: center; background: radial-gradient(ellipse at 50% 42%, #f0fdfa 0, #e7f5f0 38%, #e8eef4 100%); }
    .customer-delivery-map::before, .customer-delivery-map::after { position: absolute; width: 130%; height: 42px; border: 12px solid rgba(255,255,255,.85); border-right: 0; border-left: 0; content: ""; transform: rotate(-24deg); }
    .customer-delivery-map::after { width: 90%; transform: rotate(36deg); }
    .customer-map-grid { position: absolute; inset: 0; opacity: .38; background-image: linear-gradient(35deg, transparent 47%, #cbd5e1 48%, #cbd5e1 49%, transparent 50%), linear-gradient(145deg, transparent 43%, #cbd5e1 44%, #cbd5e1 45%, transparent 46%); background-size: 95px 82px, 120px 100px; }
    .customer-map-rider { position: absolute; z-index: 6; top: calc(50% - 23px); left: calc(50% - 23px); display: grid; width: 46px; height: 46px; place-items: center; border: 3px solid #fff; border-radius: 50%; background: #0f766e; color: #fff; font-size: 18px; box-shadow: 0 4px 16px rgba(15,23,42,.24); }
    .customer-map-placeholder { position: relative; z-index: 4; max-width: 230px; padding: 10px 14px; border-radius: 12px; background: rgba(255,255,255,.94); color: #475569; text-align: center; font-size: 13px; box-shadow: 0 3px 12px rgba(15,23,42,.12); }
    .customer-map-frame { position: absolute; z-index: 5; inset: 0; width: 100%; height: 100%; border: 0; }
    .customer-map-frame[hidden], .customer-map-rider[hidden] { display: none; }
    .customer-map-attribution { position: absolute; z-index: 6; right: 0; bottom: 0; padding: 3px 6px; background: rgba(255,255,255,.9); color: #475569; font-size: 10px; }
    .customer-map-attribution a { color: inherit; }
    .customer-delivery-side { display: flex; flex-direction: column; justify-content: space-between; gap: 18px; padding: 24px; border-left: 1px solid #eef2f7; background: #fbfcfe; }
    .customer-courier { display: flex; align-items: center; gap: 14px; }
    .customer-courier-avatar { display: grid; flex: 0 0 54px; width: 54px; height: 54px; place-items: center; border-radius: 50%; background: #ccfbf1; color: #0f766e; font-size: 19px; font-weight: 800; }
    .customer-courier h4 { margin: 0 0 3px; font-size: 15px; }
    .customer-courier p { margin: 0; color: #64748b; font-size: 13px; }
    .customer-stop-alert { padding: 12px 14px; border: 1px solid #fde68a; border-radius: 12px; background: #fffbeb; color: #92400e; font-size: 13px; }
    .customer-stop-alert[hidden] { display: none; }
    .customer-tracking-update { color: #64748b; font-size: 12px; }
    .customer-tracking-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .customer-tracking-actions button { width: fit-content; }
    .customer-location-status { min-height: 36px; margin: 0; color: #64748b; font-size: 12px; overflow-wrap: anywhere; }
    .order-live-tracking-privacy { margin-top: 18px; color: #64748b; font-size: 12px; }
    @media (max-width: 760px) {
        .customer-order-heading { align-items: flex-start; padding: 16px; }
        .customer-delivery-card { grid-template-columns: minmax(0, 1fr); }
        .customer-delivery-main, .customer-delivery-side { padding: 18px; }
        .customer-delivery-side { border-top: 1px solid #eef2f7; border-left: 0; }
        .customer-delivery-map { min-height: 220px; }
        .customer-progress-step { font-size: 11px; }
    }
</style>

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
        <?php if (!$isOfficial && $trackingOrders): ?>
            <div class="customer-order-list">
                <?php foreach ($trackingOrders as $order): ?>
                    <?php
                    $deliveryIds = array_filter(array_map('intval', explode(',', (string) ($order['delivery_ids'] ?? ''))));
                    $orderStatus = $order['order_status'] ?? 'processing';
                    ?>
                    <section class="customer-order-card">
                        <header class="customer-order-heading">
                            <div>
                                <h2>訂單 <?php echo htmlspecialchars($order['order_number'] ?? ''); ?></h2>
                                <p><?php echo htmlspecialchars($order['item_name'] ?? '物資捐贈'); ?> · <?php echo (int) $order['delivery_count']; ?> 個配送任務</p>
                            </div>
                            <span class="status status-<?php echo htmlspecialchars($statusClasses[$orderStatus] ?? 'approved'); ?>"><?php echo htmlspecialchars($statusLabels[$orderStatus] ?? $orderStatus); ?></span>
                        </header>
                        <?php if (!empty($order['sub_orders'])): ?>
                            <div class="customer-suborder-summary">
                                <?php foreach ($order['sub_orders'] as $subOrder): ?>
                                    <span class="customer-suborder-chip">
                                        <strong><?php echo htmlspecialchars($subOrder['sub_order_number'] ?? ''); ?></strong>
                                        <span class="status status-<?php echo htmlspecialchars($subOrder['delivery_status'] ?? 'pending'); ?>"><?php echo htmlspecialchars($subOrderStatusLabels[$subOrder['delivery_status'] ?? ''] ?? '待指派'); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($deliveryIds): ?>
                            <?php foreach ($deliveryIds as $deliveryId): ?>
                                <article class="customer-delivery-card order-live-tracking" data-delivery-id="<?php echo $deliveryId; ?>" data-order-status="<?php echo htmlspecialchars($orderStatus); ?>" aria-label="配送任務 <?php echo $deliveryId; ?>">
                                    <div class="customer-delivery-main">
                                        <div class="customer-delivery-summary">
                                            <div>
                                                <h3 class="customer-delivery-status-title">正在準備配送</h3>
                                                <p>配送任務 #<?php echo $deliveryId; ?> · <?php echo htmlspecialchars($order['quantity'] . ' ' . $order['unit']); ?></p>
                                            </div>
                                            <div class="customer-delivery-eta">
                                                <strong data-delivery-eta>確認中</strong>
                                                <span>預計送達</span>
                                            </div>
                                        </div>
                                        <div class="customer-progress" role="group" aria-label="訂單配送進度">
                                            <div class="customer-progress-title"><i class="fa-solid fa-box-open"></i><span class="order-live-tracking-status">正在取得配送狀態…</span></div>
                                            <div class="customer-progress-track"><span class="customer-progress-fill"></span></div>
                                            <div class="customer-progress-steps">
                                                <span class="customer-progress-step">準備中</span>
                                                <span class="customer-progress-step">外送員接單</span>
                                                <span class="customer-progress-step">前往送達</span>
                                                <span class="customer-progress-step">已送達</span>
                                            </div>
                                        </div>
                                        <div class="customer-delivery-map" aria-label="配送位置地圖">
                                            <div class="customer-map-grid"></div>
                                            <span class="customer-map-rider" hidden><i class="fa-solid fa-motorcycle"></i></span>
                                            <p class="customer-map-placeholder">配送員取貨後，地圖會顯示即時位置</p>
                                            <iframe class="customer-map-frame" title="OpenStreetMap 配送員即時位置" loading="lazy" referrerpolicy="no-referrer" hidden></iframe>
                                            <span class="customer-map-attribution" hidden>© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> 貢獻者</span>
                                        </div>
                                    </div>
                                    <aside class="customer-delivery-side">
                                        <div>
                                            <div class="customer-courier">
                                                <span class="customer-courier-avatar" data-courier-avatar><i class="fa-solid fa-user"></i></span>
                                                <div><h4 data-courier-name>等待配送員接單</h4><p data-courier-vehicle>配送夥伴資訊將於接單後顯示</p></div>
                                            </div>
                                        </div>
                                        <p class="customer-stop-alert" data-other-stops hidden></p>
                                        <div>
                                            <p class="customer-location-status" aria-live="polite">地圖將在您點選後載入，並由 OpenStreetMap 提供地圖圖資。</p>
                                            <p class="customer-tracking-update" data-location-updated></p>
                                        </div>
                                        <div class="customer-tracking-actions">
                                            <button type="button" class="btn btn-secondary btn-sm" data-show-map disabled>載入地圖並查看位置</button>
                                            <a class="btn btn-secondary btn-sm order-live-tracking-map" target="_blank" rel="noopener noreferrer" hidden>另開地圖</a>
                                        </div>
                                    </aside>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="customer-delivery-main"><p>配送任務建立後，這裡會顯示配送進度與即時位置。</p></div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php elseif ($isOfficial && $trackingOrders): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>訂單編號</th>
                        <?php if ($isOfficial): ?><th>捐贈者</th><?php endif; ?>
                        <th>物資</th>
                        <th>建立日期</th>
                        <th>配送任務</th>
                        <th>完成進度</th>
                        <th>拆單狀態</th>
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
                            <td>
                                <?php if (!empty($order['sub_orders'])): ?>
                                    <?php foreach ($order['sub_orders'] as $subOrder): ?>
                                        <div><code><?php echo htmlspecialchars($subOrder['sub_order_number'] ?? ''); ?></code> <span class="status status-<?php echo htmlspecialchars($subOrder['delivery_status'] ?? 'pending'); ?>"><?php echo htmlspecialchars($subOrderStatusLabels[$subOrder['delivery_status'] ?? ''] ?? '待指派'); ?></span></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><span class="status status-<?php echo htmlspecialchars($statusClasses[$orderStatus] ?? 'approved'); ?>"><?php echo htmlspecialchars($statusLabels[$orderStatus] ?? $orderStatus); ?></span></td>
                            <td><a href="?page=<?php echo $isOfficial ? 'donation_materials_review' : 'donation_materials'; ?>" class="btn btn-secondary btn-sm">查看物資</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-box-open"></i><p>目前沒有符合條件的訂單</p></div>
        <?php endif; ?>
        <p class="order-live-tracking-privacy">配送位置僅供該訂單捐贈者、配送會員及官方人員查看；點選地圖連結才會將座標傳送至 OpenStreetMap。</p>
    </div>
</div>
