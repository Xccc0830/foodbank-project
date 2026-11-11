<?php
require_once BASE_PATH . '/src/views/partials/detail_blocks.php';
require_once BASE_PATH . '/src/models/DonationModel.php';

require_once BASE_PATH . '/src/models/FoodbankProfileModel.php';
require_once BASE_PATH . '/src/models/DeliveryModel.php';
$trackingModel = new DonationModel();
$trackingDeliveryModel = new DeliveryModel(); // 也會確保 foodbank_vehicle 欄位存在
$trackingMessage = null;
// 食物銀行為忠信派車的配送單指定車種
if (($currentUser['role'] ?? '') === 'foodbank_staff' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_foodbank_vehicle') {
    $vehicleKey = (string) ($_POST['foodbank_vehicle'] ?? '');
    $trackingMessage = $trackingDeliveryModel->setFoodbankVehicle((int) ($_POST['delivery_id'] ?? 0), $vehicleKey)
        ? ['type' => 'success', 'text' => '已將配送任務 #' . (int) ($_POST['delivery_id'] ?? 0) . ' 的車種改為「' . DeliveryModel::FOODBANK_VEHICLES[$vehicleKey] . '」。']
        : ['type' => 'error', 'text' => '無法更新車種，請重新整理後再試。'];
}
$trackingFoodbank = (new FoodbankProfileModel())->get();
$currentRole = $currentUser['role'] ?? 'member';
$isOfficial = $currentRole === 'foodbank_staff';
$allTrackingOrders = $trackingModel->getOrderTracking($isOfficial ? null : (int) ($currentUser['user_id'] ?? 0));
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
// 篩選按鈕：「進行中」＝還沒完成也沒婉拒的訂單；每個按鈕顯示筆數
$statusFilter = (string) ($_GET['status'] ?? '');
$filterGroups = [
    '' => ['label' => '全部', 'statuses' => null],
    'active' => ['label' => '進行中', 'statuses' => ['pending', 'processing', 'in_transit']],
];
foreach ($statusLabels as $statusValue => $statusLabel) {
    $filterGroups[$statusValue] = ['label' => $statusLabel, 'statuses' => [$statusValue]];
}
if (!isset($filterGroups[$statusFilter])) {
    $statusFilter = '';
}
$filterCounts = [];
foreach ($filterGroups as $filterKey => $group) {
    $filterCounts[$filterKey] = $group['statuses'] === null ? count($allTrackingOrders) : count(array_filter($allTrackingOrders, static function ($order) use ($group) {
        return in_array($order['order_status'] ?? '', $group['statuses'], true);
    }));
}
$filteredOrders = $filterGroups[$statusFilter]['statuses'] === null ? $allTrackingOrders : array_values(array_filter($allTrackingOrders, static function ($order) use ($filterGroups, $statusFilter) {
    return in_array($order['order_status'] ?? '', $filterGroups[$statusFilter]['statuses'], true);
}));

// 分頁：每頁 10 筆
$ordersPerPage = 10;
$totalPages = max(1, (int) ceil(count($filteredOrders) / $ordersPerPage));
$currentPage = min($totalPages, max(1, (int) ($_GET['p'] ?? 1)));
$trackingOrders = array_slice($filteredOrders, ($currentPage - 1) * $ordersPerPage, $ordersPerPage);
$trackingUrl = static function ($status, $pageNumber = 1) {
    $query = ['page' => 'order_tracking'];
    if ($status !== '') { $query['status'] = $status; }
    if ($pageNumber > 1) { $query['p'] = $pageNumber; }
    return '?' . http_build_query($query);
};

$subOrderStatusLabels = [
    'open' => '待接單',
    'claimed' => '已接單',
    'arrived_pickup' => '已到店',
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
    .customer-delivery-side { display: flex; flex-direction: column; justify-content: space-between; gap: 18px; padding: 24px; border-left: 1px solid #eef2f7; background: #fbfcfe; }
    .customer-courier { display: flex; align-items: center; gap: 14px; }
    .customer-courier-avatar { display: grid; flex: 0 0 54px; width: 54px; height: 54px; place-items: center; border-radius: 50%; background: #ccfbf1; color: #0f766e; font-size: 19px; font-weight: 800; }
    .customer-courier h4 { margin: 0 0 3px; font-size: 15px; }
    .customer-courier p { margin: 0; color: #64748b; font-size: 13px; }
    .customer-stop-alert { padding: 12px 14px; border: 1px solid #fde68a; border-radius: 12px; background: #fffbeb; color: #92400e; font-size: 13px; }
    .customer-stop-alert[hidden] { display: none; }
    .customer-order-heading-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; }
    .order-route-info { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; padding: 16px 24px; border-bottom: 1px solid #eef2f7; }
    .order-route-info .detail-info-grid { margin: 8px 0 0; }
    .order-filter-chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .order-filter-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1px solid #e2e8f0; border-radius: 999px; background: #fff; color: #334155; font-size: 13px; font-weight: 600; text-decoration: none; }
    .order-filter-chip span { padding: 0 7px; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: 12px; }
    .order-filter-chip:hover { border-color: #0f766e; color: #0f766e; }
    .order-filter-chip.is-active { border-color: #0f766e; background: #0f766e; color: #fff; }
    .order-filter-chip.is-active span { background: rgba(255,255,255,.25); color: #fff; }
    .order-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; margin-top: 24px; }
    .order-page-number { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 8px; border: 1px solid #e2e8f0; border-radius: 8px; color: #334155; font-weight: 600; text-decoration: none; }
    .order-page-number.is-active { border-color: #1e3a8a; background: #1e3a8a; color: #fff; }
    .order-no-delivery { margin: 0; padding: 16px 24px; color: #64748b; font-size: 14px; }
    .order-route-title { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 15px; }
    .order-route-title i { color: #0f766e; }
    .order-tracking-suborder { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 6px; }
    .order-tracking-action { white-space: nowrap; }
    @media (max-width: 760px) {
        .customer-order-heading { align-items: flex-start; padding: 16px; }
        .customer-delivery-card { grid-template-columns: minmax(0, 1fr); }
        .customer-delivery-main, .customer-delivery-side { padding: 18px; }
        .customer-delivery-side { border-top: 1px solid #eef2f7; border-left: 0; }
        .customer-progress-step { font-size: 11px; }
        .order-route-info { grid-template-columns: minmax(0, 1fr); padding: 16px; }
    }
</style>

<div class="card">
    <div class="card-header">
        <div class="toolbar-row">
            <div>
                <h2>訂單狀態總覽</h2>
                <p class="toolbar-meta">共 <?php echo count($filteredOrders); ?> 筆訂單<?php echo $totalPages > 1 ? '，第 ' . $currentPage . ' / ' . $totalPages . ' 頁' : ''; ?></p>
            </div>
            <nav class="order-filter-chips" aria-label="篩選訂單狀態">
                <?php foreach ($filterGroups as $filterKey => $group): ?>
                    <a href="<?php echo htmlspecialchars($trackingUrl($filterKey)); ?>" class="order-filter-chip<?php echo $filterKey === $statusFilter ? ' is-active' : ''; ?>"<?php echo $filterKey === $statusFilter ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars($group['label']); ?> <span><?php echo (int) $filterCounts[$filterKey]; ?></span></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
    <div class="card-body">
        <?php if ($trackingMessage): ?>
            <div class="alert alert-<?php echo $trackingMessage['type'] === 'success' ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($trackingMessage['text']); ?></div>
        <?php endif; ?>
        <?php if ($trackingOrders): ?>
            <div class="customer-order-list">
                <?php foreach ($trackingOrders as $order): ?>
                    <?php
                    $deliveryIds = array_filter(array_map('intval', explode(',', (string) ($order['delivery_ids'] ?? ''))));
                    $deliveryMeta = [];
                    foreach (array_filter(explode(',', (string) ($order['delivery_meta'] ?? ''))) as $metaText) {
                        [$metaId, $metaMethod, $metaVehicle, $metaStatus] = array_pad(explode(':', $metaText), 4, '');
                        $deliveryMeta[(int) $metaId] = ['method' => $metaMethod, 'vehicle' => $metaVehicle, 'status' => $metaStatus];
                    }
                    $orderStatus = $order['order_status'] ?? 'processing';
                    ?>
                    <section class="customer-order-card">
                        <header class="customer-order-heading">
                            <div>
                                <h2>訂單 <?php echo htmlspecialchars($order['order_number'] ?? ''); ?></h2>
                                <p><?php if ($isOfficial): ?><?php echo htmlspecialchars($order['donor_name'] ?? ''); ?> · <?php endif; ?><?php echo htmlspecialchars($order['item_name'] ?? '物資捐贈'); ?>（<?php echo htmlspecialchars(detailNumber($order['quantity']) . ' ' . $order['unit']); ?>） · <?php echo (int) $order['delivery_count']; ?> 個配送任務 · 建立於 <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['donation_date']))); ?></p>
                            </div>
                            <div class="customer-order-heading-actions">
                                <span class="status status-<?php echo htmlspecialchars($statusClasses[$orderStatus] ?? 'approved'); ?>"><?php echo htmlspecialchars($statusLabels[$orderStatus] ?? $orderStatus); ?></span>
                                <a href="?page=<?php echo $isOfficial ? 'donation_materials_review' : 'donation_materials'; ?>" class="btn btn-secondary btn-sm order-tracking-action">查看物資</a>
                            </div>
                        </header>
                        <?php
                        // 取貨（商家）與送達（食物銀行）資訊：和 GO Rider 行程詳情看到的一樣
                        $pickupRows = [
                            ['label' => '取貨地址', 'value' => $order['donor_address'] ?: '未填寫', 'wide' => true],
                            ['label' => '預約取貨時間', 'value' => !empty($order['pickup_deadline']) ? date('Y-m-d H:i', strtotime($order['pickup_deadline'])) : '未填寫'],
                        ];
                        $donorOpenDays = DonationModel::formatOpenDays($order['donor_open_days'] ?? '');
                        if ($donorOpenDays !== '') { $pickupRows[] = ['label' => '營業日', 'value' => $donorOpenDays]; }
                        if (!empty($order['donor_business_hours'])) { $pickupRows[] = ['label' => '營業時間', 'value' => $order['donor_business_hours']]; }
                        if (!empty($order['donor_closed_days'])) { $pickupRows[] = ['label' => '公休日', 'value' => $order['donor_closed_days']]; }
                        if (!empty($order['donor_pickup_note'])) { $pickupRows[] = ['label' => '取貨備註', 'value' => $order['donor_pickup_note'], 'wide' => true]; }
                        if ($isOfficial && !empty($order['donor_phone'])) { $pickupRows[] = ['label' => '商家電話', 'value' => $order['donor_phone']]; }

                        $dropRows = [['label' => '送達地址', 'value' => $order['delivery_address'] ?: '未填寫', 'wide' => true]];
                        if (trim((string) $order['delivery_address']) === trim($trackingFoodbank['address'])) {
                            $foodbankDays = DonationModel::formatOpenDays($trackingFoodbank['open_days']);
                            if ($foodbankDays !== '') { $dropRows[] = ['label' => '收貨日', 'value' => str_replace('每天營業', '每天', $foodbankDays)]; }
                            if ($trackingFoodbank['business_hours'] !== '') { $dropRows[] = ['label' => '收貨時間', 'value' => $trackingFoodbank['business_hours']]; }
                            if ($trackingFoodbank['closed_days'] !== '') { $dropRows[] = ['label' => '公休日', 'value' => $trackingFoodbank['closed_days']]; }
                            if ($trackingFoodbank['receiving_note'] !== '') { $dropRows[] = ['label' => '收貨備註', 'value' => $trackingFoodbank['receiving_note'], 'wide' => true]; }
                            if ($trackingFoodbank['contact_phone'] !== '') { $dropRows[] = ['label' => '聯絡電話', 'value' => $trackingFoodbank['contact_phone']]; }
                        }
                        ?>
                        <div class="order-route-info">
                            <div>
                                <h3 class="order-route-title"><i class="fa-solid fa-store"></i> 取貨：<?php echo htmlspecialchars($order['donor_name'] ?? '愛心商家'); ?></h3>
                                <?php renderDetailInfoGrid($pickupRows, 'detail-info-grid-compact'); ?>
                            </div>
                            <div>
                                <h3 class="order-route-title"><i class="fa-solid fa-house-flag"></i> 送達：<?php echo trim((string) $order['delivery_address']) === trim($trackingFoodbank['address']) ? htmlspecialchars(FoodbankProfileModel::NAME) : '指定地點'; ?></h3>
                                <?php renderDetailInfoGrid($dropRows, 'detail-info-grid-compact'); ?>
                            </div>
                        </div>
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
                                                <p>配送任務 #<?php echo $deliveryId; ?> · <?php echo htmlspecialchars(detailNumber($order['quantity']) . ' ' . $order['unit']); ?></p>
                                            </div>
                                            <div class="customer-delivery-eta">
                                                <strong data-delivery-eta>確認中</strong>
                                                <span>預計送達</span>
                                            </div>
                                        </div>
                                        <div class="customer-progress" role="group" aria-label="訂單配送進度">
                                            <?php $isFoodbankDelivery = ($deliveryMeta[$deliveryId]['method'] ?? '') === 'food_bank'; ?>
                                            <?php if (!$isFoodbankDelivery && ($authService->can('page.donation_materials') || $authService->can('page.deliveries'))): ?><button type="button" class="btn btn-secondary btn-sm" data-rider-location data-delivery-id="<?php echo $deliveryId; ?>"><i class="fa-solid fa-location-crosshairs"></i> 查看 GO Rider 位置</button><?php endif; ?>
                                            <div class="customer-progress-title"><i class="fa-solid fa-box-open"></i><span class="order-live-tracking-status">正在取得配送狀態…</span></div>
                                            <div class="customer-progress-track"><span class="customer-progress-fill"></span></div>
                                            <div class="customer-progress-steps">
                                                <span class="customer-progress-step">準備中</span>
                                                <span class="customer-progress-step">GO Rider 接單</span>
                                                <span class="customer-progress-step">運送中</span>
                                                <span class="customer-progress-step">已送達</span>
                                            </div>
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
                                        <?php if ($isOfficial && $isFoodbankDelivery && !in_array($deliveryMeta[$deliveryId]['status'] ?? '', ['delivered', 'cancelled'], true)): ?>
                                            <form method="post" class="foodbank-vehicle-form">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="set_foodbank_vehicle">
                                                <input type="hidden" name="delivery_id" value="<?php echo $deliveryId; ?>">
                                                <label for="foodbankVehicle<?php echo $deliveryId; ?>">派車車種</label>
                                                <select id="foodbankVehicle<?php echo $deliveryId; ?>" name="foodbank_vehicle">
                                                    <?php if (($deliveryMeta[$deliveryId]['vehicle'] ?? '') === ''): ?><option value="" selected disabled>請選擇</option><?php endif; ?>
                                                    <?php foreach (DeliveryModel::FOODBANK_VEHICLES as $vehicleKey => $vehicleLabel): ?>
                                                        <option value="<?php echo $vehicleKey; ?>"<?php echo ($deliveryMeta[$deliveryId]['vehicle'] ?? '') === $vehicleKey ? ' selected' : ''; ?>><?php echo $vehicleLabel; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-truck"></i> 更新車種</button>
                                            </form>
                                        <?php endif; ?>
                                    </aside>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="order-no-delivery"><i class="fa-regular fa-clock"></i> <?php echo $orderStatus === 'rejected' ? '這筆物資已婉拒，不會建立配送任務。' : '食物銀行評估完成並發布後，會在這裡顯示配送進度。'; ?></p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="order-pagination" aria-label="分頁">
                    <?php if ($currentPage > 1): ?><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($trackingUrl($statusFilter, $currentPage - 1)); ?>"><i class="fas fa-chevron-left"></i> 上一頁</a><?php endif; ?>
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <a class="order-page-number<?php echo $pageNumber === $currentPage ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($trackingUrl($statusFilter, $pageNumber)); ?>"<?php echo $pageNumber === $currentPage ? ' aria-current="page"' : ''; ?>><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>
                    <?php if ($currentPage < $totalPages): ?><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($trackingUrl($statusFilter, $currentPage + 1)); ?>">下一頁 <i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-box-open"></i><p>目前沒有符合條件的訂單</p></div>
        <?php endif; ?>
    </div>
</div>
