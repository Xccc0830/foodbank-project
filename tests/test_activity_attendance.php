<?php
require_once __DIR__ . '/../src/models/ActivityModel.php';

$model = new ActivityModel();
$db = $GLOBALS['db']->getConnection();
$staffResult = $db->query("SELECT user_id FROM users WHERE role = 'foodbank_staff' AND status = 'active' ORDER BY user_id LIMIT 1");
$memberResult = $db->query("SELECT user_id FROM users WHERE role = 'member' AND member_type = 'general' AND status = 'active' ORDER BY user_id LIMIT 1");
$staff = $staffResult ? $staffResult->fetch_assoc() : null;
$member = $memberResult ? $memberResult->fetch_assoc() : null;
if (!$staff || !$member) {
    echo "SKIP activity-attendance-tests (active staff/member fixtures unavailable)\n";
    exit(0);
}

$failed = false;
function attendanceCheck($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

Transaction::begin($db);
try {
    $start = date('Y-m-d H:i:s', strtotime('-2 days'));
    $end = date('Y-m-d H:i:s', strtotime('-2 days +2 hours'));
    $db->query("INSERT INTO activities (title, activity_type, start_at, end_at, status) VALUES ('出席點數測試', 'other', '{$start}', '{$end}', 'completed')");
    $completedActivityId = (int) $db->insert_id;
    $db->query("INSERT INTO activity_assignments (activity_id, user_id, assignment_type, participant_count) VALUES ({$completedActivityId}, " . (int) $member['user_id'] . ", 'individual', 1)");
    $assignmentId = (int) $db->insert_id;

    $award = $model->recordAttendance($assignmentId, (int) $staff['user_id'], '2');
    attendanceCheck('依實際兩小時計算 floor(2 × 196 × 15%) = 58 點', $award && $award['points'] === 58 && $award['hours'] === 2.0);
    attendanceCheck('完成簽到後不可重複領點', !$model->recordAttendance($assignmentId, (int) $staff['user_id'], '2'));
    $saved = $db->query("SELECT status, attendance_hours, points FROM activity_assignments WHERE assignment_id = {$assignmentId}")->fetch_assoc();
    attendanceCheck('出席時數、狀態及獎勵點數一併保存', $saved && $saved['status'] === 'attended' && $saved['attendance_hours'] === '2.00' && (int) $saved['points'] === 58);
    $ledger = $db->query("SELECT COUNT(*) AS total FROM point_transactions WHERE user_id = " . (int) $member['user_id'] . " AND description LIKE '公益活動「出席點數測試」%'")->fetch_assoc();
    attendanceCheck('活動獎勵寫入點數明細', $ledger && (int) $ledger['total'] === 1);

    $db->query("INSERT INTO activities (title, activity_type, start_at, end_at, status) VALUES ('極短時數測試', 'other', '{$start}', '{$end}', 'completed')");
    $shortActivityId = (int) $db->insert_id;
    $db->query("INSERT INTO activity_assignments (activity_id, user_id, assignment_type, participant_count) VALUES ({$shortActivityId}, " . (int) $member['user_id'] . ", 'individual', 1)");
    $shortAssignmentId = (int) $db->insert_id;
    $shortAward = $model->recordAttendance($shortAssignmentId, (int) $staff['user_id'], '0.01');
    attendanceCheck('不足一點時依公式登記出席但不寫入零點交易', $shortAward && $shortAward['points'] === 0);

    $db->query("INSERT INTO activities (title, activity_type, start_at, end_at, status) VALUES ('未完成活動測試', 'other', DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 'planned')");
    $futureActivityId = (int) $db->insert_id;
    $db->query("INSERT INTO activity_assignments (activity_id, user_id, assignment_type, participant_count) VALUES ({$futureActivityId}, " . (int) $member['user_id'] . ", 'individual', 1)");
    attendanceCheck('活動尚未結束不能登記出席', !$model->recordAttendance((int) $db->insert_id, (int) $staff['user_id'], '1'));
} finally {
    Transaction::rollback($db);
}

exit($failed ? 1 : 0);
