<?php
require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/models/DeliveryModel.php';
require_once BASE_PATH . '/src/helpers/UploadHelper.php';
require_once BASE_PATH . '/src/models/NotificationModel.php';

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$notificationModel = new NotificationModel();
$connection = $db->getConnection();
$currentUserId = isset($currentUser['user_id']) ? (int) $currentUser['user_id'] : 0;
$formMessage = null;
$viewingDonation = null;
$viewingItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_material_donation') {
    $donorName = trim((string) ($currentUser['enterprise_name'] ?? $currentUser['full_name'] ?? ''));
    $deliveryMode = (string) ($_POST['delivery_mode'] ?? '');
    $deliveryOption = $deliveryMode === 'food_bank' ? 'food_bank_pickup' : 'volunteer_delivery';
    $deliveryMethod = $deliveryMode === 'food_bank' ? 'self_delivery' : 'volunteer_assist';
    $deliveryDate = trim((string) ($_POST['delivery_date'] ?? '')) ?: null;
    $deliveryTime = trim((string) ($_POST['delivery_time'] ?? '')) ?: null;
    $donorAddress = trim((string) ($_POST['donor_address'] ?? ''));
    $deliveryAddress = trim((string) ($_POST['delivery_address'] ?? ''));
    $beneficiaryId = (int) ($_POST['beneficiary_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $itemNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];
    $itemBrands = is_array($_POST['brand'] ?? null) ? $_POST['brand'] : [];
    $itemSpecifications = is_array($_POST['specification'] ?? null) ? $_POST['specification'] : [];
    $itemQuantities = is_array($_POST['item_quantity'] ?? null) ? $_POST['item_quantity'] : [];
    $itemUnits = is_array($_POST['item_unit'] ?? null) ? $_POST['item_unit'] : [];
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
            'brand' => trim((string) ($itemBrands[$index] ?? '')) ?: null,
            'specification' => trim((string) ($itemSpecifications[$index] ?? '')) ?: null,
            'quantity' => $quantity,
            'unit' => trim((string) ($itemUnits[$index] ?? '件')) ?: '件',
            'expiry_date' => trim((string) ($itemExpiryDates[$index] ?? '')) ?: null,
            'notes' => trim((string) ($itemNotes[$index] ?? '')) ?: null,
        ];
    }

    $firstItem = $items[0] ?? null;
    $itemName = $firstItem['item_name'] ?? '';
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
        'seal_code' => null,
        'need_inspection' => 1,
        'inspection_notes' => null,
        'delivery_date' => $deliveryDate,
        'delivery_time' => $deliveryTime,
        'delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
        'beneficiary_id' => $beneficiaryId > 0 ? $beneficiaryId : null,
    ];

    $hasInvalidItem = empty($items);
    foreach ($items as $item) {
        if ($item['item_name'] === '' || $item['quantity'] <= 0) {
            $hasInvalidItem = true;
            break;
        }
    }
    if ($donorName === '' || $donorAddress === '' || $deliveryMode === '' || $deliveryAddress === '' || !$deliveryDate || !$deliveryTime || $hasInvalidItem) {
        $formMessage = ['type' => 'error', 'text' => '請完成企業資料、取貨地址、送達地址、配送方式、日期時間，以及至少一筆物資。'];
    } else {
        $connection->begin_transaction();
        $insertedId = $donationModel->addDonation($donationData);
        if ($insertedId) {
            $itemStatement = $connection->prepare('INSERT INTO donation_items (donation_id, item_name, brand, specification, quantity, unit, expiry_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $itemsSaved = $itemStatement !== false;
            if ($itemsSaved) {
                foreach ($items as $item) {
                    $itemStatement->bind_param(
                        'isssdsss',
                        $insertedId,
                        $item['item_name'],
                        $item['brand'],
                        $item['specification'],
                        $item['quantity'],
                        $item['unit'],
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'donor_confirm_pickup') {
    $confirmed = $deliveryModel->donorConfirmPickup((int) ($_POST['delivery_id'] ?? 0), $currentUserId);
    $formMessage = $confirmed
        ? ['type' => 'success', 'text' => '已確認外送員領取物資，運送狀態已更新。']
        : ['type' => 'error', 'text' => '確認失敗，請確認外送員已接受任務。'];
}

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
    'volunteer_delivery' => '志工派車',
    'food_bank_pickup' => '忠信派車',
];
$merchantDeliveryOptionLabels = [
    'donor_delivery' => '忠信派車',
    'volunteer_delivery' => '志工派車',
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
$beneficiaries = [];
$beneficiaryResult = $connection->query("SELECT beneficiary_id, beneficiary_code, first_name, last_name, address FROM beneficiaries WHERE status = 'active' ORDER BY last_name, first_name");
if ($beneficiaryResult) {
    while ($beneficiary = $beneficiaryResult->fetch_assoc()) {
        $beneficiaries[] = $beneficiary;
    }
}
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
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_material_donation">
            <div class="material-stepper" aria-label="新增物資步驟">
                <span class="material-step is-active" data-step-indicator="1">1 配送資訊</span>
                <span class="material-step" data-step-indicator="2">2 物資規格</span>
            </div>

            <section class="material-step-panel is-active" data-step-panel="1">
                <div class="form-section-heading"><span>Step 1</span><h3>配送資訊</h3><p>先確認誰配送、何時送達，以及配送目的地。</p></div>
                <div class="form-section-heading"><span>商家資料</span><h3>企業取貨資訊</h3><p>這是配送人員前往取物資的地點，不是物資送達地址。</p></div>
                <div class="grid-2">
                    <div class="form-group"><label for="donorName">捐贈企業／組織</label><input id="donorName" type="text" value="<?php echo htmlspecialchars($donorName, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
                    <div class="form-group"><label for="donorAddress">商家取貨地址 <span class="required-mark">*</span></label><input id="donorAddress" type="text" name="donor_address" placeholder="配送人員要到哪裡取貨" required></div>
                </div>
                <div class="form-section-heading"><span>配送目的地</span><h3>送達資訊</h3><p>這是物資最後要送到的地點與關懷戶。</p></div>
                <div class="form-group">
                    <label>配送方式 <span class="required-mark">*</span></label>
                    <div class="delivery-mode-options">
                        <label><input type="radio" name="delivery_mode" value="food_bank" required> <span><strong>忠信派車</strong><small>由忠信食物銀行安排配送</small></span></label>
                        <label><input type="radio" name="delivery_mode" value="volunteer"> <span><strong>志工派車</strong><small>由配送會員接取配送任務</small></span></label>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group"><label for="deliveryDate">預計配送日期 <span class="required-mark">*</span></label><input id="deliveryDate" type="date" name="delivery_date" required></div>
                    <div class="form-group"><label for="deliveryTime">預計配送時間 <span class="required-mark">*</span></label><input id="deliveryTime" type="time" name="delivery_time" required></div>
                </div>
                <div class="form-group"><label for="deliveryAddress">配送地址 <span class="required-mark">*</span></label><input id="deliveryAddress" type="text" name="delivery_address" placeholder="物資送到哪裡" required></div>
                <div class="form-group"><label for="beneficiaryId">關懷戶</label><select id="beneficiaryId" name="beneficiary_id"><option value="">選擇關懷戶</option><?php foreach ($beneficiaries as $beneficiary): ?><option value="<?php echo (int) $beneficiary['beneficiary_id']; ?>"><?php echo htmlspecialchars($beneficiary['beneficiary_code'] . '｜' . $beneficiary['last_name'] . $beneficiary['first_name'] . ($beneficiary['address'] ? '｜' . $beneficiary['address'] : '')); ?></option><?php endforeach; ?></select></div>
                <div class="dispatch-details-note"><i class="fas fa-circle-info"></i> 選擇志工派車後，配送會員接單時才會自動顯示姓名、電話與車輛資訊。</div>
                <div class="form-group"><label for="deliveryNotes">備註</label><textarea id="deliveryNotes" name="notes" rows="3" placeholder="特殊配送需求或補充資訊"></textarea></div>
                <div class="step-actions"><button type="button" class="btn btn-primary" data-next-step>下一步：填寫物資</button></div>
            </section>

            <section class="material-step-panel" data-step-panel="2" hidden>
                <div class="form-section-heading"><span>Step 2</span><h3>物資規格</h3><p>同一筆配送可以加入多種物資。</p></div>
                <div id="materialItemRows">
                    <div class="material-item-row">
                        <div class="grid-2"><div class="form-group"><label>物資名稱 <span class="required-mark">*</span></label><input type="text" name="item_name[]" placeholder="例如：白米" required></div><div class="form-group"><label>品牌</label><input type="text" name="brand[]" placeholder="例如：台灣好米"></div></div>
                        <div class="grid-3"><div class="form-group"><label>規格</label><input type="text" name="specification[]" placeholder="例如：5kg"></div><div class="form-group"><label>數量 <span class="required-mark">*</span></label><input type="number" name="item_quantity[]" min="0.01" step="0.01" required></div><div class="form-group"><label>單位</label><select name="item_unit[]"><option>包</option><option>箱</option><option>瓶</option><option>盒</option><option>份</option><option>件</option></select></div></div>
                        <div class="grid-2"><div class="form-group"><label>保存期限</label><input type="date" name="item_expiry_date[]"></div><div class="form-group"><label>備註</label><input type="text" name="item_notes[]" placeholder="其他資訊"></div></div>
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
            <table class="data-table">
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
            <table class="data-table">
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
            <table class="data-table">
                <thead><tr><th>店家名稱</th><th>物資類型</th><th>名稱</th><th>數量</th><th>狀態與配送資訊</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($transportTasks as $task): ?>
                    <?php
                    $transportStatus = $task['status'] ?? 'open';
                    $transportStatusLabel = $transportStatus === 'open'
                        ? '尚在等待運送人員'
                        : ($transportStatus === 'claimed' ? '運送員正在路上' : '物資已領取，運送中');
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($task['donor_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($donationTypeLabels[$task['donation_type'] ?? ''] ?? '其他'); ?></td>
                        <td><?php echo htmlspecialchars($task['item_name'] ?? '未填寫'); ?></td>
                        <td><?php echo htmlspecialchars($task['quantity'] ?? ''); ?> <?php echo htmlspecialchars($task['unit'] ?? ''); ?></td>
                        <td>
                            <span class="status <?php echo $transportStatus === 'open' ? 'status-pending' : 'status-info'; ?>"><?php echo htmlspecialchars($transportStatusLabel); ?></span>
                            <?php if (in_array($transportStatus, ['claimed', 'picked_up'], true)): ?>
                                <div class="assigned-delivery-details">
                                    <strong>配送會員</strong><?php echo htmlspecialchars($task['volunteer_name'] ?? '尚未同步'); ?>
                                    <strong>聯絡電話</strong><?php echo htmlspecialchars($task['volunteer_phone'] ?? '未提供'); ?>
                                    <strong>車輛類型</strong><?php echo ($task['vehicle_type'] ?? '') === 'car' ? '汽車' : '機車'; ?>
                                    <strong>配送日期</strong><?php echo htmlspecialchars($task['delivery_date'] ?? '未設定'); ?>
                                    <strong>配送時間</strong><?php echo htmlspecialchars($task['delivery_time'] ?? '未設定'); ?>
                                    <strong>配送地址</strong><?php echo htmlspecialchars($task['delivery_address'] ?? '未設定'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($transportStatus === 'claimed'): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="donor_confirm_pickup">
                                    <input type="hidden" name="delivery_id" value="<?php echo (int) $task['delivery_id']; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">外送員已將物資領取</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted">等待狀態更新</span>
                            <?php endif; ?>
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
                            $detailDeliveryLabels = ($viewingDonation['status'] ?? '') === 'pending'
                                ? $merchantDeliveryOptionLabels
                                : $deliveryOptionLabels;
                            echo htmlspecialchars($detailDeliveryLabels[$viewingDonation['delivery_option'] ?? ''] ?? '未指定');
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
                                <img src="<?php echo htmlspecialchars(APP_URL . '/' . ltrim($path, '/')); ?>" style="max-width: 220px; margin: 10px 10px 0 0; border-radius: 8px; border: 1px solid #dfe3ea;" alt="捐贈照片">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($viewingItems)): ?>
                    <div class="material-items-summary">
                        <h3>物資規格</h3>
                        <div class="data-table-wrapper"><table class="data-table"><thead><tr><th>物資名稱</th><th>品牌</th><th>規格</th><th>數量</th><th>保存期限</th><th>備註</th></tr></thead><tbody>
                            <?php foreach ($viewingItems as $item): ?><tr><td><?php echo htmlspecialchars($item['item_name']); ?></td><td><?php echo htmlspecialchars($item['brand'] ?? ''); ?></td><td><?php echo htmlspecialchars($item['specification'] ?? ''); ?></td><td><?php echo htmlspecialchars($item['quantity'] . ' ' . $item['unit']); ?></td><td><?php echo htmlspecialchars($item['expiry_date'] ?? '未填寫'); ?></td><td><?php echo htmlspecialchars($item['notes'] ?? ''); ?></td></tr><?php endforeach; ?>
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
        if (itemRows && addItemButton) {
            addItemButton.addEventListener('click', function () {
                const newRow = itemRows.firstElementChild.cloneNode(true);
                newRow.querySelectorAll('input').forEach(function (input) {
                    input.value = '';
                });
                newRow.querySelectorAll('select').forEach(function (select) {
                    select.selectedIndex = 0;
                });
                itemRows.appendChild(newRow);
            });
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
    .delivery-mode-options label:has(input:checked) { border-color: #0f766e; background: #f0fdfa; }
    .delivery-mode-options strong, .delivery-mode-options small { display: block; }
    .delivery-mode-options small { margin-top: 4px; color: #64748b; }
    .dispatch-details { margin: 18px 0; padding: 18px; border: 1px solid #99f6e4; border-radius: 8px; background: #f0fdfa; }
    .dispatch-details-note { margin: 18px 0; padding: 12px 14px; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; }
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
        z-index: 999;
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
