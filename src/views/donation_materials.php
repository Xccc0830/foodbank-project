<?php
require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/models/DeliveryModel.php';
require_once BASE_PATH . '/src/helpers/UploadHelper.php';
require_once BASE_PATH . '/src/helpers/DistanceHelper.php';
require_once BASE_PATH . '/src/helpers/AddressHelper.php';
require_once BASE_PATH . '/src/models/NotificationModel.php';

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$notificationModel = new NotificationModel();
$connection = $db->getConnection();
$currentUserId = isset($currentUser['user_id']) ? (int) $currentUser['user_id'] : 0;
$donorName = trim((string) ($currentUser['enterprise_name'] ?? $currentUser['full_name'] ?? ''));
$deliveryAddress = array_key_exists('delivery_address', $_POST)
    ? trim((string) $_POST['delivery_address'])
    : '臺北市文山區明義里興隆路四段42巷3號1樓';
$itemCategoryOptions = [];
$categoryResult = $connection->query("SELECT category_id, category_name FROM item_categories WHERE status = 'active' ORDER BY display_order, category_id");
while ($categoryResult && ($categoryRow = $categoryResult->fetch_assoc())) {
    $itemCategoryOptions[] = $categoryRow;
}
$formMessage = null;
$defaultPickupAddress = $donationModel->getDefaultPickupAddress($currentUserId);
$viewingDonation = null;
$viewingItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_material_donation') {
    $deliveryOption = 'volunteer_delivery';
    $deliveryMethod = 'volunteer_assist';
    $deliveryDate = trim((string) ($_POST['delivery_date'] ?? '')) ?: null;
    $deliveryTime = trim((string) ($_POST['delivery_time'] ?? '')) ?: null;
    $donorAddress = trim((string) ($_POST['donor_address'] ?? ''));
    if ($donorAddress === '') {
        $donorAddress = $defaultPickupAddress;
    }
    $deliveryAddress = trim((string) ($_POST['delivery_address'] ?? ''));
    $estimatedDistanceKm = is_numeric($_POST['estimated_distance_km'] ?? null) ? round((float) $_POST['estimated_distance_km'], 2) : null;
    $estimatedDurationMinutes = is_numeric($_POST['estimated_duration_minutes'] ?? null) ? (int) round((float) $_POST['estimated_duration_minutes']) : null;
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $itemNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];
    $itemCategories = is_array($_POST['item_category'] ?? null) ? $_POST['item_category'] : [];
    $itemStorages = is_array($_POST['item_storage'] ?? null) ? $_POST['item_storage'] : [];
    $itemPackages = is_array($_POST['item_package'] ?? null) ? $_POST['item_package'] : [];
    $itemQuantities = is_array($_POST['item_quantity'] ?? null) ? $_POST['item_quantity'] : [];
    $itemUnits = is_array($_POST['item_unit'] ?? null) ? $_POST['item_unit'] : [];
    $itemLengths = is_array($_POST['item_length_cm'] ?? null) ? $_POST['item_length_cm'] : [];
    $itemWidths = is_array($_POST['item_width_cm'] ?? null) ? $_POST['item_width_cm'] : [];
    $itemHeights = is_array($_POST['item_height_cm'] ?? null) ? $_POST['item_height_cm'] : [];
    $itemWeights = is_array($_POST['item_weight_kg'] ?? null) ? $_POST['item_weight_kg'] : [];
    $itemExpiryDates = is_array($_POST['item_expiry_date'] ?? null) ? $_POST['item_expiry_date'] : [];
    $itemNotes = is_array($_POST['item_notes'] ?? null) ? $_POST['item_notes'] : [];
    $items = [];
    foreach ($itemNames as $index => $rawName) {
        $itemName = trim((string) $rawName);
        $quantity = (float) ($itemQuantities[$index] ?? 0);
        if ($itemName === '' && $quantity <= 0) {
            continue;
        }
        $items[] = [
            'item_name' => $itemName,
            'category_id' => (int) ($itemCategories[$index] ?? 0) ?: null,
            'storage_condition' => in_array($itemStorages[$index] ?? '', ['room', 'chilled', 'frozen'], true) ? $itemStorages[$index] : 'room',
            'package_state' => in_array($itemPackages[$index] ?? '', ['sealed', 'opened'], true) ? $itemPackages[$index] : 'sealed',
            'quantity' => $quantity,
            'unit' => trim((string) ($itemUnits[$index] ?? '件')) ?: '件',
            'length_cm' => is_numeric($itemLengths[$index] ?? null) ? round((float) $itemLengths[$index], 2) : null,
            'width_cm' => is_numeric($itemWidths[$index] ?? null) ? round((float) $itemWidths[$index], 2) : null,
            'height_cm' => is_numeric($itemHeights[$index] ?? null) ? round((float) $itemHeights[$index], 2) : null,
            'item_weight_kg' => is_numeric($itemWeights[$index] ?? null) ? round((float) $itemWeights[$index], 2) : null,
            'expiry_date' => trim((string) ($itemExpiryDates[$index] ?? '')) ?: null,
            'notes' => trim((string) ($itemNotes[$index] ?? '')) ?: null,
        ];
    }

    $firstItem = $items[0] ?? null;
    $itemName = $firstItem['item_name'] ?? '';
    if (count($items) > 1) {
        $itemName .= ' 等 ' . count($items) . ' 項';
    }
    $quantity = (float) ($firstItem['quantity'] ?? 0);
    $unit = $firstItem['unit'] ?? '件';
    $donationType = 'food';
    $normalizedWeight = 0;
    $sizeDescription = null;
    $expiryDate = $firstItem['expiry_date'] ?? null;
    $pickupDeadline = $deliveryDate && $deliveryTime ? $deliveryDate . ' ' . $deliveryTime . ':00' : null;

    $vehicleTypeValue = 'none';

    $photoPath = null;
    if (!empty($_FILES['photo']['name'])) {
        $photoFiles = $_FILES['photo'];
        $uploadedPaths = [];

        if (is_array($photoFiles['name'])) {
            foreach ($photoFiles['name'] as $index => $fileName) {
                if ($fileName === '') {
                    continue;
                }

                $singleFile = [
                    'name' => $photoFiles['name'][$index],
                    'type' => $photoFiles['type'][$index],
                    'tmp_name' => $photoFiles['tmp_name'][$index],
                    'error' => $photoFiles['error'][$index],
                    'size' => $photoFiles['size'][$index],
                ];

                $uploadedPath = uploadDonationPhoto($singleFile);
                if ($uploadedPath !== false) {
                    $uploadedPaths[] = $uploadedPath;
                }
            }
        } else {
            $uploadedPath = uploadDonationPhoto($photoFiles);
            if ($uploadedPath !== false) {
                $uploadedPaths[] = $uploadedPath;
            }
        }

        if (!empty($uploadedPaths)) {
            $photoPath = implode(',', $uploadedPaths);
        }
    }

    $donationData = [
        'donor_id' => $currentUserId,
        'donor_name' => $donorName,
        'donor_address' => $donorAddress !== '' ? $donorAddress : null,
        'donation_type' => $donationType,
        'quantity' => $quantity,
        'unit' => $unit,
        'donation_date' => date('Y-m-d H:i:s'),
        'received_by' => null,
        'status' => 'pending',
        'evaluation_status' => 'pending',
        'delivery_method' => $deliveryMethod,
        'approval_notes' => null,
        'approved_at' => null,
        'approved_by' => null,
        'rejection_reason' => null,
        'rejected_at' => null,
        'published_at' => null,
        'current_status' => 'waiting_pickup',
        'status_updated_at' => null,
        'split_count' => 1,
        'notes' => $notes,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'item_name' => $itemName,
        'weight_kg' => $normalizedWeight,
        'size_description' => $sizeDescription,
        'expiry_date' => $expiryDate,
        'pickup_deadline' => $pickupDeadline,
        'delivery_option' => $deliveryOption,
        'vehicle_type' => $vehicleTypeValue === 'none' ? 'none' : $vehicleTypeValue,
        'photo_path' => $photoPath,
        'evaluation_notes' => null,
        'need_inspection' => 1,
        'inspection_notes' => null,
        'delivery_date' => $deliveryDate,
        'delivery_time' => $deliveryTime,
        'delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
        'estimated_distance_km' => $estimatedDistanceKm,
        'estimated_duration_minutes' => $estimatedDurationMinutes,
    ];

    $hasInvalidItem = empty($items);
    foreach ($items as $item) {
        if ($item['item_name'] === '' || $item['quantity'] <= 0) {
            $hasInvalidItem = true;
            break;
        }
    }
    $addressError = validateAddress($donorAddress, '取貨地址') ?? validateAddress($deliveryAddress, '送達地址');
    if ($addressError !== null) {
        $formMessage = ['type' => 'error', 'text' => $addressError];
    } elseif ($donorName === '' || !$deliveryDate || !$deliveryTime || $hasInvalidItem) {
        $formMessage = ['type' => 'error', 'text' => '請完成企業資料、日期時間，以及至少一筆物資。'];
    } else {
        $connection->begin_transaction();
        $insertedId = $donationModel->addDonation($donationData);
        if ($insertedId) {
            $itemStatement = $connection->prepare('INSERT INTO donation_items (donation_id, item_name, category_id, storage_condition, package_state, quantity, unit, length_cm, width_cm, height_cm, item_weight_kg, expiry_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $itemsSaved = $itemStatement !== false;
            if ($itemsSaved) {
                foreach ($items as $item) {
                    $itemStatement->bind_param(
                        'isissdsddddss',
                        $insertedId,
                        $item['item_name'],
                        $item['category_id'],
                        $item['storage_condition'],
                        $item['package_state'],
                        $item['quantity'],
                        $item['unit'],
                        $item['length_cm'],
                        $item['width_cm'],
                        $item['height_cm'],
                        $item['item_weight_kg'],
                        $item['expiry_date'],
                        $item['notes']
                    );
                    if (!$itemStatement->execute()) {
                        $itemsSaved = false;
                        break;
                    }
                }
                $itemStatement->close();
            }
            if (!$itemsSaved) {
                $connection->rollback();
                $insertedId = false;
            } else {
                $connection->commit();
            }
        }
        if ($insertedId && $donorAddress !== '' && ($defaultPickupAddress === '' || !empty($_POST['save_default_address']))) {
            $donationModel->setDefaultPickupAddress($currentUserId, $donorAddress);
            $defaultPickupAddress = $donorAddress;
        }
        if ($insertedId) {
            $officialUsers = $connection->query("SELECT user_id FROM users WHERE role = 'foodbank_staff' AND status = 'active'");
            if ($officialUsers) {
                while ($officialUser = $officialUsers->fetch_assoc()) {
                    $notificationModel->notify(
                        (int) $officialUser['user_id'],
                        '新的物資待評估',
                        sprintf('商家 %s 已送出物資「%s」，請至物資捐贈審查處理。', $donationData['donor_name'], $itemName),
                        'info'
                    );
                }
            }
            $formMessage = ['type' => 'success', 'text' => '物資捐贈已送出，待食物銀行評估。'];
        } else {
            $formMessage = ['type' => 'error', 'text' => '送出失敗，請稍後再試。'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resubmit_donation') {
    $resubmitId = (int) ($_POST['donation_id'] ?? 0);
    $resubmitResult = $donationModel->resubmitDonation($resubmitId, $currentUserId, $_POST['fields'] ?? []);
    if ($resubmitResult === true) {
        $formMessage = ['type' => 'success', 'text' => '已重新送審，食物銀行會再次評估。'];
        $resubmitted = $donationModel->getDonationById($resubmitId);
        $officialUsers = $connection->query("SELECT user_id FROM users WHERE role = 'foodbank_staff' AND status = 'active'");
        while ($officialUsers && ($officialUser = $officialUsers->fetch_assoc())) {
            $notificationModel->notify(
                (int) $officialUser['user_id'],
                '物資已重新送審',
                sprintf('商家 %s 已修改並重新送審物資「%s」。', $donorName, $resubmitted['item_name'] ?? ''),
                'info'
            );
        }
    } else {
        $formMessage = ['type' => 'error', 'text' => $resubmitResult];
        $_POST['action'] = 'view_material_details';
        $_POST['donation_id'] = $resubmitId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'view_material_details') {
    $viewingDonation = $donationModel->getDonationById((int) ($_POST['donation_id'] ?? 0));
    if ($viewingDonation) {
        $viewingDonationId = (int) $viewingDonation['donation_id'];
        $itemResult = $connection->query("SELECT * FROM donation_items WHERE donation_id = {$viewingDonationId} ORDER BY item_id ASC");
        if ($itemResult) {
            while ($item = $itemResult->fetch_assoc()) {
                $viewingItems[] = $item;
            }
        }
    }
}


require_once BASE_PATH . '/src/models/ThanksModel.php';
$thanksModel = new ThanksModel();
$receivedThanks = $thanksModel->forDonor($currentUserId, 5);
$donorBadge = $thanksModel->badgeFor($currentUserId);
$merchantDonations = $donationModel->getAllDonations(null, $currentUserId);
$pendingDonations = array_values(array_filter($merchantDonations, static function ($row) {
    return strtolower((string) ($row['status'] ?? '')) === 'pending';
}));
$evaluatedDonations = array_values(array_filter($merchantDonations, static function ($row) {
    return strtolower((string) ($row['status'] ?? '')) === 'assessed'
    && in_array(strtolower((string) ($row['evaluation_status'] ?? '')), ['approved_volunteer', 'approved_self_delivery', 'rejected'], true);
}));
$transportTasks = $deliveryModel->getDonorTransportTasks($currentUserId);
$donationTypeLabels = [
    'food' => '食物',
    'supplies' => '民生用品',
    'money' => '金錢',
    'other' => '其他',
];
$deliveryOptionLabels = [
    'donor_delivery' => '忠信派車',
    'volunteer_delivery' => 'GO Rider 派車',
    'food_bank_pickup' => '忠信派車',
];
$merchantDeliveryOptionLabels = [
    'donor_delivery' => '忠信派車',
    'volunteer_delivery' => 'GO Rider 派車',
    'food_bank_pickup' => '忠信派車',
];
$vehicleTypeLabels = [
    'car' => '汽車',
    'motorcycle' => '機車',
    'none' => '未指定',
];
$formatVehicleTypes = static function ($donation) use ($vehicleTypeLabels) {
    $selectedTypes = [];
    $storedVehicleType = (string) ($donation['vehicle_type'] ?? 'none');
    if ($storedVehicleType !== '' && $storedVehicleType !== 'none') {
        $selectedTypes[] = $vehicleTypeLabels[$storedVehicleType] ?? $storedVehicleType;
    }

    $notes = (string) ($donation['notes'] ?? '');
    if (preg_match('/運送評估(?:選項)?：([^;]+)/u', $notes, $matches)) {
        $noteTypes = array_filter(array_map('trim', explode('、', $matches[1])));
        foreach ($noteTypes as $noteType) {
            if (!in_array($noteType, $selectedTypes, true)) {
                $selectedTypes[] = $noteType;
            }
        }
    }

    return !empty($selectedTypes) ? implode('、', $selectedTypes) : '未指定';
};
$formatDateTime = static function ($value) {
    if (empty($value)) {
        return '未填寫';
    }

    $timestamp = strtotime((string) $value);
    return $timestamp ? date('Y年m月d日 H:i', $timestamp) : '未填寫';
};
?>

<div class="view-header">
    <div>
        <h1 class="view-title">物資捐贈</h1>
        <p class="view-subtitle">提交物資資訊後，將會進入待評估流程。</p>
    </div>
</div>

<?php if ($formMessage): ?>
    <div class="alert alert-<?php echo $formMessage['type']; ?>"><?php echo htmlspecialchars($formMessage['text']); ?></div>
<?php endif; ?>

<?php if ($receivedThanks): ?>
    <div class="card mb-20">
        <div class="card-header">
            <h2><i class="fas fa-heart"></i> 收到的感謝</h2>
            <p>已收到 <?php echo (int) $donorBadge['count']; ?> 則感謝<?php echo $donorBadge['badge'] ? '・目前勳章：' . htmlspecialchars($donorBadge['badge']) : ''; ?></p>
        </div>
        <div class="card-body">
            <?php foreach ($receivedThanks as $thanks): ?>
                <p>「<?php echo htmlspecialchars($thanks['message']); ?>」<small> — <?php echo htmlspecialchars(substr($thanks['created_at'], 0, 10)); ?></small></p>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-20">
    <div class="card-header">
        <div class="toolbar-row">
            <div>
                <h2>新增物資捐贈</h2>
            </div>
            <button class="btn btn-primary btn-sm" type="button" id="toggleDonationFormButton">
                <i class="fas fa-plus"></i> 新增物資捐贈
            </button>
        </div>
    </div>
</div>

<div class="donation-form-overlay" id="donationFormSection" role="dialog" aria-modal="true" aria-labelledby="donationFormTitle">
    <div class="donation-form-modal">
        <div class="card-header">
            <div>
                <h2 id="donationFormTitle">新增物資捐贈</h2>
                <p>請填寫物資資料</p>
            </div>
            <button type="button" class="icon-btn" id="closeDonationFormButton" aria-label="關閉新增物資捐贈">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="card-body">
        <form method="post" enctype="multipart/form-data" data-distance-estimate-form>
            <input type="hidden" name="action" value="add_material_donation">
            <div class="material-stepper" aria-label="新增物資步驟">
                <span class="material-step is-active" data-step-indicator="1">1 配送資訊</span>
                <span class="material-step" data-step-indicator="2">2 物資規格</span>
            </div>

            <section class="material-step-panel is-active" data-step-panel="1">
                <div class="form-section-heading"><span>Step 1</span><h3>配送資訊</h3><p>請填寫取貨地點、預計時間與送達地址；配送方式由食物銀行評估後安排。</p></div>
                <div class="form-section-heading"><span>商家資料</span><h3>企業取貨資訊</h3><p>這是配送人員前往取物資的地點，不是物資送達地址。</p></div>
                <div class="grid-2">
                    <div class="form-group"><label for="donorName">捐贈企業／組織</label><input id="donorName" type="text" value="<?php echo htmlspecialchars($donorName, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
                    <div class="form-group"><label for="donorAddress">商家取貨地址 <span class="required-mark">*</span></label><input id="donorAddress" type="text" name="donor_address" value="<?php echo htmlspecialchars($defaultPickupAddress, ENT_QUOTES, 'UTF-8'); ?>" placeholder="配送人員要到哪裡取貨" required minlength="5" maxlength="255" autocomplete="off" data-address-input data-address-label="取貨地址"></div>
                    <div class="form-group form-check-row"><label><input type="checkbox" name="save_default_address" value="1"<?php echo $defaultPickupAddress === '' ? ' checked' : ''; ?>> 儲存為預設取貨地址（下次自動帶入）</label></div>
                </div>
                <div class="form-section-heading"><span>配送目的地</span><h3>送達資訊</h3><p>這是物資最後要送到的地點。</p></div>
                <div class="grid-2">
                    <div class="form-group"><label for="deliveryDate">預計配送日期 <span class="required-mark">*</span></label><input id="deliveryDate" type="date" name="delivery_date" required></div>
                    <div class="form-group"><label for="deliveryTime">預計配送時間 <span class="required-mark">*</span></label><input id="deliveryTime" type="time" name="delivery_time" required></div>
                </div>
                <div class="form-group"><label for="deliveryAddress">送達地址 <span class="required-mark">*</span></label><input id="deliveryAddress" type="text" name="delivery_address" value="<?php echo htmlspecialchars($deliveryAddress, ENT_QUOTES, 'UTF-8'); ?>" placeholder="物資送到哪裡" required minlength="5" maxlength="255" autocomplete="off" data-address-input data-address-label="送達地址"></div>
                <div class="form-group distance-estimate" id="distanceEstimate" data-distance-estimate hidden>
                    <label>預計運送距離與時間</label>
                    <div class="distance-estimate-box">
                        <span class="distance-estimate-item"><i class="fas fa-route"></i> <span data-distance-value>—</span></span>
                        <span class="distance-estimate-item"><i class="fas fa-clock"></i> <span data-duration-value>—</span></span>
                    </div>
                    <input type="hidden" name="estimated_distance_km" data-distance-input>
                    <input type="hidden" name="estimated_duration_minutes" data-duration-input>
                </div>
                <p class="distance-estimate-status" data-distance-status hidden></p>
                <button type="button" class="btn btn-secondary btn-sm" data-distance-retry hidden><i class="fas fa-rotate"></i> 重新計算距離與時間</button>
                <div class="dispatch-details-note"><i class="fas fa-circle-info"></i> 若由 GO Rider 配送，配送會員接單後才會自動顯示姓名、電話與車輛資訊。</div>
                <div class="form-group"><label for="deliveryNotes">備註</label><textarea id="deliveryNotes" name="notes" rows="3" placeholder="特殊配送需求或補充資訊"></textarea></div>
                <div class="step-actions"><button type="button" class="btn btn-primary" data-next-step>下一步：填寫物資</button></div>
            </section>

            <section class="material-step-panel" data-step-panel="2" hidden>
                <div class="form-section-heading"><span>Step 2</span><h3>物資規格</h3><p>同一筆配送可以加入多種物資。</p></div>
                <div id="materialItemRows">
                    <div class="material-item-row">
                        <div class="grid-2"><div class="form-group"><label>物資名稱 <span class="required-mark">*</span></label><input type="text" name="item_name[]" placeholder="例如：白米 5kg 裝" required></div><div class="form-group"><label>物資類別</label><select name="item_category[]"><option value="">請選擇</option><?php foreach ($itemCategoryOptions as $categoryOption): ?><option value="<?php echo (int) $categoryOption['category_id']; ?>"><?php echo htmlspecialchars($categoryOption['category_name']); ?></option><?php endforeach; ?></select></div></div>
                        <div class="grid-3"><div class="form-group"><label>數量 <span class="required-mark">*</span></label><input type="number" name="item_quantity[]" min="0.01" step="0.01" required></div><div class="form-group"><label>單位</label><select name="item_unit[]"><option>包</option><option>箱</option><option>瓶</option><option>盒</option><option>份</option><option>件</option></select></div><div class="form-group"><label>總重量 (kg)</label><input type="number" name="item_weight_kg[]" min="0" step="0.01" placeholder="例如：5"></div></div>
                        <div class="grid-3"><div class="form-group"><label>保存方式</label><select name="item_storage[]"><option value="room">常溫</option><option value="chilled">冷藏</option><option value="frozen">冷凍</option></select></div><div class="form-group"><label>包裝狀態</label><select name="item_package[]"><option value="sealed">原包裝未拆封</option><option value="opened">已拆封／已分裝</option></select></div><div class="form-group"><label>保存期限</label><input type="date" name="item_expiry_date[]"></div></div>
                        <div class="grid-3"><div class="form-group"><label>長 (cm)</label><input type="number" name="item_length_cm[]" min="0" step="0.1" placeholder="例如：30"></div><div class="form-group"><label>寬 (cm)</label><input type="number" name="item_width_cm[]" min="0" step="0.1" placeholder="例如：20"></div><div class="form-group"><label>高 (cm)</label><input type="number" name="item_height_cm[]" min="0" step="0.1" placeholder="例如：15"></div></div>
                        <div class="material-item-footer"><button type="button" class="btn btn-danger btn-sm" data-remove-item><i class="fas fa-trash"></i> 刪除此物資</button></div>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="addMaterialItemButton"><i class="fas fa-plus"></i> 新增物資</button>
                <div class="form-group"><label>安全驗證：照片上傳</label><input type="file" name="photo[]" accept="image/png,image/jpeg,image/webp" multiple></div>
                <div class="step-actions"><button type="button" class="btn btn-secondary" data-previous-step>上一步</button><button type="submit" class="btn btn-primary">儲存物資</button></div>
            </section>
        </form>
    </div>
</div>
</div>

<div class="card">
    <div class="card-header">
        <h2>待評估</h2>
        <p>已送出的物資捐贈資訊會出現在這裡，狀態為待評估。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($pendingDonations)): ?>
            <table class="data-table donation-pending-table">
                <thead>
                    <tr>
                        <th>狀態</th>
                        <th>企業／組織</th>
                        <th>物資類型</th>
                        <th>名稱</th>
                        <th>配送選擇</th>
                        <th>送出時間</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingDonations as $donation): ?>
                        <tr>
                            <td><span class="status status-pending">待評估</span></td>
                            <td><?php echo htmlspecialchars($donation['donor_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type'] ?? ''] ?? '其他'); ?></td>
                            <td><?php echo htmlspecialchars($donation['item_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($merchantDeliveryOptionLabels[$donation['delivery_option'] ?? ''] ?? '未指定'); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($donation['donation_date']))); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="view_material_details">
                                    <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">查看</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>目前尚無待評估物資</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-20">
    <div class="card-header">
        <h2>評估結果</h2>
        <p>食物銀行完成評估的物資會顯示在這裡。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($evaluatedDonations)): ?>
            <table class="data-table donation-evaluated-table">
                <thead>
                    <tr>
                        <th>狀態</th>
                        <th>物資類型</th>
                        <th>名稱</th>
                        <th>配送選擇</th>
                        <th>評估時間</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($evaluatedDonations as $donation): ?>
                        <?php $isRejectedResult = ($donation['evaluation_status'] ?? '') === 'rejected'; ?>
                        <tr>
                            <td><span class="status <?php echo $isRejectedResult ? 'status-rejected' : 'status-approved'; ?>"><?php echo $isRejectedResult ? '未通過' : '接受'; ?></span></td>
                            <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type'] ?? ''] ?? '其他'); ?></td>
                            <td><?php echo htmlspecialchars($donation['item_name'] ?? '未填寫'); ?></td>
                            <td><?php echo htmlspecialchars($deliveryOptionLabels[$donation['delivery_option'] ?? ''] ?? '未指定'); ?></td>
                            <td><?php echo htmlspecialchars($formatDateTime($isRejectedResult ? ($donation['rejected_at'] ?? null) : ($donation['approved_at'] ?? null))); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="view_material_details">
                                    <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">查看詳情</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>目前沒有評估結果</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-20">
    <div class="card-header">
        <h2>我的配送進度</h2>
        <p>查看自己捐贈物資的配送狀態，並確認配送會員是否已領取物資。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($transportTasks)): ?>
            <table class="data-table donation-transport-table">
                <thead><tr><th>店家名稱</th><th>物資類型</th><th>名稱</th><th>數量</th><th>狀態與配送資訊</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($transportTasks as $task): ?>
                    <?php
                    $transportStatus = $task['status'] ?? 'open';
                    $transportStatusLabel = $transportStatus === 'open'
                        ? '尚在等待運送人員'
                        : (in_array($transportStatus, ['claimed', 'arrived_pickup'], true) ? ($transportStatus === 'arrived_pickup' ? 'GO Rider 已到店' : 'GO Rider 正在路上') : '物資已領取，運送中');
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($task['donor_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($donationTypeLabels[$task['donation_type'] ?? ''] ?? '其他'); ?></td>
                        <td><?php echo htmlspecialchars($task['item_name'] ?? '未填寫'); ?></td>
                        <td><?php echo htmlspecialchars($task['quantity'] ?? ''); ?> <?php echo htmlspecialchars($task['unit'] ?? ''); ?></td>
                        <td>
                            <span class="status <?php echo $transportStatus === 'open' ? 'status-pending' : 'status-info'; ?>"><?php echo htmlspecialchars($transportStatusLabel); ?></span>
                            <?php if (in_array($transportStatus, ['claimed', 'arrived_pickup', 'picked_up'], true)): ?>
                                <div class="assigned-delivery-details">
                                    <strong>配送會員</strong><?php echo htmlspecialchars($task['volunteer_name'] ?? '尚未同步'); ?>
                                    <strong>聯絡電話</strong><?php echo htmlspecialchars($task['volunteer_phone'] ?? '未提供'); ?>
                                    <strong>車輛類型</strong><?php echo ($task['vehicle_type'] ?? '') === 'car' ? '汽車' : '機車'; ?>
                                    <strong>配送日期</strong><?php echo htmlspecialchars($task['delivery_date'] ?? '未設定'); ?>
                                    <strong>配送時間</strong><?php echo htmlspecialchars($task['delivery_time'] ?? '未設定'); ?>
                                    <strong>配送地址</strong><?php echo htmlspecialchars($task['delivery_address'] ?? '未設定'); ?>
                                    <?php if (!empty($task['estimated_distance_km'])): ?>
                                        <strong>預計運送距離</strong><?php echo htmlspecialchars(formatDistanceText($task['estimated_distance_km'])); ?>
                                        <strong>預計運送時間</strong><?php echo htmlspecialchars(formatDurationText($task['estimated_duration_minutes'] ?? 0)); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="text-muted">由 GO Rider 拍照確認取貨後會自動更新</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-truck"></i><p>目前沒有運送中的物資</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewingDonation): ?>
    <div class="donation-detail-overlay" id="donationDetailModal" role="dialog" aria-modal="true" aria-labelledby="donationDetailTitle">
        <div class="donation-detail-modal">
            <div class="card-header">
                <div>
                    <h2 id="donationDetailTitle">捐贈詳情</h2>
                    <p>物資資料</p>
                </div>
                <button type="button" class="icon-btn" id="closeDonationDetailButton" aria-label="關閉捐贈詳情">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <?php if (($viewingDonation['status'] ?? '') === 'assessed' && ($viewingDonation['evaluation_status'] ?? '') === 'rejected'): ?>
                    <div class="donation-rejection-reason">
                        <strong>婉拒的原因：</strong>
                        <span><?php echo nl2br(htmlspecialchars($viewingDonation['rejection_reason'] ?? '未填寫')); ?></span>
                    </div>
                    <?php
                    $flaggedFields = json_decode((string) ($viewingDonation['rejected_fields'] ?? ''), true);
                    $flaggedFields = is_array($flaggedFields) ? $flaggedFields : [];
                    $resubmitInputs = [
                        'item_name' => 'text', 'quantity' => 'number', 'unit' => 'text', 'weight_kg' => 'number',
                        'size_description' => 'text', 'expiry_date' => 'date', 'donor_address' => 'text', 'notes' => 'text',
                    ];
                    ?>
                    <form method="post" class="resubmit-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="resubmit_donation">
                        <input type="hidden" name="donation_id" value="<?php echo (int) $viewingDonation['donation_id']; ?>">
                        <p><strong>請修改<?php echo $flaggedFields ? '被標記的欄位（紅框）' : '有問題的欄位'; ?>後，直接重新送審：</strong></p>
                        <?php foreach ($resubmitInputs as $fieldKey => $inputType):
                            $isFlagged = in_array($fieldKey, $flaggedFields, true);
                            $isEditable = !$flaggedFields || $isFlagged; ?>
                            <div class="form-group<?php echo $isFlagged ? ' field-flagged' : ''; ?>">
                                <label><?php echo htmlspecialchars(DonationModel::RESUBMIT_FIELD_LABELS[$fieldKey]); ?><?php echo $isFlagged ? '（需修改）' : ''; ?></label>
                                <input type="<?php echo $inputType; ?>"<?php echo $inputType === 'number' ? ' step="0.01" min="0"' : ''; ?> name="fields[<?php echo $fieldKey; ?>]" value="<?php echo htmlspecialchars((string) ($viewingDonation[$fieldKey] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $isEditable ? '' : ' disabled'; ?>>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> 修改後重新送審</button>
                    </form>
                <?php endif; ?>
                <?php $isViewingRejected = ($viewingDonation['evaluation_status'] ?? '') === 'rejected'; ?>
                <?php
                $viewingStatus = strtolower((string) ($viewingDonation['status'] ?? ''));
                $viewingEvaluationStatus = strtolower((string) ($viewingDonation['evaluation_status'] ?? ''));
                if ($viewingStatus === 'pending') {
                    $viewingProcessTimeLabel = '送出時間';
                    $viewingProcessTime = $viewingDonation['donation_date'] ?? null;
                } elseif ($viewingStatus === 'assessed') {
                    $viewingProcessTimeLabel = $isViewingRejected ? '婉拒時間' : '接受時間';
                    $viewingProcessTime = $isViewingRejected ? ($viewingDonation['rejected_at'] ?? null) : ($viewingDonation['approved_at'] ?? null);
                } elseif ($viewingStatus === 'published') {
                    $viewingProcessTimeLabel = '發布時間';
                    $viewingProcessTime = $viewingDonation['published_at'] ?? null;
                } else {
                    $viewingProcessTimeLabel = '更新時間';
                    $viewingProcessTime = $viewingDonation['updated_at'] ?? null;
                }
                ?>
                <?php if (($viewingDonation['status'] ?? '') === 'assessed' && !$isViewingRejected): ?>
                    <div class="donation-current-progress">
                        <strong>目前進度：</strong> 已接受物資捐贈，正在派車前往領取。
                    </div>
                <?php endif; ?>
                <div class="grid-2">
                    <div>
                        <p><strong>店家名稱：</strong> <?php echo htmlspecialchars($viewingDonation['donor_name'] ?? ''); ?></p>
                        <p><strong>物資類型：</strong> <?php
                            $viewingTypeLabel = $donationTypeLabels[$viewingDonation['donation_type'] ?? ''] ?? '其他';
                            if (strpos((string) ($viewingDonation['notes'] ?? ''), '物資類型細項：生鮮食品') !== false) {
                                $viewingTypeLabel .= '（生鮮食品）';
                            }
                            echo htmlspecialchars($viewingTypeLabel);
                        ?></p>
                        <p><strong>名稱：</strong> <?php echo htmlspecialchars($viewingDonation['item_name'] ?? ''); ?></p>
                        <p><strong>數量：</strong> <?php echo htmlspecialchars($viewingDonation['quantity'] ?? ''); ?> <?php echo htmlspecialchars($viewingDonation['unit'] ?? ''); ?></p>
                        <p><strong>重量：</strong> <?php echo htmlspecialchars($viewingDonation['weight_kg'] ?? ''); ?> 公斤</p>
                        <p><strong>大小：</strong> <?php echo htmlspecialchars($viewingDonation['size_description'] ?? '未填寫'); ?></p>
                        <p><strong>有效期限：</strong> <?php echo htmlspecialchars($viewingDonation['expiry_date'] ?? '未填寫'); ?></p>
                    </div>
                    <div>
                        <p><strong>最後領取期限：</strong> <?php echo htmlspecialchars($viewingDonation['pickup_deadline'] ?? '未填寫'); ?></p>
                        <p><strong>配送選擇：</strong> <?php
                            if (($viewingDonation['status'] ?? '') === 'pending') {
                                echo '待食物銀行安排';
                            } else {
                                echo htmlspecialchars($deliveryOptionLabels[$viewingDonation['delivery_option'] ?? ''] ?? '未指定');
                            }
                        ?></p>
                        <p><strong>運送評估：</strong> <?php echo htmlspecialchars($formatVehicleTypes($viewingDonation)); ?></p>
                        <p><strong><?php echo htmlspecialchars($viewingProcessTimeLabel); ?>：</strong> <?php echo htmlspecialchars($formatDateTime($viewingProcessTime)); ?></p>
                        <?php if (($viewingDonation['status'] ?? '') === 'assessed'): ?>
                            <p><strong>評估結果：</strong> <?php echo $isViewingRejected ? '未通過' : '接受'; ?></p>
                        <?php endif; ?>
                        <p><strong>照片上傳：</strong> <?php echo !empty($viewingDonation['photo_path']) ? '已上傳' : '未上傳'; ?></p>
                        <?php if (!empty($viewingDonation['photo_path'])): ?>
                            <?php $paths = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $viewingDonation['photo_path']))); ?>
                            <?php foreach ($paths as $path): ?>
                                <img src="<?php echo htmlspecialchars(storedPublicImageUrl($path)); ?>" style="max-width: 220px; margin: 10px 10px 0 0; border-radius: 8px; border: 1px solid #dfe3ea;" alt="捐贈照片">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($viewingItems)): ?>
                    <div class="material-items-summary">
                        <h3>物資規格</h3>
                        <div class="data-table-wrapper"><table class="data-table donation-items-table"><thead><tr><th>物資名稱</th><th>保存方式</th><th>包裝</th><th>數量</th><th>長寬高 (cm)</th><th>重量 (kg)</th><th>保存期限</th></tr></thead><tbody>
                            <?php foreach ($viewingItems as $item):
                                $dimensionParts = array_filter([$item['length_cm'] ?? null, $item['width_cm'] ?? null, $item['height_cm'] ?? null], static function ($value) {
                                    return $value !== null && $value !== '';
                                });
                                $dimensionText = count($dimensionParts) === 3 ? implode(' × ', $dimensionParts) : '未填寫';
                            ?><tr><td><?php echo htmlspecialchars($item['item_name']); ?></td><td><?php echo htmlspecialchars(['room' => '常溫', 'chilled' => '冷藏', 'frozen' => '冷凍'][$item['storage_condition'] ?? 'room'] ?? '常溫'); ?></td><td><?php echo htmlspecialchars(($item['package_state'] ?? 'sealed') === 'opened' ? '已拆封／分裝' : '原包裝未拆封'); ?></td><td><?php echo htmlspecialchars($item['quantity'] . ' ' . $item['unit']); ?></td><td><?php echo htmlspecialchars($dimensionText); ?></td><td><?php echo htmlspecialchars($item['item_weight_kg'] !== null && $item['item_weight_kg'] !== '' ? $item['item_weight_kg'] . ' kg' : '未填寫'); ?></td><td><?php echo htmlspecialchars($item['expiry_date'] ?? '未填寫'); ?></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    (function () {
        const toggleButton = document.getElementById('toggleDonationFormButton');
        const formSection = document.getElementById('donationFormSection');
        const closeFormButton = document.getElementById('closeDonationFormButton');

        if (toggleButton && formSection) {
            toggleButton.addEventListener('click', function () {
                formSection.classList.add('is-open');
            });
        }

        const closeDonationForm = function () {
            if (formSection) {
                formSection.classList.remove('is-open');
            }
        };

        if (closeFormButton) {
            closeFormButton.addEventListener('click', closeDonationForm);
        }

        if (formSection) {
            formSection.addEventListener('click', function (event) {
                if (event.target === formSection) {
                    closeDonationForm();
                }
            });
        }

        const stepPanels = document.querySelectorAll('[data-step-panel]');
        const stepIndicators = document.querySelectorAll('[data-step-indicator]');
        const showMaterialStep = function (step) {
            stepPanels.forEach(function (panel) {
                const isCurrent = panel.dataset.stepPanel === String(step);
                panel.hidden = !isCurrent;
                panel.classList.toggle('is-active', isCurrent);
            });
            stepIndicators.forEach(function (indicator) {
                indicator.classList.toggle('is-active', indicator.dataset.stepIndicator === String(step));
            });
        };
        document.querySelectorAll('[data-next-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.disabled) {
                    return;
                }
                const deliveryPanel = document.querySelector('[data-step-panel="1"]');
                const deliveryFieldsValid = deliveryPanel && Array.from(deliveryPanel.querySelectorAll('[required]')).every(function (field) {
                    return field.reportValidity();
                });
                if (deliveryFieldsValid) {
                    showMaterialStep(2);
                }
            });
        });
        document.querySelectorAll('[data-previous-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                showMaterialStep(1);
            });
        });

        const itemRows = document.getElementById('materialItemRows');
        const addItemButton = document.getElementById('addMaterialItemButton');
        // 只剩一項物資時不能刪除；多項時每項都有「刪除此物資」
        const refreshItemRows = function () {
            const rows = itemRows ? itemRows.querySelectorAll('.material-item-row') : [];
            rows.forEach(function (row, index) {
                const removeButton = row.querySelector('[data-remove-item]');
                if (removeButton) {
                    removeButton.hidden = rows.length <= 1;
                }
                let title = row.querySelector('.material-item-title');
                if (!title) {
                    title = document.createElement('strong');
                    title.className = 'material-item-title';
                    row.insertBefore(title, row.firstChild);
                }
                title.textContent = '物資 ' + (index + 1);
            });
        };
        if (itemRows && addItemButton) {
            addItemButton.addEventListener('click', function () {
                const newRow = itemRows.firstElementChild.cloneNode(true);
                newRow.querySelectorAll('input').forEach(function (input) {
                    input.value = '';
                });
                newRow.querySelectorAll('select').forEach(function (select) {
                    select.selectedIndex = 0;
                });
                const checkBox = newRow.querySelector('.spec-check-box');
                if (checkBox) {
                    checkBox.remove();
                }
                itemRows.appendChild(newRow);
                refreshItemRows();
            });
            itemRows.addEventListener('click', function (event) {
                const removeButton = event.target.closest('[data-remove-item]');
                if (!removeButton) {
                    return;
                }
                const rows = itemRows.querySelectorAll('.material-item-row');
                if (rows.length > 1) {
                    removeButton.closest('.material-item-row').remove();
                    refreshItemRows();
                }
            });
            refreshItemRows();
        }

        const detailModal = document.getElementById('donationDetailModal');
        const closeDetailButton = document.getElementById('closeDonationDetailButton');
        const closeDetailModal = function () {
            if (detailModal) {
                detailModal.remove();
            }
        };

        if (closeDetailButton) {
            closeDetailButton.addEventListener('click', closeDetailModal);
        }

        if (detailModal) {
            detailModal.addEventListener('click', function (event) {
                if (event.target === detailModal) {
                    closeDetailModal();
                }
            });
        }
    })();
</script>

<style>
    .material-stepper { display: flex; gap: 10px; margin-bottom: 24px; }
    .material-step { flex: 1; padding: 12px 14px; color: #64748b; background: #f1f5f9; border-radius: 8px; font-weight: 700; text-align: center; }
    .material-step.is-active { color: #0f766e; background: #ccfbf1; }
    .material-step-panel[hidden] { display: none; }
    .form-section-heading { margin-bottom: 18px; }
    .form-section-heading span { color: #0f766e; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .form-section-heading h3 { margin: 4px 0; }
    .form-section-heading p { margin: 0; color: #64748b; }
    .required-mark { color: #dc2626; }
    .delivery-mode-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .delivery-mode-options label { display: flex; align-items: flex-start; gap: 10px; padding: 14px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; }
    .delivery-mode-options input[type="radio"] { width: auto; flex: 0 0 auto; margin: 3px 0 0; padding: 0; }
    .delivery-mode-options label > span { flex: 1; min-width: 0; }
    .delivery-mode-options label:has(input:checked) { border-color: #0f766e; background: #f0fdfa; }
    .delivery-mode-options strong, .delivery-mode-options small { display: block; }
    .delivery-mode-options small { margin-top: 4px; color: #64748b; }
    .dispatch-details { margin: 18px 0; padding: 18px; border: 1px solid #99f6e4; border-radius: 8px; background: #f0fdfa; }
    .dispatch-details-note { margin: 18px 0; padding: 12px 14px; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; }
    .distance-estimate-box { display: flex; flex-wrap: wrap; gap: 16px; padding: 12px 14px; border: 1px solid #99f6e4; border-radius: 8px; background: #f0fdfa; }
    .distance-estimate-item { display: flex; align-items: center; gap: 8px; font-weight: 600; color: #0f766e; }
    .distance-estimate-item i { color: #14b8a6; }
    .distance-estimate-status { margin: 8px 0 0; font-size: 12px; color: #64748b; }
    .distance-estimate-status.is-error { color: #dc2626; }
    .material-item-title { display: block; margin-bottom: 10px; color: #0f766e; }
    .material-item-footer { display: flex; justify-content: flex-end; }
    .material-item-row { margin-bottom: 18px; padding: 18px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
    .material-items-summary { margin-top: 24px; }
    .material-items-summary h3 { margin-bottom: 12px; }
    .assigned-delivery-details { display: grid; grid-template-columns: auto 1fr; gap: 4px 8px; margin-top: 10px; padding: 10px; border-left: 3px solid #14b8a6; background: #f0fdfa; font-size: 12px; line-height: 1.5; }
    .assigned-delivery-details strong { color: #0f766e; }
    .step-actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; }
    @media (max-width: 700px) { .delivery-mode-options { grid-template-columns: 1fr; } .material-stepper { flex-direction: column; } }
</style>

<?php if ($viewingDonation): ?>
<style>
    .donation-detail-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.48);
    }

    .donation-detail-modal {
        width: min(760px, 100%);
        max-height: min(720px, 90vh);
        overflow-y: auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.24);
    }

    .donation-detail-modal .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .donation-current-progress {
        margin-bottom: 20px;
        padding: 12px 14px;
        color: #166534;
        background: #dcfce7;
        border: 1px solid #86efac;
        border-radius: 8px;
        font-weight: 600;
    }

    .donation-rejection-reason {
        margin-bottom: 20px;
        padding: 14px 16px;
        color: #991b1b;
        background: #fff1f2;
        border: 1px solid #fda4af;
        border-left: 4px solid #ef4444;
        border-radius: 8px;
        line-height: 1.6;
    }

    .donation-rejection-reason span {
        display: block;
        margin-top: 4px;
    }
</style>
<?php endif; ?>

<style>
    .donation-form-overlay {
        position: fixed;
        inset: 0;
        z-index: 1200;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.48);
    }

    .donation-form-overlay.is-open {
        display: flex;
    }

    .donation-form-modal {
        margin: auto;
        width: min(760px, 100%);
        max-height: 90vh;
        overflow-y: auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.24);
    }

    .donation-form-modal .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
</style>
