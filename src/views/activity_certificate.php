<?php
/**
 * 企業／個人活動認領證書
 */

require_once BASE_PATH . '/src/models/ActivityModel.php';

$activityModel = new ActivityModel();
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$assignment = $assignmentId > 0 ? $activityModel->getAssignmentForCertificate($assignmentId) : null;

$isOfficial = in_array($currentUser['role'] ?? '', ['admin', 'foodbank_staff'], true);
$isOwner = $assignment && (int) $assignment['user_id'] === (int) $currentUser['user_id'];

if (!$assignment || (!$isOwner && !$isOfficial)) {
    echo '<div class="alert alert-error">找不到認領紀錄，或您沒有權限查看。</div>';
    return;
}

if ($assignment['activity_status'] !== 'completed') {
    echo '<div class="alert alert-info">活動尚未完成，證書將於活動結束後開放下載。</div>';
    return;
}
?>

<div class="view-header no-print"><div><h1 class="view-title">活動認領證書</h1><p class="view-subtitle">可列印或另存 PDF</p></div>
    <button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> 列印</button>
</div>

<div class="certificate-shell">
    <h1><?php echo $assignment['assignment_type'] === 'company' ? '企業公益永續認證證書' : '公益活動參與證明'; ?></h1>
    <p class="certificate-meta"><?php echo htmlspecialchars(APP_NAME); ?> · 證書編號 ACT-<?php echo str_pad((string) $assignment['assignment_id'], 6, '0', STR_PAD_LEFT); ?></p>
    <div class="certificate-body">
        <?php if ($assignment['assignment_type'] === 'company'): ?>
            <p>茲證明 <strong><?php echo htmlspecialchars($assignment['organization_name'] ?? '—'); ?></strong> 集體認領並支持本平台公益活動：</p>
        <?php else: ?>
            <p>茲證明 <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong> 參與本平台公益活動：</p>
        <?php endif; ?>
        <p><strong>活動名稱：</strong><?php echo htmlspecialchars($assignment['title']); ?></p>
        <p><strong>活動期間：</strong><?php echo htmlspecialchars($assignment['start_at']); ?> ～ <?php echo htmlspecialchars($assignment['end_at'] ?? '活動當日'); ?></p>
        <p>特此表揚其對在地公益與永續行動之支持與貢獻。</p>
    </div>
    <div class="certificate-signature">
        <span>核發單位：忠信食物銀行智慧平台</span>
        <span>核發日期：<?php echo date('Y-m-d'); ?></span>
    </div>
</div>
