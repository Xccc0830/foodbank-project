<?php
/**
 * 企業／商家捐贈證明
 */

require_once BASE_PATH . '/src/models/DonationModel.php';

$donationModel = new DonationModel();
$donationId = (int) ($_GET['donation_id'] ?? 0);
$donation = $donationId > 0 ? $donationModel->getDonationById($donationId) : null;

$isOfficial = in_array($currentUser['role'] ?? '', ['admin', 'foodbank_staff'], true);
$isOwner = $donation && !empty($donation['donor_id']) && (int) $donation['donor_id'] === (int) $currentUser['user_id'];

if (!$donation || (!$isOwner && !$isOfficial)) {
    echo '<div class="alert alert-error">找不到捐贈紀錄，或您沒有權限查看。</div>';
    return;
}

if (!in_array($donation['status'], ['approved', 'received'], true)) {
    echo '<div class="alert alert-info">此筆捐贈尚未完成評估／收貨，證明將於批准後開放下載。</div>';
    return;
}
?>

<div class="view-header no-print"><div><h1 class="view-title">捐贈證明</h1><p class="view-subtitle">可作為稅務減免或永續報告書附件</p></div>
    <button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> 列印</button>
</div>

<div class="certificate-shell">
    <h1>公益物資捐贈證明</h1>
    <p class="certificate-meta"><?php echo htmlspecialchars(APP_NAME); ?> · 證書編號 DN-<?php echo str_pad((string) $donation['donation_id'], 6, '0', STR_PAD_LEFT); ?></p>
    <div class="certificate-body">
        <p>茲證明 <strong><?php echo htmlspecialchars($donation['donor_name']); ?></strong> 捐贈以下物資予忠信食物銀行智慧平台：</p>
        <p><strong>物資名稱：</strong><?php echo htmlspecialchars($donation['item_name'] ?? '—'); ?></p>
        <p><strong>數量：</strong><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit'] ?? ''); ?></p>
        <p><strong>重量：</strong><?php echo $donation['weight_kg'] !== null ? htmlspecialchars($donation['weight_kg']) . ' kg' : '—'; ?></p>
        <p><strong>捐贈日期：</strong><?php echo date('Y-m-d', strtotime($donation['donation_date'])); ?></p>
        <p>特此證明，感謝支持在地食物銀行公益行動與永續發展。</p>
    </div>
    <div class="certificate-signature">
        <span>核發單位：忠信食物銀行智慧平台</span>
        <span>核發日期：<?php echo date('Y-m-d'); ?></span>
    </div>
</div>
