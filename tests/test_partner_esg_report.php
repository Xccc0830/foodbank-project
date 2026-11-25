<?php
require_once __DIR__ . '/../src/models/BaseModel.php';

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="test">';
}

$connection = $GLOBALS['db']->getConnection();
runAllSchemaChecks($connection);
$staffResult = $connection->query("SELECT user_id FROM users WHERE role = 'foodbank_staff' AND status = 'active' ORDER BY user_id LIMIT 1");
$staff = $staffResult ? $staffResult->fetch_assoc() : null;
if (!$staff) {
    echo "SKIP partner-esg-report-tests (active staff fixture unavailable)\n";
    exit(0);
}

$failed = false;
function esgCheck($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$currentUser = ['user_id' => (int) $staff['user_id'], 'role' => 'foodbank_staff'];
$db = $GLOBALS['db'];
$quarter = date('Y') . '-Q' . (string) ceil((int) date('n') / 3);
$existingDonorResult = $connection->query("SELECT user_id FROM users WHERE role = 'member' AND member_type = 'enterprise' AND status = 'active' ORDER BY user_id LIMIT 1");
$donor = $existingDonorResult ? $existingDonorResult->fetch_assoc() : null;
if (!$donor) {
    echo "SKIP partner-esg-report-tests (active enterprise fixture unavailable)\n";
    exit(0);
}

Transaction::begin($connection);
try {
    $_GET = ['partner_quarter' => $quarter];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include __DIR__ . '/../src/views/reports.php';
    $html = ob_get_clean();
    esgCheck('季度 ESG 指標及政策稽核面板可載入', strpos($html, '合作商家季度 ESG 指標與認證') !== false
        && strpos($html, '配送政策稽核紀錄') !== false);

    $_GET = [];
    $_POST = [
        'action' => 'save_partner_esg_review',
        'partner_quarter' => $quarter,
        'donor_id' => (int) $donor['user_id'],
        'review_status' => 'certified',
        'review_note' => '季度評估測試',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    include __DIR__ . '/../src/views/reports.php';
    $html = ob_get_clean();
    $review = $connection->query(
        "SELECT status, review_note FROM partner_esg_reviews WHERE donor_id = " . (int) $donor['user_id'] . "
         AND quarter = '" . $connection->real_escape_string($quarter) . "'"
    )->fetch_assoc();
    esgCheck('食物銀行可儲存季度認證結果與評估說明', strpos($html, '季度商家 ESG 評估已儲存') !== false
        && $review && $review['status'] === 'certified' && $review['review_note'] === '季度評估測試');
} finally {
    Transaction::rollback($connection);
}

exit($failed ? 1 : 0);
