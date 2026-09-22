<?php
require_once BASE_PATH . '/src/models/DonationModel.php';

$donationModel = new DonationModel();
$reviewMessage = null;
$viewingDonation = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $donationId = (int) ($_POST['donation_id'] ?? 0);

    if ($action === 'review_material_donation') {
        $decision = $_POST['decision'] ?? '';
        $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
        $foodbankDeliveryOption = $_POST['foodbank_delivery_option'] ?? '';

        if (in_array($decision, ['accepted', 'rejected'], true) && $donationId > 0) {
            if ($decision === 'rejected' && $rejectionReason === '') {
                $reviewMessage = ['type' => 'error', 'text' => '請填寫不接受原因後再送出。'];
            } else {
                $updated = $donationModel->reviewMaterialDonation($donationId, $decision, $rejectionReason, $foodbankDeliveryOption);
                if ($updated && $decision === 'accepted' && !empty($_POST['publish_now'])) {
                    $updated = $donationModel->publishDonation($donationId, $_POST);
                }
                $reviewMessage = $updated
                    ? ['type' => 'success', 'text' => ($decision === 'accepted' && !empty($_POST['publish_now']) ? '物資已接受並立即發布。' : ($decision === 'accepted' ? '物資已接受並完成評估。' : '物資已標記為不接受並完成評估。'))]
                    : ['type' => 'error', 'text' => '評估失敗，請確認此物資仍在待評估狀態。'];
            }
        } else {
            $reviewMessage = ['type' => 'error', 'text' => '評估資料不完整。'];
        }
    }

    if ($action === 'view_review_donation' && $donationId > 0) {
        $viewingDonation = $donationModel->getDonationById($donationId);
    }

    if ($action === 'view_publish_donation' && $donationId > 0) {
        $viewingDonation = $donationModel->getDonationById($donationId);
    }

    if ($action === 'publish_material_donation' && $donationId > 0) {
        $published = $donationModel->publishDonation($donationId, $_POST);
        $reviewMessage = $published
            ? ['type' => 'success', 'text' => '物資已發布到平台。']
            : ['type' => 'error', 'text' => '發布失敗，請確認物資仍在待發布狀態。'];
    }
}

$donations = $donationModel->getAllDonations();
$merchantDonations = array_values(array_filter($donations, static function ($donation) {
    return !empty($donation['donor_id']);
}));
$pendingDonations = array_values(array_filter($merchantDonations, static function ($donation) {
    return strtolower((string) ($donation['status'] ?? '')) === 'pending';
}));
$assessedDonations = array_values(array_filter($merchantDonations, static function ($donation) {
    return strtolower((string) ($donation['status'] ?? '')) === 'assessed'
        && in_array(strtolower((string) ($donation['evaluation_status'] ?? '')), ['approved_volunteer', 'approved_self_delivery', 'rejected'], true);
}));
$pendingPublishDonations = array_values(array_filter($assessedDonations, static function ($donation) {
    return ($donation['evaluation_status'] ?? '') !== 'rejected';
}));
$publishedDonations = array_values(array_filter($merchantDonations, static function ($donation) {
    return strtolower((string) ($donation['status'] ?? '')) === 'published';
}));

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

$renderDetails = static function ($donation) use ($donationTypeLabels, $deliveryOptionLabels, $merchantDeliveryOptionLabels, $formatVehicleTypes, $formatDateTime) {
    $photoPaths = !empty($donation['photo_path'])
        ? array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $donation['photo_path'])))
        : [];
    $isRejected = ($donation['evaluation_status'] ?? '') === 'rejected';
    $decisionLabel = $isRejected ? '不接受' : '接受';
    $decisionTime = $isRejected ? ($donation['rejected_at'] ?? null) : ($donation['approved_at'] ?? null);
    $typeLabel = $donationTypeLabels[$donation['donation_type'] ?? ''] ?? '其他';
    if (strpos((string) ($donation['notes'] ?? ''), '物資類型細項：生鮮食品') !== false) {
        $typeLabel .= '（生鮮食品）';
    }
    ?>
    <div class="donation-detail-grid">
        <div>
            <p><strong>店家名稱：</strong><?php echo htmlspecialchars($donation['donor_name'] ?? ''); ?></p>
            <p><strong>店家地址：</strong><?php echo htmlspecialchars($donation['donor_address'] ?? '未填寫'); ?></p>
            <p><strong>物資類型：</strong><?php echo htmlspecialchars($typeLabel); ?></p>
            <p><strong>名稱：</strong><?php echo htmlspecialchars($donation['item_name'] ?? '未填寫'); ?></p>
            <p><strong>數量：</strong><?php echo htmlspecialchars($donation['quantity'] ?? ''); ?> <?php echo htmlspecialchars($donation['unit'] ?? ''); ?></p>
            <p><strong>重量：</strong><?php echo htmlspecialchars($donation['weight_kg'] ?? '0'); ?> 公斤</p>
            <p><strong>大小：</strong><?php echo htmlspecialchars($donation['size_description'] ?? '未填寫'); ?></p>
            <p><strong>有效期限：</strong><?php echo htmlspecialchars($donation['expiry_date'] ?? '未填寫'); ?></p>
        </div>
        <div>
            <p><strong>最後領取期限：</strong><?php echo htmlspecialchars($formatDateTime($donation['pickup_deadline'] ?? null)); ?></p>
            <p><strong>配送選擇：</strong><?php
                $detailDeliveryLabels = ($donation['status'] ?? '') === 'pending'
                    ? $merchantDeliveryOptionLabels
                    : $deliveryOptionLabels;
                echo htmlspecialchars($detailDeliveryLabels[$donation['delivery_option'] ?? ''] ?? '未指定');
            ?></p>
            <p><strong>運送評估：</strong><?php echo htmlspecialchars($formatVehicleTypes($donation)); ?></p>
            <p><strong>送出時間：</strong><?php echo htmlspecialchars($formatDateTime($donation['donation_date'] ?? null)); ?></p>
            <?php if (($donation['status'] ?? '') === 'assessed'): ?>
                <p><strong>評估結果：</strong><?php echo htmlspecialchars($decisionLabel); ?></p>
                <p><strong><?php echo htmlspecialchars($decisionLabel); ?>時間：</strong><?php echo htmlspecialchars($formatDateTime($decisionTime)); ?></p>
                <?php if ($isRejected): ?>
                    <p><strong>不接受原因：</strong><?php echo nl2br(htmlspecialchars($donation['rejection_reason'] ?? '未填寫')); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($photoPaths)): ?>
        <div class="donation-photos">
            <strong>安全驗證照片：</strong>
            <div>
                <?php foreach ($photoPaths as $photoPath): ?>
                    <img src="<?php echo htmlspecialchars(APP_URL . '/' . ltrim($photoPath, '/')); ?>" alt="捐贈物資照片">
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <p><strong>安全驗證照片：</strong>未上傳</p>
    <?php endif; ?>
    <?php
};
?>
<div class="view-header">
    <div>
        <h1 class="view-title">物資捐贈審查</h1>
        <p class="view-subtitle">查看愛心商家送出的物資內容並完成評估。</p>
    </div>
</div>

<?php if ($reviewMessage): ?>
    <div class="alert alert-<?php echo $reviewMessage['type']; ?>">
        <?php echo htmlspecialchars($reviewMessage['text']); ?>
    </div>
<?php endif; ?>

<div class="card mb-20">
    <div class="card-header">
        <h2>待評估</h2>
        <p>愛心商家按下送出後，物資會先出現在這裡。</p>
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
                            <td><?php echo htmlspecialchars($donation['item_name'] ?? '未填寫'); ?></td>
                            <td><?php echo htmlspecialchars($merchantDeliveryOptionLabels[$donation['delivery_option'] ?? ''] ?? '未指定'); ?></td>
                            <td><?php echo htmlspecialchars($formatDateTime($donation['donation_date'] ?? null)); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="view_review_donation">
                                    <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">評估</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>目前沒有待評估物資</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>已評估</h2>
        <p>已完成接受或不接受判定的物資會出現在這裡。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($assessedDonations)): ?>
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
                    <?php foreach ($assessedDonations as $donation): ?>
                        <tr>
                            <?php
                            $evaluationStatus = strtolower((string) ($donation['evaluation_status'] ?? ''));
                            $isRejected = $evaluationStatus === 'rejected';
                            $decisionLabel = $isRejected ? '不接受' : '接受';
                            $decisionTime = $isRejected ? ($donation['rejected_at'] ?? null) : ($donation['approved_at'] ?? null);
                            ?>
                            <td><span class="status <?php echo $isRejected ? 'status-rejected' : 'status-approved'; ?>"><?php echo htmlspecialchars($decisionLabel); ?></span></td>
                            <td><?php echo htmlspecialchars($donation['donor_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type'] ?? ''] ?? '其他'); ?></td>
                            <td><?php echo htmlspecialchars($donation['item_name'] ?? '未填寫'); ?></td>
                            <td><?php echo htmlspecialchars($deliveryOptionLabels[$donation['delivery_option'] ?? ''] ?? '未指定'); ?></td>
                            <td><?php echo htmlspecialchars($formatDateTime($decisionTime)); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="view_review_donation">
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
                <i class="fas fa-check-circle"></i>
                <p>目前沒有已評估物資</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header">
        <h2>待發布</h2>
        <p>已接受但尚未發布到平台的物資。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($pendingPublishDonations)): ?>
            <table class="data-table">
                <thead><tr><th>狀態</th><th>店家名稱</th><th>物資類型</th><th>名稱</th><th>配送選擇</th><th>已評估完成時間</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($pendingPublishDonations as $donation): ?>
                    <tr>
                        <td><span class="status status-approved">尚未發布</span></td>
                        <td><?php echo htmlspecialchars($donation['donor_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type'] ?? ''] ?? '其他'); ?></td>
                        <td><?php echo htmlspecialchars($donation['item_name'] ?? '未填寫'); ?></td>
                        <td><?php echo htmlspecialchars($deliveryOptionLabels[$donation['delivery_option'] ?? ''] ?? '未指定'); ?></td>
                        <td><?php echo htmlspecialchars($formatDateTime($donation['approved_at'] ?? null)); ?></td>
                        <td><form method="post"><input type="hidden" name="action" value="view_publish_donation"><input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>"><button type="submit" class="btn btn-primary btn-sm">發布</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-clock"></i><p>目前沒有待發布物資</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header">
        <h2>已發布</h2>
        <p>已公開到平台、可供志工查看與配送的物資。</p>
    </div>
    <div class="card-body">
        <?php if (!empty($publishedDonations)): ?>
            <table class="data-table">
                <thead><tr><th>狀態</th><th>店家名稱</th><th>物資類型</th><th>名稱</th><th>配送選擇</th><th>發布時間</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($publishedDonations as $donation): ?>
                    <tr>
                        <td><span class="status status-success">已發布</span></td>
                        <td><?php echo htmlspecialchars($donation['donor_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type'] ?? ''] ?? '其他'); ?></td>
                        <td><?php echo htmlspecialchars($donation['item_name'] ?? '未填寫'); ?></td>
                        <td><?php echo htmlspecialchars($deliveryOptionLabels[$donation['delivery_option'] ?? ''] ?? '未指定'); ?></td>
                        <td><?php echo htmlspecialchars($formatDateTime($donation['published_at'] ?? null)); ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="view_publish_donation">
                                <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                <button type="submit" class="btn btn-primary btn-sm">查看</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-bullhorn"></i><p>目前沒有已發布物資</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewingDonation): ?>
    <?php $modalTitle = (($viewingDonation['status'] ?? '') === 'published') ? '已發布資訊' : '物資捐贈評估'; ?>
    <div class="donation-review-overlay" id="donationReviewModal" role="dialog" aria-modal="true" aria-labelledby="donationReviewTitle">
        <div class="donation-review-modal">
            <div class="card-header">
                <div>
                    <h2 id="donationReviewTitle"><?php echo htmlspecialchars($modalTitle); ?></h2>
                    <p><?php echo htmlspecialchars($viewingDonation['item_name'] ?? '物資詳情'); ?></p>
                </div>
                <button type="button" class="icon-btn" id="closeDonationReviewButton" aria-label="關閉評估視窗">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <?php $renderDetails($viewingDonation); ?>
                <?php if (($viewingDonation['status'] ?? '') === 'pending'): ?>
                    <form method="post" class="review-decision-form">
                        <input type="hidden" name="action" value="review_material_donation">
                        <input type="hidden" name="donation_id" value="<?php echo (int) $viewingDonation['donation_id']; ?>">
                        <input type="hidden" name="publish_now" id="publishNowInput" value="0">
                        <div class="rejection-reason-panel" id="rejectionReasonPanel" hidden>
                            <label for="rejectionReason"><strong>不接受原因</strong></label>
                            <textarea id="rejectionReason" name="rejection_reason" rows="3" placeholder="請填寫不接受原因"></textarea>
                            <button type="submit" name="decision" value="rejected" class="btn btn-danger">送出不接受</button>
                        </div>
                        <div class="foodbank-delivery-options">
                            <strong>配送方式</strong>
                            <label><input type="checkbox" name="foodbank_delivery_option" value="food_bank_pickup"> 自行派車</label>
                            <label><input type="checkbox" name="foodbank_delivery_option" value="volunteer_delivery"> 他人協助運送</label>
                        </div>
                        <div class="modal-actions">
                            <button type="button" id="showRejectionReasonButton" class="btn btn-danger">不接受</button>
                            <button type="button" id="showPublishChoiceButton" class="btn btn-primary">接受</button>
                        </div>
                        <div class="publish-choice-panel" id="publishChoicePanel" hidden>
                            <strong>接受後要發布到平台嗎？</strong>
                            <div class="modal-actions">
                                <button type="submit" name="decision" value="accepted" class="btn btn-secondary">稍後處理</button>
                                <button type="button" id="showPublishFieldsButton" class="btn btn-primary">現在發布</button>
                            </div>
                        </div>
                        <div class="publish-fields" id="publishFields" hidden>
                            <h3>發布物資資訊</h3>
                            <div class="grid-2">
                                <label>商家店名<input type="text" name="donor_name" value="<?php echo htmlspecialchars($viewingDonation['donor_name'] ?? '', ENT_QUOTES); ?>" required></label>
                                <label>商家地址<input type="text" name="donor_address" value="<?php echo htmlspecialchars($viewingDonation['donor_address'] ?? '', ENT_QUOTES); ?>"></label>
                                <label>物資名稱<input type="text" name="item_name" value="<?php echo htmlspecialchars($viewingDonation['item_name'] ?? '', ENT_QUOTES); ?>" required></label>
                                <label>數量<input type="number" step="0.01" name="quantity" value="<?php echo htmlspecialchars($viewingDonation['quantity'] ?? '', ENT_QUOTES); ?>" required></label>
                                <label>重量（公斤）<input type="number" step="0.01" name="weight_kg" value="<?php echo htmlspecialchars($viewingDonation['weight_kg'] ?? '', ENT_QUOTES); ?>"></label>
                                <label>大小<input type="text" name="size_description" value="<?php echo htmlspecialchars($viewingDonation['size_description'] ?? '', ENT_QUOTES); ?>"></label>
                                <label>照片路徑<input type="text" name="photo_path" value="<?php echo htmlspecialchars($viewingDonation['photo_path'] ?? '', ENT_QUOTES); ?>"></label>
                                <label>領取期限<input type="datetime-local" name="pickup_deadline" value="<?php echo !empty($viewingDonation['pickup_deadline']) ? date('Y-m-d\TH:i', strtotime($viewingDonation['pickup_deadline'])) : ''; ?>"></label>
                            </div>
                            <div class="publish-options">
                                <strong>是否需協助檢查物資</strong>
                                <label><input type="checkbox" name="need_inspection" value="1" checked> 檢查物資</label>
                                <label><input type="text" name="inspection_notes" placeholder="不需檢查時可填寫防弊貼紙提醒"></label>
                            </div>
                            <div class="publish-options">
                                <strong>是否要拆單</strong>
                                <label><input type="number" name="split_count" min="1" value="1"> 拆成幾個運送單</label>
                            </div>
                            <div class="publish-options">
                                <strong>獎勵機制</strong>
                                <label><input type="checkbox" name="reward_options[]" value="points" checked> 點數</label>
                                <label><input type="checkbox" name="reward_options[]" value="goods" checked> 物資</label>
                                <label><input type="checkbox" name="reward_options[]" value="free" checked> 無償</label>
                                <label><input type="checkbox" name="reward_options[]" value="service_hours" checked> 服務時數</label>
                            </div>
                            <div class="modal-actions"><button type="submit" name="decision" value="accepted" class="btn btn-primary">發布</button></div>
                        </div>
                    </form>
                <?php elseif (($viewingDonation['status'] ?? '') === 'assessed'): ?>
                    <div class="assessment-summary">
                        <?php $renderDetails($viewingDonation); ?>
                    </div>
                <?php elseif (($viewingDonation['status'] ?? '') === 'published'): ?>
                    <div class="publish-summary">
                        <p><strong>發布時間：</strong><?php echo htmlspecialchars($formatDateTime($viewingDonation['published_at'] ?? null)); ?></p>
                        <?php $renderDetails($viewingDonation); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    (function () {
        const reviewModal = document.getElementById('donationReviewModal');
        const closeButton = document.getElementById('closeDonationReviewButton');
        const showRejectionReasonButton = document.getElementById('showRejectionReasonButton');
        const rejectionReasonPanel = document.getElementById('rejectionReasonPanel');
        const rejectionReasonInput = document.getElementById('rejectionReason');
        const showPublishChoiceButton = document.getElementById('showPublishChoiceButton');
        const publishChoicePanel = document.getElementById('publishChoicePanel');
        const showPublishFieldsButton = document.getElementById('showPublishFieldsButton');
        const publishFields = document.getElementById('publishFields');
        const publishNowInput = document.getElementById('publishNowInput');
        const deliveryCheckboxes = document.querySelectorAll('input[name="foodbank_delivery_option"]');
        const closeModal = function () {
            if (reviewModal) {
                reviewModal.remove();
            }
        };

        if (closeButton) {
            closeButton.addEventListener('click', closeModal);
        }

        if (showRejectionReasonButton && rejectionReasonPanel) {
            showRejectionReasonButton.addEventListener('click', function () {
                rejectionReasonPanel.hidden = false;
                showRejectionReasonButton.hidden = true;
                if (rejectionReasonInput) {
                    rejectionReasonInput.focus();
                }
            });
        }

        if (showPublishChoiceButton && publishChoicePanel) {
            showPublishChoiceButton.addEventListener('click', function () {
                publishChoicePanel.hidden = false;
                showPublishChoiceButton.hidden = true;
            });
        }

        if (showPublishFieldsButton && publishFields) {
            showPublishFieldsButton.addEventListener('click', function () {
                publishFields.hidden = false;
                publishChoicePanel.hidden = true;
                if (publishNowInput) {
                    publishNowInput.value = '1';
                }
            });
        }

        deliveryCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (!checkbox.checked) {
                    return;
                }

                deliveryCheckboxes.forEach(function (otherCheckbox) {
                    if (otherCheckbox !== checkbox) {
                        otherCheckbox.checked = false;
                    }
                });
            });
        });

        const splitRadios = document.querySelectorAll('input[name="split_enabled"]');
        const splitCountInput = document.querySelector('input[name="split_count"]');
        splitRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (splitCountInput) {
                    splitCountInput.disabled = radio.value !== '1' || !radio.checked;
                    if (radio.value === '1' && radio.checked) {
                        splitCountInput.focus();
                    }
                }
            });
        });

        if (reviewModal) {
            reviewModal.addEventListener('click', function (event) {
                if (event.target === reviewModal) {
                    closeModal();
                }
            });
        }
    })();
</script>

<?php if ($viewingDonation): ?>
<style>
    .donation-review-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.48);
    }

    .donation-review-modal {
        width: min(820px, 100%);
        max-height: min(760px, 90vh);
        overflow-y: auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.24);
    }

    .donation-review-modal .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .donation-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px 28px;
    }

    .donation-detail-grid p,
    .donation-review-modal .card-body > p {
        margin: 0 0 12px;
        line-height: 1.6;
    }

    .donation-photos {
        margin-top: 8px;
    }

    .donation-photos img {
        width: 150px;
        height: 110px;
        object-fit: cover;
        margin: 10px 10px 0 0;
        border: 1px solid #dfe3ea;
        border-radius: 8px;
    }

    .review-decision-form {
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid #e5e7eb;
    }

    .review-decision-form textarea {
        width: 100%;
        margin-top: 8px;
    }

    .foodbank-delivery-options {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: center;
        margin-top: 18px;
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
    }

    .foodbank-delivery-options label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .rejection-reason-panel {
        margin-top: 14px;
        padding: 14px;
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 8px;
    }

    .rejection-reason-panel .btn {
        margin-top: 10px;
    }

    .publish-choice-panel,
    .publish-fields {
        margin-top: 16px;
        padding: 16px;
        background: #f8fafc;
        border: 1px solid #dbe3ee;
        border-radius: 8px;
    }

    .publish-fields h3 {
        margin-top: 0;
    }

    .publish-fields label,
    .publish-options label {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 12px;
    }

    .publish-options {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid #e5e7eb;
    }

    .publish-options strong {
        width: 100%;
    }

    .publish-options label {
        flex-direction: row;
        align-items: center;
        margin-bottom: 0;
    }

    .review-decision-form .modal-actions {
        justify-content: flex-end;
        gap: 10px;
    }

    @media (max-width: 700px) {
        .donation-detail-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
<?php endif; ?>
