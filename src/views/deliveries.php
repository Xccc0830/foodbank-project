<?php
/**
 * 配送任務管理
 */

require_once BASE_PATH . '/src/models/DeliveryModel.php';
require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/helpers/AddressHelper.php';

$deliveryModel = new DeliveryModel();
$message = null;
$editingDelivery = null;
$currentRole = $currentUser['role'] ?? 'volunteer';
$memberType = $currentUser['member_type'] ?? null;
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$isOfficial = $currentRole === 'foodbank_staff';
require_once BASE_PATH . '/src/models/ThanksModel.php';
$thanksModel = new ThanksModel();
$canManageDeliveries = $isOfficial && $authService->can('delivery.manage');
$canConfirmArrival = $isOfficial && $authService->can('delivery.confirm_arrival');
$isVolunteer = $currentRole === 'member' && $memberType === 'general' && $authService->can('rider.accept_task');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message = ['type' => 'error', 'text' => '上傳內容超過伺服器允許的大小，請縮小照片後再試。'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_delivery') {
        if (!$canManageDeliveries) {
            $message = ['type' => 'error', 'text' => '只有管理員可以發布配送任務。'];
        } elseif ($addressError = validateAddress($_POST['pickup_address'] ?? '', '取貨地址') ?? validateAddress($_POST['delivery_address'] ?? '', '送達地址')) {
            $message = ['type' => 'error', 'text' => $addressError];
        } else {
        $data = [
            'donation_id' => $_POST['donation_id'] ?? null,
            'delivery_method' => $_POST['delivery_method'] ?? 'volunteer',
            'vehicle_type' => $_POST['vehicle_type'] ?? 'motorcycle',
            'total_distance_km' => (float) ($_POST['total_distance_km'] ?? 0),
            'weight_kg' => (float) ($_POST['weight_kg'] ?? 0),
            'urgency' => $_POST['urgency'] ?? 'normal',
            'pickup_address' => trim($_POST['pickup_address'] ?? ''),
            'delivery_address' => trim($_POST['delivery_address'] ?? '忠信食物銀行'),
            'created_by' => $currentUserId,
        ];
        $createdDeliveryId = $deliveryModel->createDelivery($data);
        $message = $createdDeliveryId
            ? ['type' => 'success', 'text' => '配送任務已發布，並依選擇的運送方式處理。']
            : ['type' => 'error', 'text' => '配送任務發布失敗。'];
        }
    } elseif ($action === 'claim_delivery') {
        $message = $isVolunteer && $deliveryModel->claimDelivery((int) $_POST['delivery_id'], $currentUserId)
            ? ['type' => 'success', 'text' => '任務已由目前配送會員接單。']
            : ['type' => 'error', 'text' => '接單失敗，任務可能已被其他配送會員接取。'];
    } elseif ($action === 'arrive_pickup') {
        $message = $isVolunteer && $deliveryModel->markArrivedAtPickup((int) ($_POST['delivery_id'] ?? 0), $currentUserId)
            ? ['type' => 'success', 'text' => '已標記到店，請拍攝物資照片後完成取件。']
            : ['type' => 'error', 'text' => '無法標記到店，任務狀態可能已變更。'];
    } elseif ($action === 'confirm_pickup') {
        require_once BASE_PATH . '/src/helpers/UploadHelper.php';
        $photoFile = $_FILES['pickup_photo'] ?? null;
        if (!$isVolunteer) {
            $message = ['type' => 'error', 'text' => '只有目前接單的配送會員可以確認取貨。'];
        } elseif (!$photoFile || ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadError = (int) ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE);
            $uploadMessage = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                ? '照片超過伺服器允許的上傳大小，請縮小照片後重試。'
                : '請先選取取貨照片，再按「完成取件」。';
            $message = ['type' => 'error', 'text' => $uploadMessage];
        } else {
            $pickupPhotoPath = uploadPickupPhoto($photoFile);
            $confirmed = $pickupPhotoPath !== false
                && $deliveryModel->confirmPickup((int) ($_POST['delivery_id'] ?? 0), $currentUserId, $pickupPhotoPath);
            $message = $confirmed
                ? ['type' => 'success', 'text' => '取貨已確認，請將物資送往食物銀行。']
                : ['type' => 'error', 'text' => '取貨照片無法儲存，請確認格式為 JPG、PNG 或 WebP 且不超過 10MB，並重試。'];
        }
    } elseif ($action === 'report_exception') {
        $message = $isVolunteer && $deliveryModel->reportException((int) $_POST['delivery_id'], $currentUserId, $_POST['exception_notes'] ?? '')
            ? ['type' => 'success', 'text' => '異常已回報，食物銀行官方人員會進行處理。']
            : ['type' => 'error', 'text' => '請填寫異常原因後再送出。'];
    } elseif ($action === 'resolve_exception') {
        $resolved = $canManageDeliveries && $deliveryModel->resolveException(
            (int) $_POST['delivery_id'],
            $currentUserId,
            $_POST['exception_response'] ?? '',
            $_POST['exception_next_status'] ?? 'claimed'
        );
        $message = $resolved
            ? ['type' => 'success', 'text' => '異常已處理，回覆已通知配送會員。']
            : ['type' => 'error', 'text' => '異常處理失敗，請填寫處理結果或確認任務狀態。'];
    } elseif ($action === 'reject_exception') {
        $rejected = $canManageDeliveries && $deliveryModel->rejectException(
            (int) $_POST['delivery_id'],
            $currentUserId,
            $_POST['exception_response'] ?? ''
        );
        $message = $rejected
            ? ['type' => 'success', 'text' => '異常回報已駁回，任務已恢復為已接單。']
            : ['type' => 'error', 'text' => '駁回異常回報失敗，請填寫回覆內容。'];
    } elseif ($action === 'complete_delivery') {
        $message = $canConfirmArrival && $deliveryModel->completeDelivery((int) $_POST['delivery_id'], $currentUserId)
            ? ['type' => 'success', 'text' => '已確認送達，公益點數已記錄。']
            : ['type' => 'error', 'text' => '送達確認失敗。'];
    } elseif ($action === 'send_thanks') {
        $thanksResult = $isOfficial
            ? $thanksModel->send((int) ($_POST['delivery_id'] ?? 0), $currentUserId, $_POST['thanks_message'] ?? '', !empty($_POST['thanks_public']))
            : '只有食物銀行人員可以留下感謝。';
        $message = $thanksResult === true
            ? ['type' => 'success', 'text' => '感謝訊息已送給商家。']
            : ['type' => 'error', 'text' => $thanksResult];
    } elseif ($action === 'load_edit_delivery') {
        if (!$canManageDeliveries) {
            $message = ['type' => 'error', 'text' => '只有管理員可以編輯配送任務。'];
        } else {
        $editingDelivery = $deliveryModel->getDeliveryById((int) $_POST['delivery_id']);
        if (!$editingDelivery || !$deliveryModel->canManageDelivery((int) $_POST['delivery_id'], $currentUserId, $currentRole)) {
            $message = ['type' => 'error', 'text' => '您無權編輯此配送任務。'];
            $editingDelivery = null;
        }
        }
    } elseif ($action === 'update_delivery') {
        if (!$canManageDeliveries) {
            $message = ['type' => 'error', 'text' => '只有管理員可以更新配送任務。'];
            $updated = false;
        } elseif ($addressError = validateAddress($_POST['pickup_address'] ?? '', '取貨地址') ?? validateAddress($_POST['delivery_address'] ?? '', '送達地址')) {
            $message = ['type' => 'error', 'text' => $addressError];
            $updated = false;
        } else {
        $updated = $deliveryModel->updateDelivery((int) $_POST['delivery_id'], $currentUserId, $currentRole, [
            'donation_id' => $_POST['donation_id'] ?? null,
            'delivery_method' => $_POST['delivery_method'] ?? 'volunteer',
            'vehicle_type' => $_POST['vehicle_type'] ?? 'motorcycle',
            'total_distance_km' => (float) ($_POST['total_distance_km'] ?? 0),
            'weight_kg' => (float) ($_POST['weight_kg'] ?? 0),
            'urgency' => $_POST['urgency'] ?? 'normal',
            'status' => $_POST['status'] ?? 'open',
            'pickup_address' => trim($_POST['pickup_address'] ?? ''),
            'delivery_address' => trim($_POST['delivery_address'] ?? '忠信食物銀行'),
        ]);
        $message = $updated
            ? ['type' => 'success', 'text' => '配送任務已更新。']
            : ['type' => 'error', 'text' => '更新失敗，只有任務發起人或食物銀行／管理者可編輯。'];
        }
    } elseif ($action === 'update_exception') {
        $updated = $canManageDeliveries && $deliveryModel->updateException(
            (int) $_POST['delivery_id'],
            $currentUserId,
            $currentRole,
            $_POST['exception_notes'] ?? '',
            $_POST['exception_response'] ?? ''
        );
        $message = $updated
            ? ['type' => 'success', 'text' => '異常回報內容已更新。']
            : ['type' => 'error', 'text' => '異常回報更新失敗。'];
    } elseif ($action === 'delete_delivery') {
        if (!$canManageDeliveries) {
            $message = ['type' => 'error', 'text' => '只有管理員可以刪除配送任務。'];
        } else {
        $message = $deliveryModel->deleteDelivery((int) $_POST['delivery_id'], $currentUserId, $currentRole)
            ? ['type' => 'success', 'text' => '已刪除配送任務。']
            : ['type' => 'error', 'text' => '刪除失敗，僅能刪除自己發布的配送任務，或由食物銀行／管理者處理。'];
        }
    }
}

$deliveryModel->notifyOverdueDeliveries();
$deliveries = $deliveryModel->getAllDeliveries();
$completedDeliveries = array_filter($deliveries, static function ($delivery) use ($isOfficial, $currentUserId) {
    return $delivery['status'] === 'delivered'
        && ($isOfficial || (int) ($delivery['volunteer_id'] ?? 0) === $currentUserId);
});
$pendingDeliveries = array_filter($deliveries, static function ($delivery) use ($isOfficial, $currentUserId) {
    if ($delivery['status'] === 'delivered') {
        return false;
    }
    // GO Rider 只看得到「還沒人接的 GO Rider 單」和「自己接的單」；忠信派車的單與別人接的單不顯示
    return $isOfficial
        || (($delivery['delivery_method'] ?? '') === 'volunteer'
            && ($delivery['status'] === 'open' || (int) ($delivery['volunteer_id'] ?? 0) === $currentUserId));
});

$activeDriverTrip = null;
if ($isVolunteer) {
    foreach ($deliveries as $delivery) {
        if ((int) ($delivery['volunteer_id'] ?? 0) === $currentUserId && in_array($delivery['status'], ['claimed', 'arrived_pickup', 'picked_up'], true)) {
            $activeDriverTrip = $delivery;
            break;
        }
    }
}
?>

<?php if ($isVolunteer): ?>
<style>
    .driver-trip-card { position: relative; margin-bottom: 28px; border-radius: var(--radius-lg, 16px); overflow: hidden; box-shadow: var(--shadow-lg, 0 14px 32px rgba(17,24,39,.12)); background: #0f172a; color: #f8fafc; }
    .driver-trip-map { position: relative; min-height: 220px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; transition: background .25s ease, color .25s ease; background: linear-gradient(160deg, #0b1222 0%, #131b31 55%, #0f172a 100%); }
    .driver-trip-map[data-theme="light"] { background: linear-gradient(160deg, #eef2ff 0%, #f8fafc 55%, #ffffff 100%); color: #1f2937; }
    .driver-trip-map-topbar { display: flex; align-items: center; justify-content: space-between; }
    .driver-trip-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; background: rgba(242,169,0,.2); color: #FFD166; }
    .driver-trip-map[data-theme="light"] .driver-trip-status-pill { background: rgba(32,59,143,.12); color: #203B8F; }
    .driver-map-theme-toggle { border: 1px solid rgba(248,250,252,.3); background: rgba(255,255,255,.08); color: inherit; border-radius: 999px; width: 36px; height: 36px; cursor: pointer; font-size: 14px; }
    .driver-trip-map[data-theme="light"] .driver-map-theme-toggle { border-color: rgba(15,23,42,.15); background: rgba(15,23,42,.06); }
    .driver-trip-route { display: flex; align-items: center; gap: 10px; margin: 28px 0; }
    .driver-trip-pin { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; font-size: 15px; flex: 0 0 auto; }
    .driver-trip-pin-start { background: #14b8a6; color: #06281f; }
    .driver-trip-pin-end { background: #f97316; color: #3a1c02; }
    .driver-trip-route-line { flex: 1; position: relative; height: 3px; border-radius: 3px; background: repeating-linear-gradient(90deg, #F2A900 0, #F2A900 10px, transparent 10px, transparent 18px); }
    .driver-trip-map[data-theme="light"] .driver-trip-route-line { background: repeating-linear-gradient(90deg, #203B8F 0, #203B8F 10px, transparent 10px, transparent 18px); }
    .driver-trip-route-line::after { content: "\f3c5"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -60%); font-size: 14px; color: #f8fafc; }
    .driver-trip-map[data-theme="light"] .driver-trip-route-line::after { color: #203B8F; }
    .driver-trip-map > .driver-navigate-btn + .driver-navigate-btn { margin-top: 14px; }
    .driver-navigate-btn { align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; border: none; background: #203B8F; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; text-decoration: none; }
    .driver-navigate-btn:hover { background: #172F75; }
    .driver-trip-sheet { background: var(--bg-primary, #fff); color: var(--text-primary, #111827); padding: 18px 20px 20px; }
    .driver-trip-sheet-handle { display: flex; flex-direction: column; align-items: center; gap: 10px; width: 100%; border: none; background: transparent; cursor: pointer; padding: 0 0 12px; }
    .driver-trip-sheet-summary { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 12px; text-align: left; }
    .driver-trip-sheet-summary strong { display: block; font-size: 15px; white-space: nowrap; }
    .driver-trip-sheet-summary small { color: var(--text-secondary, #6b7280); }
    .driver-trip-sheet-details { margin-top: 16px; display: grid; gap: 14px; }
    .driver-trip-sheet-details[hidden] { display: none; }
    .driver-trip-stop { display: flex; gap: 12px; align-items: flex-start; }
    .driver-trip-stop i { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background: var(--bg-secondary, #f7f9fc); color: var(--primary-color, #203B8F); flex: 0 0 auto; }
    .driver-trip-stop strong { display: block; font-size: 13px; color: var(--text-secondary, #6b7280); }
    .driver-trip-stop span { display: block; font-size: 14px; font-weight: 600; }
    .driver-trip-note { margin-top: 4px; padding: 10px 12px; border-radius: 10px; background: #fff7ed; color: #9a3412; font-size: 12px; }
    .driver-trip-checklist { margin-top: 16px; display: grid; gap: 8px; padding: 12px 14px; border-radius: 10px; background: var(--bg-secondary, #f7f9fc); }
    .driver-trip-checklist label { display: flex; align-items: center; gap: 8px; font-size: 13px; }
    .driver-action-button { margin-top: 16px; width: 100%; border: none; border-radius: 14px; padding: 16px; font-size: 15px; font-weight: 800; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; background: linear-gradient(90deg, #203B8F, #3B5DB2); box-shadow: 0 10px 24px rgba(32,59,143,.3); }
    .driver-action-button.driver-action-complete { background: linear-gradient(90deg, #10B981, #34d399); box-shadow: 0 10px 24px rgba(16,185,129,.35); }
    .driver-action-button:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
    .driver-action-button i { transition: transform .2s ease; }
    .driver-action-button:not(:disabled):hover i { transform: translateX(4px); }
</style>
<?php endif; ?>

<?php if ($activeDriverTrip):
    $tripIsPickupPhase = in_array($activeDriverTrip['status'], ['claimed', 'arrived_pickup'], true);
    $tripDestinationLabel = $tripIsPickupPhase ? '前往商家取件' : '前往顧客送達';
    $tripStoreName = $activeDriverTrip['donor_name'] ?? '合作商家';
    $tripOrigin = $tripIsPickupPhase ? '目前位置' : $activeDriverTrip['pickup_address'];
    $tripDestinationAddress = $tripIsPickupPhase ? $activeDriverTrip['pickup_address'] : $activeDriverTrip['delivery_address'];
    $tripTravelMode = ($activeDriverTrip['vehicle_type'] ?? 'motorcycle') === 'car' ? 'driving' : 'two_wheeler';
    // 階段一：目前定位 → 取貨地址；階段二：目前定位 → 送達地址（無定位時以取貨地址為起點）
    $navigationUrl = 'https://www.google.com/maps/dir/?' . http_build_query(array_filter([
        'api' => 1,
        'origin' => $tripIsPickupPhase ? null : $activeDriverTrip['pickup_address'],
        'destination' => $tripDestinationAddress,
        'travelmode' => $tripTravelMode,
    ]));
    $hasOtherStop = false;
    foreach ($deliveries as $otherDelivery) {
        if ((int) $otherDelivery['delivery_id'] === (int) $activeDriverTrip['delivery_id']) {
            continue;
        }
        if ((int) ($otherDelivery['volunteer_id'] ?? 0) === $currentUserId && in_array($otherDelivery['status'], ['claimed', 'arrived_pickup', 'picked_up'], true)) {
            $hasOtherStop = true;
            break;
        }
    }
?>
<?php if ($hasOtherStop): ?>
<div class="driver-trip-note" data-route-plan hidden><strong><i class="fas fa-route"></i> 建議路線順序（由近到遠、先取貨再送達）</strong><ol data-route-plan-list></ol></div>
<?php endif; ?>
<div class="driver-trip-card" data-driver-trip data-status="<?php echo htmlspecialchars($activeDriverTrip['status']); ?>">
    <div class="driver-trip-map" data-theme="dark" data-map-theme>
        <div class="driver-trip-map-topbar">
            <span class="driver-trip-status-pill"><i class="fas fa-route"></i> <?php echo htmlspecialchars($tripDestinationLabel); ?></span>
            <button type="button" class="driver-map-theme-toggle" data-map-theme-toggle aria-label="切換地圖淺色／深色模式"><i class="fas fa-moon" data-theme-icon></i></button>
        </div>
        <div class="driver-trip-route">
            <span class="driver-trip-pin driver-trip-pin-start" title="<?php echo htmlspecialchars($tripOrigin); ?>"><i class="fas fa-location-arrow"></i></span>
            <span class="driver-trip-route-line" aria-hidden="true"></span>
            <span class="driver-trip-pin driver-trip-pin-end" title="<?php echo htmlspecialchars($tripDestinationAddress); ?>"><i class="fas <?php echo $tripIsPickupPhase ? 'fa-store' : 'fa-house-user'; ?>"></i></span>
        </div>
        <button type="button" class="driver-navigate-btn" data-map-modal-open data-map-query="<?php echo htmlspecialchars($tripDestinationAddress, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-map-location-dot"></i> 地圖小視窗</button>
        <a class="driver-navigate-btn" data-navigate-btn data-destination="<?php echo htmlspecialchars($tripDestinationAddress, ENT_QUOTES, 'UTF-8'); ?>" data-fallback-origin="<?php echo $tripIsPickupPhase ? '' : htmlspecialchars($activeDriverTrip['pickup_address'], ENT_QUOTES, 'UTF-8'); ?>" data-travel-mode="<?php echo $tripTravelMode; ?>" href="<?php echo htmlspecialchars($navigationUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><i class="fas fa-diamond-turn-right"></i> 開始導航</a>
    </div>
    <div class="driver-trip-sheet" data-driver-sheet>
        <button type="button" class="driver-trip-sheet-handle" data-sheet-toggle aria-expanded="false" aria-controls="driverTripDetails-<?php echo (int) $activeDriverTrip['delivery_id']; ?>">
            <div class="driver-trip-sheet-summary">
                <span>
                    <strong><?php echo htmlspecialchars($tripStoreName); ?></strong>
                </span>
                <i class="fas fa-chevron-up" data-sheet-chevron></i>
            </div>
        </button>
        <div class="driver-trip-sheet-details" id="driverTripDetails-<?php echo (int) $activeDriverTrip['delivery_id']; ?>" data-sheet-details hidden>
            <div class="driver-trip-stop">
                <i class="fas fa-store"></i>
                <div><strong>取件商家</strong><span><?php echo htmlspecialchars($tripStoreName); ?></span><span><?php echo htmlspecialchars($activeDriverTrip['pickup_address']); ?></span></div>
            </div>
            <div class="driver-trip-stop">
                <i class="fas fa-house-user"></i>
                <div><strong>送達地址</strong><span><?php echo htmlspecialchars($activeDriverTrip['delivery_address']); ?></span>
                    <?php
                    // 送到食物銀行時，附上食物銀行在「設置」填的收貨時間與備註
                    require_once BASE_PATH . '/src/models/FoodbankProfileModel.php';
                    $tripFoodbank = (new FoodbankProfileModel())->get();
                    if (trim((string) $activeDriverTrip['delivery_address']) === trim($tripFoodbank['address'])):
                        $tripReceiving = FoodbankProfileModel::receivingSummary($tripFoodbank); ?>
                        <?php if ($tripReceiving !== ''): ?><span><?php echo htmlspecialchars($tripReceiving); ?></span><?php endif; ?>
                        <?php if ($tripFoodbank['receiving_note'] !== ''): ?><span>收貨備註：<?php echo htmlspecialchars($tripFoodbank['receiving_note']); ?></span><?php endif; ?>
                        <?php if ($tripFoodbank['contact_phone'] !== ''): ?><a class="btn btn-secondary btn-sm" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $tripFoodbank['contact_phone']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-phone"></i> 聯絡食物銀行</a><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
                $tripItemCount = (int) ($activeDriverTrip['item_count'] ?? 0);
                $tripDimensionParts = array_filter([
                    $activeDriverTrip['max_length_cm'] ?? null,
                    $activeDriverTrip['max_width_cm'] ?? null,
                    $activeDriverTrip['max_height_cm'] ?? null,
                ], static function ($value) {
                    return $value !== null && $value !== '';
                });
            ?>
            <?php if ($tripItemCount > 0): ?>
                <div class="driver-trip-stop">
                    <i class="fas fa-box"></i>
                    <div>
                        <strong>物資規格</strong>
                        <span><?php echo $tripItemCount; ?> 項物資</span>
                        <span>長寬高：<?php echo count($tripDimensionParts) === 3 ? htmlspecialchars(implode(' × ', $tripDimensionParts)) . ' cm' : '未填寫'; ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php
                $tripWeight = (float) ($activeDriverTrip['total_item_weight_kg'] ?? 0) ?: (float) ($activeDriverTrip['weight_kg'] ?? 0);
                $tripStorageLevel = (int) ($activeDriverTrip['storage_level'] ?? 1);
                $tripStorageText = [2 => '含冷藏物資，請準備保冷袋或冰桶、盡快送達', 3 => '含冷凍物資，請準備保冷袋與冰塊、盡快送達'][$tripStorageLevel] ?? '';
            ?>
            <?php if ($tripWeight > 0 || $tripStorageText !== ''): ?>
                <div class="driver-trip-stop">
                    <i class="fas fa-weight-hanging"></i>
                    <div>
                        <strong>重量與保存</strong>
                        <?php if ($tripWeight > 0): ?><span>總重約 <?php echo htmlspecialchars(rtrim(rtrim(number_format($tripWeight, 2, '.', ''), '0'), '.')); ?> 公斤</span><?php endif; ?>
                        <?php if ($tripStorageText !== ''): ?><span><?php echo htmlspecialchars($tripStorageText); ?></span><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="driver-trip-stop" data-trip-eta data-destination="<?php echo htmlspecialchars($tripDestinationAddress, ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fas fa-gauge-high"></i>
                <div>
                    <strong>路程資訊</strong>
                    <?php if (!empty($activeDriverTrip['total_distance_km'])): ?><span>全程約 <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $activeDriverTrip['total_distance_km'], 1, '.', ''), '0'), '.')); ?> 公里</span><?php endif; ?>
                    <span data-trip-eta-text>允許定位後，會顯示距離目的地的剩餘距離與時間</span>
                </div>
            </div>
            <?php $tripOpenDays = DonationModel::formatOpenDays($activeDriverTrip['donor_open_days'] ?? ''); ?>
            <?php if (!empty($activeDriverTrip['donor_business_hours']) || $tripOpenDays !== '' || !empty($activeDriverTrip['donor_closed_days']) || !empty($activeDriverTrip['donor_pickup_note'])): ?>
                <div class="driver-trip-stop">
                    <i class="fas fa-clock"></i>
                    <div>
                        <strong>店家取貨資訊</strong>
                        <?php if ($tripOpenDays !== ''): ?><span>營業日：<?php echo htmlspecialchars($tripOpenDays); ?></span><?php endif; ?>
                        <?php if (!empty($activeDriverTrip['donor_business_hours'])): ?><span>營業時間：<?php echo htmlspecialchars($activeDriverTrip['donor_business_hours']); ?></span><?php endif; ?>
                        <?php if (!empty($activeDriverTrip['donor_closed_days'])): ?><span>公休日：<?php echo htmlspecialchars($activeDriverTrip['donor_closed_days']); ?></span><?php endif; ?>
                        <?php if (!empty($activeDriverTrip['donor_pickup_note'])): ?><span>取貨備註：<?php echo htmlspecialchars($activeDriverTrip['donor_pickup_note']); ?></span><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($activeDriverTrip['donation_notes']) || !empty($activeDriverTrip['donor_phone'])): ?>
                <div class="driver-trip-stop">
                    <i class="fas fa-circle-info"></i>
                    <div>
                        <strong>貨況與備註</strong>
                        <?php if (!empty($activeDriverTrip['donation_notes'])): ?><span><?php echo nl2br(htmlspecialchars($activeDriverTrip['donation_notes'])); ?></span><?php endif; ?>
                        <?php if (!empty($activeDriverTrip['donor_phone'])): ?><a class="btn btn-secondary btn-sm" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $activeDriverTrip['donor_phone']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-phone"></i> 聯絡商家</a><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($hasOtherStop): ?>
                <div class="driver-trip-note"><i class="fas fa-circle-info"></i> 這趟路線中，您沿途還有一站停靠點，請依序完成配送。</div>
            <?php endif; ?>

            <?php if ($activeDriverTrip['status'] === 'claimed'): ?>
                <form method="post" class="driver-trip-action-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="arrive_pickup">
                    <input type="hidden" name="delivery_id" value="<?php echo (int) $activeDriverTrip['delivery_id']; ?>">
                    <button type="submit" class="driver-action-button"><i class="fas fa-store"></i> 已到店取貨</button>
                </form>
                <form method="post" class="delivery-action-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="report_exception">
                    <input type="hidden" name="delivery_id" value="<?php echo (int) $activeDriverTrip['delivery_id']; ?>">
                    <input name="exception_notes" placeholder="取件異常原因（例如找不到商家、物資短少）" required>
                    <button class="btn btn-danger btn-sm" type="submit">回報異常</button>
                </form>
            <?php elseif ($tripIsPickupPhase): ?>
                <div class="driver-trip-note"><i class="fas fa-camera"></i> 請先拍攝取得的物資照片，上傳後才能開始配送。</div>
                <form method="post" class="driver-trip-action-form" enctype="multipart/form-data" data-pickup-form>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="confirm_pickup">
                    <input type="hidden" name="delivery_id" value="<?php echo (int) $activeDriverTrip['delivery_id']; ?>">
                    <div class="driver-trip-checklist">
                        <label>取貨照片（拍下取得的物資）<input type="file" name="pickup_photo" accept="image/png,image/jpeg,image/webp" capture="environment" data-pickup-photo required></label>
                    </div>
                    <button type="submit" class="driver-action-button" data-pickup-submit><i class="fas fa-box-open"></i> 確認取貨 / 開始配送</button>
                </form>
                <form method="post" class="delivery-action-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="report_exception">
                    <input type="hidden" name="delivery_id" value="<?php echo (int) $activeDriverTrip['delivery_id']; ?>">
                    <input name="exception_notes" placeholder="取件異常原因（例如找不到商家、物資短少）" required>
                    <button class="btn btn-danger btn-sm" type="submit">回報異常</button>
                </form>
            <?php else: ?>
                <div class="driver-trip-note">
                    <i class="fas fa-circle-info"></i>
                    送達食物銀行後，請將物資交給食物銀行管理員；由食物銀行管理員確認「已抵達」並清點物資後，任務才算完成。
                    <?php if (!empty($activeDriverTrip['estimated_arrival_start']) && !empty($activeDriverTrip['estimated_arrival_end'])): ?>
                        預估送達時間：<?php echo htmlspecialchars(date('H:i', strtotime($activeDriverTrip['estimated_arrival_start'])) . '–' . date('H:i', strtotime($activeDriverTrip['estimated_arrival_end']))); ?>
                    <?php endif; ?>
                </div>
                <form method="post" class="delivery-action-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="report_exception">
                    <input type="hidden" name="delivery_id" value="<?php echo (int) $activeDriverTrip['delivery_id']; ?>">
                    <input name="exception_notes" placeholder="配送異常原因（例如找不到地址、無人收貨）" required>
                    <button class="btn btn-danger btn-sm" type="submit">回報異常</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="view-header">
    <div>
        <h1 class="view-title"><?php echo $page === 'material_transport' ? '物資運送' : ($isVolunteer ? '可接配送' : '配送任務'); ?></h1>
        <p class="view-subtitle"><?php echo $page === 'material_transport' ? '查看、接取與追蹤物資運送任務' : ($isVolunteer ? '查看、接取與追蹤自己的配送任務' : '發布、管理與追蹤惜食配送任務'); ?></p>
    </div>
    <div class="btn-group">
        <?php if ($canManageDeliveries): ?>
            <button type="button" class="btn btn-primary" data-modal-open="createDeliveryModal"><i class="fas fa-bullhorn"></i> 發布配送任務</button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" data-modal-open="rewardRulesModal"><i class="fas fa-gift"></i> 獎勵機制</button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<?php if ($canManageDeliveries): ?>
<div class="app-modal-overlay" id="createDeliveryModal" role="dialog" aria-modal="true" aria-labelledby="createDeliveryTitle" hidden>
    <div class="app-modal">
        <div class="app-modal-header">
            <div><h2 id="createDeliveryTitle">發布配送任務</h2><p>依獎勵機制自動計算心意幣</p></div>
            <button type="button" class="icon-btn" data-modal-close aria-label="關閉"><i class="fas fa-times"></i></button>
        </div>
        <div class="app-modal-body">
            <form method="post">
                <input type="hidden" name="action" value="create_delivery">
                <div class="form-group"><label>關聯捐贈編號</label><input type="number" name="donation_id" min="0" max="2147483647" placeholder="可留白"></div>
                <div class="form-group"><label>配送方式*</label><select name="delivery_method" required><option value="food_bank">忠信派車</option><option value="volunteer" selected>GO Rider 派車</option></select></div>
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
</div>
<?php endif; ?>

<div class="app-modal-overlay" id="rewardRulesModal" role="dialog" aria-modal="true" aria-labelledby="rewardRulesTitle" hidden>
    <div class="app-modal">
        <div class="app-modal-header">
            <div><h2 id="rewardRulesTitle">獎勵機制</h2><p>GO Rider 完成配送可獲得心意幣，心意幣不具現金兌換功能</p></div>
            <button type="button" class="icon-btn" data-modal-close aria-label="關閉"><i class="fas fa-times"></i></button>
        </div>
        <div class="app-modal-body">
            <ul class="feature-list">
                <li>基本：機車 3 枚、汽車 5 枚</li>
                <li>重量每 5 公斤 +3（最多 +15），距離 2／5／10 公里以上依序 +2／+4／+6</li>
                <li>商家申請並經食物銀行同意的緊急運送 +4，冷藏冷凍物資 +2</li>
                <li>完成後另有準時 +1、順路每多一站 +1、首次配送 +3</li>
                <li>食物銀行確認收貨後才會入帳</li>
            </ul>
        </div>
    </div>
</div>

<?php if ($editingDelivery): ?>
<div class="card mt-32">
    <div class="card-header"><h2>編輯配送任務</h2><p>更新任務內容與點數計算</p></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo (int) $editingDelivery['delivery_id']; ?>">
            <div class="grid-2">
                <div class="form-group"><label>關聯捐贈編號</label><input type="number" name="donation_id" min="0" value="<?php echo (int) ($editingDelivery['donation_id'] ?? 0); ?>"></div>
                <div class="form-group"><label>運送方式*</label><select name="delivery_method" required>
                    <option value="food_bank" <?php echo ($editingDelivery['delivery_method'] ?? 'volunteer') === 'food_bank' ? 'selected' : ''; ?>>忠信派車</option>
                    <option value="volunteer" <?php echo ($editingDelivery['delivery_method'] ?? 'volunteer') === 'volunteer' ? 'selected' : ''; ?>>GO Rider 派車</option>
                </select></div>
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
                <div class="form-group"><label>任務狀態</label><select name="status" required>
                    <?php
                    $statusLabels = ['open' => '待接單', 'claimed' => '已接單', 'picked_up' => '已取貨', 'exception' => '異常待處理', 'cancelled' => '已取消'];
                    foreach ($statusLabels as $statusValue => $statusLabel):
                    ?>
                        <option value="<?php echo $statusValue; ?>" <?php echo ($editingDelivery['status'] ?? 'open') === $statusValue ? 'selected' : ''; ?>><?php echo $statusLabel; ?></option>
                    <?php endforeach; ?>
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

<?php
    // 配送進度總覽：依實際狀態統計目前看得到的任務數量（食物銀行看全部、GO Rider 看自己的與可接的）
    $flowCounts = ['open' => 0, 'pickup' => 0, 'transit' => 0, 'delivered' => count($completedDeliveries), 'exception' => 0];
    foreach ($pendingDeliveries as $flowDelivery) {
        $flowStatus = $flowDelivery['status'];
        if ($flowStatus === 'open' && ($flowDelivery['delivery_method'] ?? '') !== 'food_bank') {
            $flowCounts['open']++;
        } elseif (in_array($flowStatus, ['open', 'claimed', 'arrived_pickup'], true)) {
            $flowCounts['pickup']++;
        } elseif (in_array($flowStatus, ['picked_up', 'in_transit'], true)) {
            $flowCounts['transit']++;
        } elseif ($flowStatus === 'exception') {
            $flowCounts['exception']++;
        }
    }
    $flowSteps = [
        ['key' => 'open', 'icon' => 'fa-clipboard-list', 'title' => '待接單', 'desc' => '等待 GO Rider 接單'],
        ['key' => 'pickup', 'icon' => 'fa-motorcycle', 'title' => '前往取貨', 'desc' => '已接單、忠信派車或已到店'],
        ['key' => 'transit', 'icon' => 'fa-truck-fast', 'title' => '運送中', 'desc' => '已拍照取貨，前往送達'],
        ['key' => 'delivered', 'icon' => 'fa-circle-check', 'title' => '已送達', 'desc' => '食物銀行確認收貨'],
    ];
?>
<div class="card mt-32 delivery-flow-card">
    <div class="card-header delivery-flow-header">
        <div><h2>配送進度總覽</h2><p>從接單、取貨到送達，各階段目前的任務數量</p></div>
        <?php if ($flowCounts['exception'] > 0): ?>
            <span class="status status-exception"><i class="fas fa-triangle-exclamation"></i> 異常待處理 <?php echo $flowCounts['exception']; ?> 筆</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <ol class="delivery-flow">
            <?php foreach ($flowSteps as $flowStep): $flowCount = $flowCounts[$flowStep['key']]; ?>
                <li class="delivery-flow-step<?php echo $flowCount > 0 ? ' has-items' : ''; ?><?php echo $flowStep['key'] === 'delivered' ? ' is-final' : ''; ?>">
                    <span class="delivery-flow-icon"><i class="fas <?php echo $flowStep['icon']; ?>"></i></span>
                    <strong><?php echo $flowStep['title']; ?></strong>
                    <small><?php echo $flowStep['desc']; ?></small>
                    <span class="delivery-flow-count"><?php echo $flowCount; ?> 筆</span>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>未完成任務</h2><p>目前共 <?php echo count($pendingDeliveries); ?> 筆任務</p></div>
    <div class="card-body deliveries-table-body">
        <?php if ($pendingDeliveries): ?>
            <table class="data-table deliveries-table"><thead><tr><th>訂單編號</th><th>路線</th><th>運送方式</th><th>交通</th><th>距離</th><th>重量</th><th>物資規格</th><th>任務類型</th><th>點數</th><th>狀態</th><th>異常回報</th><th>編輯配送任務</th></tr></thead><tbody>
            <?php foreach ($pendingDeliveries as $delivery): ?>
                <tr id="delivery-<?php echo (int) $delivery['delivery_id']; ?>">
                    <td><code><?php echo htmlspecialchars($delivery['order_number'] ?? '未關聯'); ?></code></td>
                    <td class="delivery-route-cell" title="<?php echo htmlspecialchars($delivery['pickup_address'] . ' → ' . $delivery['delivery_address'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="delivery-route">
                            <span class="delivery-route-point"><strong>起點</strong><?php echo htmlspecialchars($delivery['pickup_address']); ?></span>
                            <span class="delivery-route-arrow" aria-hidden="true">→</span>
                            <span class="delivery-route-point"><strong>終點</strong><?php echo htmlspecialchars($delivery['delivery_address']); ?></span>
                        </div>
                    </td>
                    <td><?php echo ['food_bank' => '忠信派車', 'volunteer' => 'GO Rider 派車', 'donor' => '忠信派車'][$delivery['delivery_method'] ?? 'volunteer']; ?></td>
                    <td><?php echo $delivery['vehicle_type'] === 'car' ? '汽車' : '機車'; ?></td>
                    <td><?php echo htmlspecialchars($delivery['total_distance_km']); ?> km</td>
                    <td><?php echo htmlspecialchars($delivery['weight_kg']); ?> kg</td>
                    <td>
                        <?php
                            $rowItemCount = (int) ($delivery['item_count'] ?? 0);
                            $rowDimensionParts = array_filter([
                                $delivery['max_length_cm'] ?? null,
                                $delivery['max_width_cm'] ?? null,
                                $delivery['max_height_cm'] ?? null,
                            ], static function ($value) {
                                return $value !== null && $value !== '';
                            });
                        ?>
                        <?php if ($rowItemCount > 0): ?>
                            <?php echo $rowItemCount; ?> 項<?php if (count($rowDimensionParts) === 3): ?><br><small><?php echo htmlspecialchars(implode(' × ', $rowDimensionParts)); ?> cm</small><?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?php echo ['normal' => '一般', 'priority' => '優先', 'urgent' => '急件'][$delivery['urgency']] ?? '一般'; ?></td>
                    <td><strong><?php echo (int) $delivery['points']; ?> 點</strong></td>
                    <td>
                        <span class="status status-<?php echo htmlspecialchars($delivery['status']); ?>"><?php echo ($delivery['delivery_method'] ?? '') === 'food_bank' && in_array($delivery['status'], ['open', 'claimed'], true) ? '忠信派車中' : (['open' => '待接單', 'claimed' => '已接單', 'arrived_pickup' => '已到店', 'waiting_pickup' => '待收貨', 'collected' => '已收取', 'picked_up' => '已取貨', 'in_transit' => '配送中', 'delivered' => '已配達', 'exception' => '異常待處理', 'cancelled' => '已取消'][$delivery['status']] ?? $delivery['status']); ?></span>
                        <?php if ($isOfficial && !empty($delivery['pickup_photo_path'])): ?>
                            <br><a href="<?php echo htmlspecialchars(storedPublicImageUrl($delivery['pickup_photo_path']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">取貨照片</a>
                        <?php endif; ?>
                        <?php if ($isOfficial && ($delivery['delivery_method'] ?? '') !== 'food_bank' && !empty($delivery['volunteer_id']) && in_array($delivery['status'], ['claimed', 'arrived_pickup', 'picked_up'], true)): ?>
                            <br><button type="button" class="btn btn-secondary btn-sm" data-rider-location data-delivery-id="<?php echo (int) $delivery['delivery_id']; ?>"><i class="fas fa-location-crosshairs"></i> GO Rider 位置</button>
                        <?php endif; ?>
                        <?php if (($delivery['delivery_method'] ?? '') === 'food_bank'): ?>
                            <br><small>派送：忠信食物銀行</small>
                        <?php elseif ($isOfficial && !empty($delivery['volunteer_id'])): ?>
                            <br><small>GO Rider：<?php echo htmlspecialchars($delivery['volunteer_name'] ?? ''); ?>
                            <?php if (!empty($delivery['volunteer_phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($delivery['volunteer_phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($delivery['volunteer_phone']); ?></a>
                            <?php else: ?>（未提供電話）<?php endif; ?></small>
                        <?php endif; ?>
                        <?php if ($isOfficial && !empty($delivery['estimated_arrival_start']) && !empty($delivery['estimated_arrival_end']) && $delivery['status'] === 'picked_up'): ?>
                            <?php $isOverdue = strtotime($delivery['estimated_arrival_end']) < time(); ?>
                            <br><small>預估送達 <?php echo htmlspecialchars(date('H:i', strtotime($delivery['estimated_arrival_start'])) . '–' . date('H:i', strtotime($delivery['estimated_arrival_end']))); ?></small>
                            <?php if ($isOverdue): ?><br><span class="status status-exception">已逾時</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="delivery-exception-cell">
                        <?php if ($delivery['status'] === 'exception' || !empty($delivery['exception_notes']) || !empty($delivery['exception_response'])): ?>
                            <?php if ($canManageDeliveries): ?>
                                <form method="post" class="delivery-action-form delivery-exception-form">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_exception">
                                    <input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>">
                                    <textarea name="exception_notes" rows="2" placeholder="配送會員異常回報"><?php echo htmlspecialchars($delivery['exception_notes'] ?? ''); ?></textarea>
                                    <textarea name="exception_response" rows="2" placeholder="官方處理回覆"><?php echo htmlspecialchars($delivery['exception_response'] ?? ''); ?></textarea>
                                    <button class="btn btn-secondary btn-sm" type="submit">儲存異常回報</button>
                                </form>
                            <?php else: ?>
                                <div class="delivery-exception-details"><?php echo htmlspecialchars($delivery['exception_notes'] ?? ''); ?></div>
                            <?php endif; ?>
                            <?php if ($canManageDeliveries && in_array($delivery['status'], ['exception', 'cancelled'], true)): ?>
                                <form method="post" class="delivery-action-form delivery-exception-form">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="<?php echo $delivery['status'] === 'cancelled' ? 'reject_exception' : 'resolve_exception'; ?>">
                                    <input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>">
                                    <textarea name="exception_response" rows="2" placeholder="填寫處理結果或給配送會員的回覆" required></textarea>
                                    <?php if ($delivery['status'] === 'exception'): ?>
                                        <select name="exception_next_status">
                                            <option value="claimed">恢復配送</option>
                                            <option value="cancelled">取消任務</option>
                                        </select>
                                        <button class="btn btn-warning btn-sm" type="submit">回覆並處理異常</button>
                                    <?php else: ?>
                                        <button class="btn btn-warning btn-sm" type="submit">駁回取消，恢復配送</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">無異常回報</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="delivery-action-stack">
                            <?php if ($isVolunteer && ($delivery['delivery_method'] ?? 'volunteer') === 'volunteer' && $delivery['status'] === 'open'): ?><form method="post" class="delivery-action-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="claim_delivery"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><button class="btn btn-primary btn-sm" type="submit">接單</button></form><?php endif; ?>
                            <?php if ($isVolunteer && in_array($delivery['status'], ['claimed', 'picked_up'], true) && (int) $delivery['volunteer_id'] === $currentUserId): ?>
                                <span class="text-muted"><i class="fas fa-arrow-up"></i> 請至上方「配送中任務」卡片操作</span>
                            <?php endif; ?>
                            <?php $canCompleteDelivery = $canConfirmArrival && (($delivery['delivery_method'] ?? '') === 'food_bank' && $delivery['status'] === 'open' || in_array($delivery['status'], ['claimed', 'picked_up', 'in_transit'], true)); ?>
                            <?php if ($canCompleteDelivery): ?><form method="post" class="delivery-action-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="complete_delivery"><input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>"><button class="btn btn-success btn-sm"><?php echo ($delivery['delivery_method'] ?? '') === 'food_bank' ? '確認忠信派車已完成' : '已抵達，確認收貨'; ?></button></form><?php endif; ?>
                            <?php if ($canManageDeliveries): ?>
                                <div class="inline-action-group">
                                    <form method="post" class="delivery-action-form">
                                        <?php echo csrfField(); ?>
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
        <?php else: ?><div class="empty-state"><i class="fas fa-route"></i><p>目前沒有未完成任務</p></div><?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>已完成任務</h2><p>目前共 <?php echo count($completedDeliveries); ?> 筆任務</p></div>
    <div class="card-body deliveries-table-body">
        <?php if ($completedDeliveries): ?>
            <table class="data-table deliveries-table completed-deliveries-table"><thead><tr><th>路線</th><th>運送方式</th><th>交通</th><th>距離</th><th>重量</th><th>任務類型</th><th>點數</th><th>完成日期與時間</th><th>狀態</th><?php if ($isOfficial): ?><th>感謝商家</th><?php endif; ?></tr></thead><tbody>
            <?php $thanksSent = $isOfficial ? array_map('intval', $thanksModel->sentDeliveryIds()) : []; ?>
            <?php foreach ($completedDeliveries as $delivery): ?>
                <tr id="delivery-<?php echo (int) $delivery['delivery_id']; ?>">
                    <td class="delivery-route-cell" title="<?php echo htmlspecialchars($delivery['pickup_address'] . ' → ' . $delivery['delivery_address'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="delivery-route">
                            <span class="delivery-route-point"><strong>起點</strong><?php echo htmlspecialchars($delivery['pickup_address']); ?></span>
                            <span class="delivery-route-arrow" aria-hidden="true">→</span>
                            <span class="delivery-route-point"><strong>終點</strong><?php echo htmlspecialchars($delivery['delivery_address']); ?></span>
                        </div>
                    </td>
                    <td><?php echo ['food_bank' => '忠信派車', 'volunteer' => 'GO Rider 派車', 'donor' => '忠信派車'][$delivery['delivery_method'] ?? 'volunteer']; ?></td>
                    <td><?php echo $delivery['vehicle_type'] === 'car' ? '汽車' : '機車'; ?></td>
                    <td><?php echo htmlspecialchars($delivery['total_distance_km']); ?> km</td>
                    <td><?php echo htmlspecialchars($delivery['weight_kg']); ?> kg</td>
                    <td><?php echo ['normal' => '一般', 'priority' => '優先', 'urgent' => '急件'][$delivery['urgency']] ?? '一般'; ?></td>
                    <td><strong><?php echo (int) $delivery['points']; ?> 點</strong></td>
                    <td><?php echo htmlspecialchars($delivery['delivered_at'] ?? ''); ?></td>
                    <td><span class="status status-delivered">已配達</span></td>
                    <?php if ($isOfficial): ?>
                        <td>
                            <?php if (in_array((int) $delivery['delivery_id'], $thanksSent, true)): ?>
                                已送出感謝
                            <?php elseif (!empty($delivery['donation_id'])): ?>
                                <form method="post" class="delivery-action-form">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="send_thanks">
                                    <input type="hidden" name="delivery_id" value="<?php echo (int) $delivery['delivery_id']; ?>">
                                    <input name="thanks_message" maxlength="300" placeholder="寫一句感謝的話" required>
                                    <label><input type="checkbox" name="thanks_public" value="1" checked> 公開</label>
                                    <button class="btn btn-secondary btn-sm" type="submit">送出</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?><div class="empty-state"><i class="fas fa-check-circle"></i><p>目前沒有已完成任務</p></div><?php endif; ?>
    </div>
</div>

<div class="map-modal-overlay" id="mapModal" role="dialog" aria-modal="true" aria-label="地圖" hidden>
    <div class="map-modal">
        <button type="button" class="icon-btn map-modal-close" id="mapModalClose" aria-label="關閉地圖"><i class="fas fa-times"></i></button>
        <iframe id="mapModalFrame" title="地圖" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('mapModal');
    var frame = document.getElementById('mapModalFrame');
    if (!modal) { return; }
    function close() { modal.hidden = true; frame.removeAttribute('src'); }
    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-map-modal-open]');
        if (opener) {
            frame.src = 'https://www.google.com/maps?q=' + encodeURIComponent(opener.getAttribute('data-map-query')) + '&output=embed&hl=zh-TW';
            modal.hidden = false;
        } else if (event.target === modal || event.target.closest('#mapModalClose')) {
            close();
        }
    });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) { close(); } });
})();
</script>
