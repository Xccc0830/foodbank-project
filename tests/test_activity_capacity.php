<?php
/**
 * 活動人數回歸測試：確認企業可報名多人、Go Rider 可使用剩餘名額，
 * 且所有報名類型都受活動總人數上限限制。
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/database_test_helpers.php';

$testDbName = 'foodbank_test_activity_capacity_' . bin2hex(random_bytes(4));
$mysqlBin = findDatabaseCli('mysql');
$mysqldumpBin = findDatabaseCli('mysqldump');
$sourceDbName = DB_NAME;
$failures = 0;

function activityTestShellOk($command) {
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return $exitCode === 0;
}

if (DB_DRIVER !== 'mysql' || !$mysqlBin || !$mysqldumpBin) {
    echo "SKIP activity-capacity-tests (MySQL driver or mysql/mysqldump binaries unavailable)\n";
    exit(0);
}

$mysqlCommand = databaseCliPrefix($mysqlBin);
$mysqldumpCommand = databaseCliPrefix($mysqldumpBin);
$created = activityTestShellOk(
    $mysqlCommand . ' -e ' .
    escapeshellarg("CREATE DATABASE {$testDbName};")
);
$cloned = $created && activityTestShellOk(
    $mysqldumpCommand . ' ' . escapeshellarg($sourceDbName) .
    ' | ' . $mysqlCommand . ' ' . escapeshellarg($testDbName)
);

if (!$cloned) {
    echo "SKIP activity-capacity-tests (failed to clone database for isolated testing)\n";
    if ($created) {
        activityTestShellOk(
            $mysqlCommand . ' -e ' .
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

    $riderBeforeCompanyClaim = $activityModel->register($activityId, $riderOneId, 'individual');
    $companyRegistered = $activityModel->register($activityId, $companyId, 'company', '容量測試商家', 3);
    $riderOneRegistered = $activityModel->register($activityId, $riderOneId, 'individual');
    $riderTwoRegistered = $activityModel->register($activityId, $riderTwoId, 'individual');
    $riderThreeRegistered = $activityModel->register($activityId, $riderThreeId, 'individual');

    if (!$riderBeforeCompanyClaim && $companyRegistered && $riderOneRegistered && $riderTwoRegistered && !$riderThreeRegistered) {
        echo "PASS enterprise-priority-and-rider-remainder (3 + 1 + 1 seats)\n";
    } else {
        echo "FAIL enterprise-headcount-and-rider-remainder\n";
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
    $mysqlCommand . ' -e ' .
    escapeshellarg("DROP DATABASE IF EXISTS {$testDbName};")
);

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll activity-capacity tests passed.\n";
