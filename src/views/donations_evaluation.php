<?php
/**
 * 捐贈評估與派車管理 - 食物銀行方專用
 */

require_once BASE_PATH . '/src/models/DonationModel.php';

$donationModel = new DonationModel();
$currentRole = $currentUser['role'] ?? 'volunteer';
$isOfficial = in_array($currentRole, ['admin', 'foodbank_staff'], true);
$message = null;
$viewingDonation = null;

if (!$isOfficial) {
    echo '<div class="alert alert-error">只有食物銀行官方人員可以進行評估和派車操作。</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'view_donation') {
        $viewingDonation = $donationModel->getDonationById((int) $_POST['donation_id']);
    } elseif ($action === 'approve_donation') {
        $donationId = (int) $_POST['donation_id'];
        $approved = $donationModel->approveDonation($donationId, $_POST['delivery_method'] ?? 'volunteer_assist');
        $message = $approved
            ? ['type' => 'success', 'text' => '捐贈已批准，進入派車流程。']
            : ['type' => 'error', 'text' => '批准失敗。'];
    } elseif ($action === 'reject_donation') {
        $donationId = (int) $_POST['donation_id'];
        $reason = trim($_POST['rejection_reason'] ?? '');
        $rejected = $donationModel->rejectDonation($donationId, $reason);
        $message = $rejected
            ? ['type' => 'success', 'text' => '捐贈已拒絕，移至歷史紀錄。']
            : ['type' => 'error', 'text' => '拒絕操作失敗。'];
    } elseif ($action === 'publish_donation') {
        $donationId = (int) $_POST['donation_id'];
        $splitCount = (int) ($_POST['split_count'] ?? 1);
        $published = $donationModel->publishDonation($donationId, $splitCount);
        $message = $published
            ? ['type' => 'success', 'text' => '物資已發布，志工可開始領取。']
            : ['type' => 'error', 'text' => '發布失敗。'];
    } elseif ($action === 'update_delivery_status') {
        $donationId = (int) $_POST['donation_id'];
        $newStatus = $_POST['new_status'] ?? '';
        $updated = $donationModel->updateDeliveryStatus($donationId, $newStatus);
        $message = $updated
            ? ['type' => 'success', 'text' => '配送狀態已更新。']
            : ['type' => 'error', 'text' => '狀態更新失敗。'];
    }
}

$pendingDonations = $donationModel->getDonationsByEvaluationStatus('pending');
$approvedDonations = $donationModel->getDonationsByEvaluationStatus('approved_volunteer');
$approvedSelfDonations = $donationModel->getDonationsByEvaluationStatus('approved_self_delivery');
$publishedDonations = $donationModel->getPublishedDonations();

$statusLabels = [
    'pending' => '待評估',
    'approved_volunteer' => '已批准(志工協助)',
    'approved_self_delivery' => '已批准(商家自運)',
    'published' => '已發布',
    'waiting_pickup' => '待取貨',
    'volunteer_received' => '志工已領取',
    'in_transit' => '配送中',
    'at_foodbank' => '已送達食物銀行',
    'inspection_complete' => '檢查完成',
    'rejected' => '已拒絕'
];

$statusColors = [
    'pending' => 'warning',
    'approved_volunteer' => 'info',
    'approved_self_delivery' => 'info',
    'published' => 'success',
    'waiting_pickup' => 'info',
    'volunteer_received' => 'primary',
    'in_transit' => 'primary',
    'at_foodbank' => 'success',
    'inspection_complete' => 'success',
    'rejected' => 'danger'
];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">評估派車管理</h1>
        <p class="view-subtitle">評估捐贈物資、選擇派車方式、追蹤配送進度</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<?php if ($viewingDonation): ?>
<div class="card mt-32">
    <div class="card-header"><h2>捐贈物資詳情</h2></div>
    <div class="card-body">
        <div class="grid-2">
            <div>
                <h3>基本資訊</h3>
                <p><strong>捐贈者：</strong> <?php echo htmlspecialchars($viewingDonation['donor_name'] ?? ''); ?></p>
                <p><strong>物資名稱：</strong> <?php echo htmlspecialchars($viewingDonation['item_name'] ?? ''); ?></p>
                <p><strong>物資類型：</strong> <?php echo htmlspecialchars($viewingDonation['donation_type'] ?? ''); ?></p>
                <p><strong>數量：</strong> <?php echo htmlspecialchars($viewingDonation['quantity'] ?? 0); ?> <?php echo htmlspecialchars($viewingDonation['unit'] ?? '件'); ?></p>
                <p><strong>重量：</strong> <?php echo htmlspecialchars($viewingDonation['weight_kg'] ?? 0); ?> 公斤</p>
                <p><strong>尺寸：</strong> <?php echo htmlspecialchars($viewingDonation['size_description'] ?? '-'); ?></p>
            </div>
            <div>
                <h3>時效資訊</h3>
                <p><strong>有效期限：</strong> <?php echo htmlspecialchars($viewingDonation['expiry_date'] ?? '無'); ?></p>
                <p><strong>領取期限：</strong> <?php echo htmlspecialchars($viewingDonation['pickup_deadline'] ?? '無'); ?></p>
                <p><strong>捐贈日期：</strong> <?php echo htmlspecialchars($viewingDonation['donation_date'] ?? ''); ?></p>
                <p><strong>捐贈選項：</strong>
                    <?php echo ($viewingDonation['delivery_option'] ?? 'volunteer_delivery') === 'self_delivery' ? '商家自運' : '需派車運送'; ?>
                </p>
            </div>
        </div>

        <?php if ($viewingDonation['photo_path']): ?>
        <div style="margin-top: 20px;">
            <h3>物資照片</h3>
            <img src="<?php echo htmlspecialchars($viewingDonation['photo_path']); ?>" style="max-width: 300px; border-radius: 4px;">
        </div>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <h3>備註</h3>
            <p><?php echo htmlspecialchars($viewingDonation['notes'] ?? '無'); ?></p>
        </div>

        <?php if ($viewingDonation['status'] === 'pending'): ?>
        <div style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">
            <h3>評估決定</h3>
            <div class="grid-2">
                <form method="post">
                    <input type="hidden" name="action" value="approve_donation">
                    <input type="hidden" name="donation_id" value="<?php echo (int) $viewingDonation['donation_id']; ?>">
                    <div class="form-group">
                        <label>派車方式*</label>
                        <select name="delivery_method" required>
                            <option value="volunteer_assist">志工協助</option>
                            <option value="self_delivery">食物銀行自行派車</option>
                        </select>
                    </div>
                    <button class="btn btn-success" type="submit"><i class="fas fa-check"></i> 批准接受</button>
                </form>
                <form method="post" onsubmit="return confirm('確定要拒絕此捐贈嗎？');">
                    <input type="hidden" name="action" value="reject_donation">
                    <input type="hidden" name="donation_id" value="<?php echo (int) $viewingDonation['donation_id']; ?>">
                    <div class="form-group">
                        <label>拒絕原因</label>
                        <textarea name="rejection_reason" placeholder="說明拒絕原因"></textarea>
                    </div>
                    <button class="btn btn-danger" type="submit"><i class="fas fa-times"></i> 拒絕</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a class="btn btn-secondary" href="?page=donations_evaluation">返回列表</a>
        </div>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header"><h2>待評估捐贈</h2><p>共 <?php echo count($pendingDonations); ?> 筆待評估物資</p></div>
    <div class="card-body">
        <?php if ($pendingDonations): ?>
            <div class="donations-evaluation-table-body">
            <table class="data-table donations-evaluation-table">
                <thead>
                    <tr>
                        <th>捐贈者</th>
                        <th>物資名稱</th>
                        <th>類型</th>
                        <th>數量</th>
                        <th>有效期限</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingDonations as $donation): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($donation['donor_name']); ?></td>
                        <td><?php echo htmlspecialchars($donation['item_name']); ?></td>
                        <td><?php echo htmlspecialchars($donation['donation_type']); ?></td>
                        <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                        <td><?php echo htmlspecialchars($donation['expiry_date'] ?? '無'); ?></td>
                        <td>
                            <form method="post" class="delivery-action-form">
                                <input type="hidden" name="action" value="view_donation">
                                <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                <button class="btn btn-primary btn-sm" type="submit">查看詳情</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-check-circle"></i><p>暫無待評估物資</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>已批准物資</h2><p>已批准待發布</p></div>
    <div class="card-body">
        <?php
        $allApproved = array_merge($approvedDonations, $approvedSelfDonations);
        if ($allApproved):
        ?>
            <div class="donations-evaluation-table-body">
            <table class="data-table donations-evaluation-table">
                <thead>
                    <tr>
                        <th>捐贈者</th>
                        <th>物資名稱</th>
                        <th>派車方式</th>
                        <th>數量</th>
                        <th>防拆碼</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allApproved as $donation): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($donation['donor_name']); ?></td>
                        <td><?php echo htmlspecialchars($donation['item_name']); ?></td>
                        <td><?php echo ($donation['delivery_method'] ?? 'volunteer_assist') === 'self_delivery' ? '商家自運' : '志工協助'; ?></td>
                        <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                        <td><code><?php echo htmlspecialchars($donation['seal_code'] ?? 'N/A'); ?></code></td>
                        <td>
                            <form method="post" class="delivery-action-form">
                                <input type="hidden" name="action" value="publish_donation">
                                <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <select name="split_count" style="width: 60px;">
                                        <option value="1">不拆單</option>
                                        <option value="2">拆2份</option>
                                        <option value="3">拆3份</option>
                                        <option value="4">拆4份</option>
                                        <option value="5">拆5份</option>
                                    </select>
                                    <button class="btn btn-success btn-sm" type="submit">發布</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>暫無已批准物資</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>已發布物資配送追蹤</h2><p>監控志工配送進度</p></div>
    <div class="card-body">
        <?php if ($publishedDonations): ?>
            <div class="donations-evaluation-table-body">
            <table class="data-table donations-evaluation-table">
                <thead>
                    <tr>
                        <th>物資名稱</th>
                        <th>派車方式</th>
                        <th>防拆碼</th>
                        <th>現況狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($publishedDonations as $donation): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($donation['item_name']); ?></td>
                        <td><?php echo ($donation['delivery_method'] ?? 'volunteer_assist') === 'self_delivery' ? '商家自運' : '志工協助'; ?></td>
                        <td><code><?php echo htmlspecialchars($donation['seal_code'] ?? 'N/A'); ?></code></td>
                        <td>
                            <span class="status status-<?php echo $statusColors[$donation['status']] ?? 'secondary'; ?>">
                                <?php echo $statusLabels[$donation['status']] ?? $donation['status']; ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" class="delivery-action-form">
                                <input type="hidden" name="action" value="update_delivery_status">
                                <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                <select name="new_status" style="width: 140px;">
                                    <option value="">選擇新狀態</option>
                                    <option value="waiting_pickup">待取貨</option>
                                    <option value="volunteer_received">志工已領取</option>
                                    <option value="in_transit">配送中</option>
                                    <option value="at_foodbank">已送達食物銀行</option>
                                    <option value="inspection_complete">檢查完成</option>
                                </select>
                                <button class="btn btn-primary btn-sm" type="submit">更新</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-package"></i><p>暫無已發布物資</p></div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
