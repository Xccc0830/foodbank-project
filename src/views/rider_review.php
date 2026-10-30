<?php
/**
 * 食物銀行審核一般會員的外送志工申請
 */

require_once BASE_PATH . '/src/models/RiderApplicationModel.php';

if (!$authService->can('page.rider_review')) {
    echo '<div class="alert alert-error">您沒有審核外送志工申請的權限。</div>';
    return;
}

$riderModel = new RiderApplicationModel();
$reviewMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
    $reviewerId = (int) ($currentUser['user_id'] ?? 0);

    if ($postAction === 'approve_rider') {
        $reviewMessage = $riderModel->approve($applicationId, $reviewerId, $reviewNotes)
            ? ['type' => 'success', 'text' => '已核准外送志工申請，會員現在可以接單。']
            : ['type' => 'error', 'text' => '核准失敗，申請可能已被處理。'];
    } elseif ($postAction === 'reject_rider') {
        $reviewMessage = $riderModel->reject($applicationId, $reviewerId, $reviewNotes)
            ? ['type' => 'success', 'text' => '已退回外送志工申請。']
            : ['type' => 'error', 'text' => '退回申請時必須填寫原因；申請也可能已被處理。'];
    }
}

$applications = $riderModel->listApplications();
$vehicleLabels = ['motorcycle' => '機車', 'car' => '汽車', 'both' => '機車與汽車'];
$statusLabels = ['pending' => '待審核', 'approved' => '已核准', 'rejected' => '已退回'];
$statusClasses = ['pending' => 'pending', 'approved' => 'active', 'rejected' => 'suspended'];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">外送志工審核</h1>
        <p class="view-subtitle">查看一般會員上傳的駕照，核准後才具備外送權限</p>
    </div>
</div>

<?php if ($reviewMessage): ?>
    <div class="alert alert-<?php echo $reviewMessage['type'] === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $reviewMessage['type'] === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($reviewMessage['text']); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>申請列表</h2><p>共 <?php echo count($applications); ?> 筆申請</p></div>
    <div class="card-body">
        <?php if ($applications): ?>
            <table class="data-table rider-review-table">
                <thead><tr><th>申請人</th><th>聯絡方式</th><th>車種</th><th>申請時間</th><th>駕照</th><th>狀態</th><th>審核</th></tr></thead>
                <tbody>
                    <?php foreach ($applications as $application): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($application['full_name']); ?></strong><br><small><?php echo htmlspecialchars($application['username']); ?></small></td>
                            <td><?php echo htmlspecialchars($application['phone'] ?? '-'); ?><br><small><?php echo htmlspecialchars($application['email']); ?></small></td>
                            <td><?php echo htmlspecialchars($vehicleLabels[$application['vehicle_type']] ?? $application['vehicle_type']); ?></td>
                            <td><?php echo htmlspecialchars($application['created_at']); ?></td>
                            <td><a href="?action=rider_license&amp;application_id=<?php echo (int) $application['application_id']; ?>" target="_blank" rel="noopener">查看駕照</a></td>
                            <td>
                                <span class="status status-<?php echo $statusClasses[$application['status']] ?? 'pending'; ?>"><?php echo htmlspecialchars($statusLabels[$application['status']] ?? $application['status']); ?></span>
                                <?php if (!empty($application['review_notes'])): ?><br><small><?php echo htmlspecialchars($application['review_notes']); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($application['status'] === 'pending'): ?>
                                    <form method="post" class="delivery-action-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="application_id" value="<?php echo (int) $application['application_id']; ?>">
                                        <input name="review_notes" placeholder="審核備註（退回時必填）">
                                        <button class="btn btn-primary btn-sm" type="submit" name="action" value="approve_rider">核准</button>
                                        <button class="btn btn-danger btn-sm" type="submit" name="action" value="reject_rider">退回</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-id-card"></i><p>目前沒有外送志工申請</p></div>
        <?php endif; ?>
    </div>
</div>
