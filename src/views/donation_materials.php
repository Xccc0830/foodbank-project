<?php
require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/models/DeliveryModel.php';
require_once BASE_PATH . '/src/helpers/UploadHelper.php';

$donationModel = new DonationModel();
$deliveryModel = new DeliveryModel();
$currentUserId = isset($currentUser['user_id']) ? (int) $currentUser['user_id'] : 0;
$formMessage = null;
$viewingDonation = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_material_donation') {
    $donorName = trim((string) ($_POST['donor_name'] ?? ''));
    $itemName = trim((string) ($_POST['item_name'] ?? ''));
    $quantity = (float) ($_POST['quantity'] ?? 0);
    $rawUnit = trim((string) ($_POST['quantity_unit'] ?? '件'));
    $unit = ($rawUnit === '其他') ? trim((string) ($_POST['custom_quantity_unit'] ?? '')) : $rawUnit;
    if ($unit === '') {
        $unit = '件';
    }

    $donationTypeValue = (string) ($_POST['donation_type'] ?? 'food');
    $mappedDonationType = [
        '民生用品' => 'supplies',
        '食物' => 'food',
        '生鮮食品' => 'food',
        '其他' => 'other',
    ];
    $donationType = $mappedDonationType[$donationTypeValue] ?? 'other';
    $customDonationType = trim((string) ($_POST['custom_donation_type'] ?? ''));

    $weightValue = (float) ($_POST['weight_value'] ?? 0);
    $weightUnit = trim((string) ($_POST['weight_unit'] ?? 'kg'));
    if ($weightUnit === 'g') {
        $normalizedWeight = $weightValue / 1000;
    } elseif ($weightUnit === 'lb') {
        $normalizedWeight = $weightValue * 0.453592;
    } else {
        $normalizedWeight = $weightValue;
    }

    $sizeLength = trim((string) ($_POST['size_length'] ?? ''));
    $sizeWidth = trim((string) ($_POST['size_width'] ?? ''));
    $sizeHeight = trim((string) ($_POST['size_height'] ?? ''));
    $sizeUnit = trim((string) ($_POST['size_unit'] ?? 'cm'));
    $sizeDescriptionParts = array_filter([$sizeLength, $sizeWidth, $sizeHeight], static function ($part) {
        return $part !== '';
    });
    $sizeDescription = $sizeDescriptionParts ? implode(' × ', $sizeDescriptionParts) . ($sizeUnit !== '' ? ' ' . $sizeUnit : '') : null;

    $expiryDate = trim((string) ($_POST['expiry_date'] ?? '')) ?: null;
    $pickupDeadline = trim((string) ($_POST['pickup_deadline'] ?? '')) ?: null;
    $deliveryOptionValue = (string) ($_POST['delivery_option'] ?? 'self_delivery');
    $deliveryOptionMap = [
        'self_delivery' => 'donor_delivery',
        'need_dispatch' => 'food_bank_pickup',
    ];
    $deliveryOption = $deliveryOptionMap[$deliveryOptionValue] ?? 'donor_delivery';

    $vehicleSelections = $_POST['vehicle_type'] ?? [];
    $vehicleTypes = is_array($vehicleSelections) ? array_map('trim', array_filter($vehicleSelections, 'strlen')) : [];
    $vehicleTypeValue = 'none';
    if (in_array('汽車', $vehicleTypes, true) || in_array('car', $vehicleTypes, true)) {
        $vehicleTypeValue = 'car';
    } elseif (in_array('機車', $vehicleTypes, true) || in_array('motorcycle', $vehicleTypes, true)) {
        $vehicleTypeValue = 'motorcycle';
    }

    $quantityNote = trim((string) ($_POST['quantity_custom_detail'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($donationTypeValue === '生鮮食品') {
        $notes = $notes === '' ? '物資類型細項：生鮮食品' : $notes . '; 物資類型細項：生鮮食品';
    }
    if ($customDonationType !== '') {
        $notes = $notes === '' ? '類型細項：' . $customDonationType : $notes . '; 類型細項：' . $customDonationType;
    }
    if ($quantityNote !== '') {
        $notes = $notes === '' ? '數量說明：' . $quantityNote : $notes . '; 數量說明：' . $quantityNote;
    }
    if (!empty($vehicleTypes)) {
        $notes = $notes === '' ? '運送評估選項：' . implode('、', $vehicleTypes) : $notes . '; 運送評估選項：' . implode('、', $vehicleTypes);
    }
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
        'donor_name' => $donorName !== '' ? $donorName : ($currentUser['full_name'] ?? '未命名商家'),
            'donor_address' => trim((string) ($_POST['donor_address'] ?? '')) ?: null,
        'donation_type' => $donationType,
        'quantity' => $quantity,
        'unit' => $unit,
        'donation_date' => date('Y-m-d H:i:s'),
        'received_by' => null,
        'status' => 'pending',
        'evaluation_status' => 'pending',
        'delivery_method' => $deliveryOption === 'food_bank_pickup' ? 'volunteer_assist' : 'self_delivery',
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
    ];

    if ($donorName === '' || $itemName === '' || $quantity <= 0) {
        $formMessage = ['type' => 'error', 'text' => '請完整填寫店家名稱、物資名稱與數量。'];
    } else {
        $insertedId = $donationModel->addDonation($donationData);
        $formMessage = $insertedId
            ? ['type' => 'success', 'text' => '物資捐贈已送出，待食物銀行評估。']
            : ['type' => 'error', 'text' => '送出失敗，請稍後再試。'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'view_material_details') {
    $viewingDonation = $donationModel->getDonationById((int) ($_POST['donation_id'] ?? 0));
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
    'donor_delivery' => '商家自行運送',
    'volunteer_delivery' => '他人協助運送',
    'food_bank_pickup' => '自行派車',
];
$merchantDeliveryOptionLabels = [
    'donor_delivery' => '商家自行運送',
    'volunteer_delivery' => '需派車運送',
    'food_bank_pickup' => '需派車運送',
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

            <div class="form-group">
                <label>店家名稱</label>
                <input type="text" name="donor_name" placeholder="例如：愛心商家001" required>
            </div>

            <div class="form-group">
                <label>店家地址</label>
                <input type="text" name="donor_address" placeholder="請填寫取貨地址">
            </div>

            <div class="form-group">
                <label>物資類型</label>
                <select name="donation_type" required>
                    <option value="">--請選擇--</option>
                    <option value="民生用品">民生用品</option>
                    <option value="食物">食物</option>
                    <option value="生鮮食品">生鮮食品</option>
                    <option value="其他">其他</option>
                </select>
            </div>

            <div class="form-group">
                <label>名稱</label>
                <input type="text" name="item_name" placeholder="例如：白米、洗衣精、鮮奶" required>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>數量</label>
                    <input type="number" name="quantity" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label>單位</label>
                    <select name="quantity_unit" id="materialQuantityUnitSelect">
                        <option value="件">件</option>
                        <option value="包">包</option>
                        <option value="箱">箱</option>
                        <option value="盒">盒</option>
                        <option value="袋">袋</option>
                        <option value="份">份</option>
                        <option value="公斤">公斤</option>
                        <option value="其他">其他</option>
                    </select>
                    <div id="customQuantityUnitWrapper" style="display:none; margin-top: 10px;">
                        <input type="text" name="custom_quantity_unit" placeholder="請填寫自訂單位">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>數量說明（可填寫區域）</label>
                <textarea name="quantity_custom_detail" rows="2" placeholder="例如：每箱約 12 包、每份約 500g"></textarea>
            </div>

            <div class="form-group">
                <label>重量（單位）</label>
                <div class="grid-2">
                    <input type="number" name="weight_value" step="0.01" min="0" placeholder="數值">
                    <select name="weight_unit">
                        <option value="kg">公斤</option>
                        <option value="g">公克</option>
                        <option value="lb">磅</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>大小（長寬高）</label>
                <div class="grid-3">
                    <input type="number" name="size_length" step="0.01" min="0" placeholder="長">
                    <input type="number" name="size_width" step="0.01" min="0" placeholder="寬">
                    <input type="number" name="size_height" step="0.01" min="0" placeholder="高">
                </div>
                <div style="margin-top: 10px;">
                    <select name="size_unit">
                        <option value="cm">公分</option>
                        <option value="m">公尺</option>
                        <option value="箱">箱</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>有效期限</label>
                <input type="date" name="expiry_date">
            </div>

            <div class="form-group">
                <label>最後領取期限</label>
                <input type="datetime-local" name="pickup_deadline">
            </div>

            <div class="form-group">
                <label>安全驗證：照片上傳</label>
                <input type="file" name="photo[]" accept="image/png,image/jpeg,image/webp" multiple>
            </div>

            <div class="form-group">
                <label>配送選擇</label>
                <select name="delivery_option" required>
                    <option value="self_delivery">商家自行運送</option>
                    <option value="need_dispatch">需派車運送</option>
                </select>
            </div>

            <div class="form-group">
                <label>運送評估</label>
                <div style="display:flex; flex-wrap: wrap; gap: 12px; margin-top: 8px;">
                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="vehicle_type[]" value="汽車"> 汽車</label>
                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="vehicle_type[]" value="機車"> 機車</label>
                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="vehicle_type[]" value="貨車"> 貨車</label>
                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="vehicle_type[]" value="其他"> 其他</label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">送出</button>
            </div>
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
                        <th>店家名稱</th>
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
        <h2>運送</h2>
        <p>查看已發布物資的運送狀態，並確認外送員是否已領取物資。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($transportTasks)): ?>
            <table class="data-table">
                <thead><tr><th>店家名稱</th><th>物資類型</th><th>名稱</th><th>數量</th><th>狀態</th><th>操作</th></tr></thead>
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
                        <td><span class="status <?php echo $transportStatus === 'open' ? 'status-pending' : 'status-info'; ?>"><?php echo htmlspecialchars($transportStatusLabel); ?></span></td>
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
                        <strong>不接受的原因：</strong>
                        <span><?php echo nl2br(htmlspecialchars($viewingDonation['rejection_reason'] ?? '未填寫')); ?></span>
                    </div>
                <?php endif; ?>
                <?php $isViewingRejected = ($viewingDonation['evaluation_status'] ?? '') === 'rejected'; ?>
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
                        <?php if (($viewingDonation['status'] ?? '') === 'assessed'): ?>
                            <p><strong>評估結果：</strong> <?php echo $isViewingRejected ? '未通過' : '接受'; ?></p>
                            <?php if ($isViewingRejected): ?>
                                <p><strong>食物銀行不接受時間：</strong> <?php echo htmlspecialchars($formatDateTime($viewingDonation['rejected_at'] ?? null)); ?></p>
                            <?php else: ?>
                            <p><strong>食物銀行接受時間：</strong> <?php echo htmlspecialchars($formatDateTime($viewingDonation['approved_at'] ?? null)); ?></p>
                            <?php endif; ?>
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
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    (function () {
        const toggleButton = document.getElementById('toggleDonationFormButton');
        const formSection = document.getElementById('donationFormSection');
        const closeFormButton = document.getElementById('closeDonationFormButton');
        const unitSelect = document.getElementById('materialQuantityUnitSelect');
        const customQuantityWrapper = document.getElementById('customQuantityUnitWrapper');

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

        if (unitSelect && customQuantityWrapper) {
            unitSelect.addEventListener('change', function () {
                customQuantityWrapper.style.display = unitSelect.value === '其他' ? 'block' : 'none';
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
