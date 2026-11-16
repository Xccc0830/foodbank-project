<?php
// 活動狀態依時間自動更新：還沒開始＝計畫中、進行中、結束後＝已完成（不能報名）；已取消的不動。
// 全程在交易內執行，結束後復原。
require_once __DIR__ . '/../src/models/BaseModel.php';

$connection = $GLOBALS['db']->getConnection();

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$staffId = 7;
$riderId = 12;

Transaction::begin($connection);
try {
    $make = function ($title, $start, $end, $status = 'planned') use ($connection, $staffId) {
        $endSql = $end === null ? 'NULL' : "'{$end}'";
        $connection->query("INSERT INTO activities (title, description, activity_type, start_at, end_at, status, created_by)
                            VALUES ('{$title}', '測試', 'other', '{$start}', {$endSql}, '{$status}', {$staffId})");
        return (int) $connection->insert_id;
    };
    $past = $make('已結束活動', date('Y-m-d H:i:s', strtotime('-3 days')), date('Y-m-d H:i:s', strtotime('-3 days +2 hours')));
    $pastNoEnd = $make('沒填結束時間的過去活動', date('Y-m-d H:i:s', strtotime('-2 days')), null);
    $now = $make('進行中活動', date('Y-m-d H:i:s', strtotime('-1 hour')), date('Y-m-d H:i:s', strtotime('+1 hour')));
    $future = $make('未來活動', date('Y-m-d H:i:s', strtotime('+3 days')), date('Y-m-d H:i:s', strtotime('+3 days +2 hours')), 'completed');
    $cancelled = $make('已取消活動', date('Y-m-d H:i:s', strtotime('-3 days')), null, 'cancelled');

    require_once __DIR__ . '/../src/models/ActivityModel.php';
    $activityModel = new ActivityModel(); // 建立時會依時間更新狀態
    $statusOf = function ($id) use ($connection) {
        return $connection->query("SELECT status FROM activities WHERE activity_id = {$id}")->fetch_assoc()['status'];
    };
    check('已結束的活動變成已完成', $statusOf($past) === 'completed');
    check('沒填結束時間的過去活動也算已完成', $statusOf($pastNoEnd) === 'completed');
    check('正在進行的活動變成進行中', $statusOf($now) === 'ongoing');
    check('改到未來的活動回到計畫中', $statusOf($future) === 'planned');
    check('已取消的活動不會被改掉', $statusOf($cancelled) === 'cancelled');
    check('已結束的活動不能報名', $activityModel->register($past, $riderId) === false);
} finally {
    Transaction::rollback($connection);
}
exit(empty($failed) ? 0 : 1);
