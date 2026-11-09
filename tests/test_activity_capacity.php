<?php
/**
 * 活動人數回歸測試：一般會員不必等企業認領即可報名；企業依員工名單報名多人；
 * 所有報名類型都受活動總人數上限限制；員工身分證字號加密保存、顯示時遮罩。
 */
require __DIR__ . '/../config/database.php';

$testDbName = 'foodbank_test_activity_capacity_' . bin2hex(random_bytes(4));
$mysqlBin = '/Applications/XAMPP/xamppfiles/bin/mysql';
$mysqldumpBin = '/Applications/XAMPP/xamppfiles/bin/mysqldump';
$sourceDbName = DB_NAME;
$failures = 0;

function activityTestShellOk($command) {
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return $exitCode === 0;
}

if (!file_exists($mysqlBin) || !file_exists($mysqldumpBin)) {
    echo "SKIP activity-capacity-tests (mysql/mysqldump binaries not found at expected XAMPP path)\n";
    exit(0);
}

$created = activityTestShellOk(
    escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' -e ' .
    escapeshellarg("CREATE DATABASE {$testDbName};")
);
$cloned = $created && activityTestShellOk(
    escapeshellarg($mysqldumpBin) . ' -u ' . escapeshellarg(DB_USER) . ' ' . escapeshellarg($sourceDbName) .
    ' | ' . escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' ' . escapeshellarg($testDbName)
);

if (!$cloned) {
    echo "SKIP activity-capacity-tests (failed to clone database for isolated testing)\n";
    if ($created) {
        activityTestShellOk(
            escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' -e ' .
            escapeshellarg("DROP DATABASE IF EXISTS {$testDbName};")
        );
    }
    exit(0);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $testDbName, DB_PORT);
require __DIR__ . '/../src/models/ActivityModel.php';

$activityModel = new class($conn) extends ActivityModel {
    public function __construct($connection) {
        $this->db = $connection;
    }
};

$testUsers = [
    ['activity_company_test', 'enterprise', '容量測試商家'],
    ['activity_rider_one', 'general', '容量測試 Rider 1'],
    ['activity_rider_two', 'general', '容量測試 Rider 2'],
    ['activity_rider_three', 'general', '容量測試 Rider 3'],
];
$testUserIds = [];

foreach ($testUsers as [$username, $memberType, $fullName]) {
    $escapedUsername = $conn->real_escape_string($username);
    $escapedName = $conn->real_escape_string($fullName);
    $enterpriseName = $memberType === 'enterprise' ? "'{$escapedName}'" : 'NULL';
    $inserted = $conn->query(
        "INSERT INTO users (username, password, email, full_name, role, member_type, status, enterprise_name)
         VALUES ('{$escapedUsername}', 'test-password', '{$escapedUsername}@example.test', '{$escapedName}', 'member', '{$memberType}', 'active', {$enterpriseName})"
    );
    if (!$inserted) {
        echo "FAIL create-test-user ({$username}): {$conn->error}\n";
        $failures++;
        continue;
    }
    $testUserIds[$username] = (int) $conn->insert_id;
}

$activityCreated = $conn->query(
    "INSERT INTO activities (title, activity_type, start_at, capacity, status)
     VALUES ('活動名額測試', 'other', DATE_ADD(NOW(), INTERVAL 1 DAY), 5, 'planned')"
);
if (!$activityCreated || count($testUserIds) !== count($testUsers)) {
    echo "FAIL create-activity-fixture\n";
    $failures++;
} else {
    $activityId = (int) $conn->insert_id;
    $companyId = $testUserIds['activity_company_test'];
    $riderOneId = $testUserIds['activity_rider_one'];
    $riderTwoId = $testUserIds['activity_rider_two'];
    $riderThreeId = $testUserIds['activity_rider_three'];

    // 產生通過檢查碼的測試用身分證字號（A1 開頭 + 7 碼流水號 + 檢查碼）
    $makeId = function ($serial) {
        $body = 'A1' . str_pad((string) $serial, 7, '0', STR_PAD_LEFT);
        for ($check = 0; $check <= 9; $check++) {
            if (IdNumberHelper::isValid($body . $check)) {
                return $body . $check;
            }
        }
        return null;
    };
    $employees = [];
    for ($i = 1; $i <= 3; $i++) {
        $employees[] = ['full_name' => '員工' . $i, 'phone' => '091234567' . $i, 'id_number' => $makeId(1000 + $i)];
    }

    $riderBeforeCompany = $activityModel->register($activityId, $riderOneId, 'individual');
    $badList = $employees;
    $badList[1]['id_number'] = 'A123456788';
    $companyBadId = $activityModel->register($activityId, $companyId, 'company', '容量測試商家', 3, $badList);
    $companyNoList = $activityModel->register($activityId, $companyId, 'company', '容量測試商家', 3);
    $companyRegistered = $activityModel->register($activityId, $companyId, 'company', '容量測試商家', 3, $employees);
    $riderTwoRegistered = $activityModel->register($activityId, $riderTwoId, 'individual');
    $riderThreeRegistered = $activityModel->register($activityId, $riderThreeId, 'individual');

    if ($riderBeforeCompany && !$companyBadId && !$companyNoList && $companyRegistered && $riderTwoRegistered && !$riderThreeRegistered) {
        echo "PASS rider-registers-without-waiting-and-capacity-limit (1 + 3 + 1 seats)\n";
    } else {
        echo "FAIL rider-registers-without-waiting-and-capacity-limit\n";
        $failures++;
    }

    $assignment = $conn->query("SELECT assignment_id FROM activity_assignments WHERE activity_id = {$activityId} AND user_id = {$companyId}")->fetch_assoc();
    $roster = $assignment ? $activityModel->getEmployees((int) $assignment['assignment_id']) : [];
    $stored = $assignment ? $conn->query("SELECT id_number_encrypted FROM activity_participants WHERE assignment_id = " . (int) $assignment['assignment_id'] . " LIMIT 1")->fetch_assoc() : null;
    if (count($roster) === 3 && $roster[0]['id_number_masked'] === IdNumberHelper::mask($employees[0]['id_number']) && !isset($roster[0]['id_number'])
        && $stored && strpos($stored['id_number_encrypted'], $employees[0]['id_number']) === false) {
        echo "PASS employee-roster-encrypted-and-masked\n";
    } else {
        echo "FAIL employee-roster-encrypted-and-masked\n";
        $failures++;
    }

    $activities = $activityModel->getAllActivities();
    $activity = null;
    foreach ($activities as $row) {
        if ((int) $row['activity_id'] === $activityId) {
            $activity = $row;
            break;
        }
    }
    if ($activity && (int) $activity['participant_count'] === 5) {
        echo "PASS activity-participant-count-is-seat-total\n";
    } else {
        echo "FAIL activity-participant-count-is-seat-total\n";
        $failures++;
    }
}

$conn->close();
activityTestShellOk(
    escapeshellarg($mysqlBin) . ' -u ' . escapeshellarg(DB_USER) . ' -e ' .
    escapeshellarg("DROP DATABASE IF EXISTS {$testDbName};")
);

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll activity-capacity tests passed.\n";
