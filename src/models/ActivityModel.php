<?php
/**
 * 公益活動與認領模型
 */

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/../helpers/IdNumberHelper.php';

class ActivityModel extends BaseModel {
    protected $table = 'activities';

    /** 企業報名的單一活動最多可登記的員工人數（避免一次送出過大的表單） */
    const MAX_EMPLOYEES_PER_REGISTRATION = 200;

    public function __construct() {
        parent::__construct();
        if (schemaChecksNeeded()) {
            $this->ensureAttendanceColumns();
        }
        $this->syncStatusesByTime();
    }

    private function ensureAttendanceColumns() {
        foreach ([
            'attendance_hours' => 'DECIMAL(5,2) DEFAULT NULL',
            'attended_at' => 'DATETIME DEFAULT NULL',
        ] as $column => $definition) {
            $result = $this->db->query("SHOW COLUMNS FROM activity_assignments LIKE '{$column}'");
            if (!$result) {
                throw new RuntimeException("無法檢查 activity_assignments.{$column}: " . $this->db->error);
            }
            if ($result->num_rows === 0 && !$this->db->query("ALTER TABLE activity_assignments ADD COLUMN `{$column}` {$definition}")) {
                throw new RuntimeException("無法新增 activity_assignments.{$column}: " . $this->db->error);
            }
        }
        if (!$this->db->query("UPDATE activity_assignments SET points = 0 WHERE status = 'registered' AND points = 5")) {
            throw new RuntimeException('無法清除尚未出席活動的預發點數: ' . $this->db->error);
        }
    }

    /**
     * 活動狀態依時間自動更新（已取消的不動）：
     * 還沒開始 → 計畫中；開始後 → 進行中；結束後 → 已完成（不能再報名，參加者可下載證書）。
     * 沒填結束時間的活動，以開始當天 23:59 視為結束。每次請求只更新一次。
     */
    private function syncStatusesByTime() {
        static $synced = false;
        if ($synced) {
            return;
        }
        $synced = true;
        $this->db->query(
            "UPDATE activities
             SET status = CASE
                 WHEN COALESCE(end_at, CONCAT(DATE(start_at), ' 23:59:59')) < NOW() THEN 'completed'
                 WHEN start_at <= NOW() THEN 'ongoing'
                 ELSE 'planned'
             END
             WHERE status <> 'cancelled' AND start_at IS NOT NULL"
        );
    }

    /** 企業報名的參加員工名冊（身分證字號加密保存，供活動保險使用）。DDL 會自動提交，須在交易外執行。 */
    public function ensureParticipantsTable() {
        // 建表會讓 MySQL 自動把進行中的交易存檔，交易中不建（資料表平常早已存在）
        if (Transaction::active()) {
            return;
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS activity_participants (
                participant_id INT AUTO_INCREMENT PRIMARY KEY,
                assignment_id INT NOT NULL,
                full_name VARCHAR(50) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                id_number_encrypted TEXT NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_activity_participants_assignment (assignment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * 檢查企業填寫的員工名單。通過回傳 null，否則回傳給使用者看的錯誤訊息。
     * @param array<int,array{full_name?:string,phone?:string,id_number?:string}> $employees
     */
    public static function validateEmployees(array $employees) {
        if (!$employees) {
            return '請至少填寫 1 位參加員工。';
        }
        if (count($employees) > self::MAX_EMPLOYEES_PER_REGISTRATION) {
            return '單次最多登記 ' . self::MAX_EMPLOYEES_PER_REGISTRATION . ' 位員工。';
        }
        $seenIds = [];
        foreach (array_values($employees) as $index => $employee) {
            $label = '第 ' . ($index + 1) . ' 位員工';
            $name = trim((string) ($employee['full_name'] ?? ''));
            $phone = preg_replace('/[\s-]/', '', (string) ($employee['phone'] ?? ''));
            $idNumber = IdNumberHelper::normalize($employee['id_number'] ?? '');
            if ($name === '' || mb_strlen($name) > 50) {
                return $label . '的姓名未填寫或太長。';
            }
            if (!preg_match('/^09\d{8}$/', $phone)) {
                return $label . '的手機號碼格式不正確（例如 0912345678）。';
            }
            if (!IdNumberHelper::isValid($idNumber)) {
                return $label . '的身分證字號格式不正確。';
            }
            if (isset($seenIds[$idNumber])) {
                return $label . '的身分證字號和第 ' . $seenIds[$idNumber] . ' 位重複。';
            }
            $seenIds[$idNumber] = $index + 1;
        }
        return null;
    }

    /** 某筆企業報名的員工名冊；$withFullId 為 true 才解密完整身分證字號（只給食物銀行匯出保險名冊用） */
    public function getEmployees($assignmentId, $withFullId = false) {
        $assignmentId = (int) $assignmentId;
        $rows = $this->query("SELECT participant_id, full_name, phone, id_number_encrypted FROM activity_participants WHERE assignment_id = {$assignmentId} ORDER BY participant_id");
        foreach ($rows as &$row) {
            $plain = IdNumberHelper::decrypt($row['id_number_encrypted']);
            $row['id_number_masked'] = $plain ? IdNumberHelper::mask($plain) : '';
            if ($withFullId) {
                $row['id_number'] = $plain ?: '';
            }
            unset($row['id_number_encrypted']);
        }
        unset($row);
        return $rows;
    }

    public function getAllActivities() {
        return $this->query(
            "SELECT a.*,
                    COALESCE(SUM(CASE WHEN aa.status <> 'cancelled' THEN aa.participant_count ELSE 0 END), 0) AS participant_count,
                    MAX(CASE WHEN aa.status <> 'cancelled' AND aa.assignment_type = 'company' THEN 1 ELSE 0 END) AS has_company_claim
             FROM activities a
             LEFT JOIN activity_assignments aa ON aa.activity_id = a.activity_id
             GROUP BY a.activity_id
             ORDER BY a.start_at ASC"
        );
    }

    public function createActivity($data) {
        return $this->insert($data);
    }

    public function getActivityById($activityId) {
        $activityId = (int) $activityId;
        $result = $this->db->query("SELECT a.*, u.role AS creator_role FROM activities a LEFT JOIN users u ON u.user_id = a.created_by WHERE a.activity_id = {$activityId} LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }

    public function getRemainingCapacity($activityId) {
        $activityId = (int) $activityId;
        $result = $this->db->query(
            "SELECT a.capacity,
                    COALESCE(SUM(CASE WHEN aa.status <> 'cancelled' THEN aa.participant_count ELSE 0 END), 0) AS participant_count
             FROM activities a
             LEFT JOIN activity_assignments aa ON aa.activity_id = a.activity_id
             WHERE a.activity_id = {$activityId}
             GROUP BY a.activity_id
             LIMIT 1"
        );
        if (!$result) {
            error_log('無法取得活動剩餘名額：' . $this->db->error);
            return false;
        }

        $activity = $result->fetch_assoc();
        if (!$activity) {
            return false;
        }

        $capacity = (int) ($activity['capacity'] ?? 0);
        return $capacity > 0 ? max(0, $capacity - (int) $activity['participant_count']) : null;
    }

    public function getParticipants($activityId) {
        $activityId = (int) $activityId;
        return $this->query(
            "SELECT aa.assignment_id, aa.user_id, aa.assignment_type, aa.organization_name, aa.participant_count,
                    aa.status AS assignment_status, aa.attendance_hours, aa.points AS awarded_points,
                    u.full_name, u.username, u.email, u.phone
             FROM activity_assignments aa
             JOIN users u ON u.user_id = aa.user_id
             WHERE aa.activity_id = {$activityId} AND aa.status <> 'cancelled'
             ORDER BY aa.created_at ASC"
        );
    }

    public function recordAttendance($assignmentId, $staffId, $hours) {
        $assignmentId = (int) $assignmentId;
        $staffId = (int) $staffId;
        $hoursText = is_scalar($hours) ? trim((string) $hours) : '';
        if ($assignmentId <= 0 || $staffId <= 0
            || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $hoursText)
            || (float) $hoursText <= 0 || (float) $hoursText > 999.99) {
            return false;
        }

        $staffResult = $this->db->query("SELECT 1 FROM users WHERE user_id = {$staffId} AND role = 'foodbank_staff' AND status = 'active' LIMIT 1");
        if (!$staffResult || $staffResult->num_rows !== 1 || !Transaction::begin($this->db)) {
            return false;
        }

        try {
            $assignmentResult = $this->db->query(
                "SELECT aa.user_id, aa.assignment_type, aa.status, aa.points, a.title, a.status AS activity_status
                 FROM activity_assignments aa
                 JOIN activities a ON a.activity_id = aa.activity_id
                 WHERE aa.assignment_id = {$assignmentId}
                 LIMIT 1 FOR UPDATE"
            );
            $assignment = $assignmentResult ? $assignmentResult->fetch_assoc() : null;
            if (!$assignment || $assignment['assignment_type'] !== 'individual'
                || $assignment['status'] !== 'registered' || $assignment['activity_status'] !== 'completed') {
                Transaction::rollback($this->db);
                return false;
            }

            $hoursHundredths = (int) round((float) $hoursText * 100);
            $points = intdiv($hoursHundredths * 196 * 15, 10000);
            $update = $this->db->prepare(
                "UPDATE activity_assignments
                 SET status = 'attended', attendance_hours = ?, points = ?, attended_at = NOW()
                 WHERE assignment_id = ? AND status = 'registered'"
            );
            if (!$update) {
                throw new RuntimeException('無法準備活動出席更新: ' . $this->db->error);
            }
            $update->bind_param('dii', $hoursText, $points, $assignmentId);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('無法儲存活動出席資料: ' . $update->error);
            }

            if ($points > 0) {
                $description = $this->db->real_escape_string(
                    '公益活動「' . $assignment['title'] . '」出席 ' . number_format($hoursHundredths / 100, 2, '.', '') . ' 小時獎勵'
                );
                if (!$this->db->query(
                    "INSERT INTO point_transactions (user_id, points, transaction_type, description)
                     VALUES (" . (int) $assignment['user_id'] . ", {$points}, 'earned', '{$description}')"
                )) {
                    throw new RuntimeException('無法寫入活動出席點數: ' . $this->db->error);
                }
            }

            if (!Transaction::commit($this->db)) {
                throw new RuntimeException('無法儲存活動出席交易: ' . $this->db->error);
            }
            return ['success' => true, 'points' => $points, 'hours' => (float) ($hoursHundredths / 100)];
        } catch (Throwable $exception) {
            Transaction::rollback($this->db);
            error_log('記錄活動出席失敗: ' . $exception->getMessage());
            return false;
        }
    }

    public function canManageActivity($activityId, $userId, $userRole = null) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;
        $activity = $this->getActivityById($activityId);
        if (!$activity) {
            return false;
        }

        $isCreator = ((int) ($activity['created_by'] ?? 0)) === $userId;
        $role = $userRole ?? 'foodbank_staff';

        // 活動發起人（食物銀行、企業或非營利組織）可管理自己的活動；食物銀行人員也可管理所有活動。
        return $isCreator || $role === 'foodbank_staff';
    }

    public function updateActivity($activityId, $userId, $userRole, $data) {
        $activityId = (int) $activityId;
        if (!$this->canManageActivity($activityId, $userId, $userRole)) {
            return false;
        }

        $allowedKeys = ['title', 'activity_type', 'activity_type_detail', 'description', 'start_at', 'end_at', 'capacity'];
        $set = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }

            if ($value === null) {
                $set[] = "{$key} = NULL";
                continue;
            }

            $set[] = "{$key} = '" . $this->db->real_escape_string((string) $value) . "'";
        }

        if (empty($set)) {
            return false;
        }

        $sql = "UPDATE activities SET " . implode(', ', $set) . " WHERE activity_id = {$activityId} LIMIT 1";
        return (bool) $this->db->query($sql);
    }

    public function canUserRegisterActivity($activityId, $userId) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;

        $result = $this->db->query(
            "SELECT status, cancelled_at FROM activity_assignments
             WHERE activity_id = {$activityId} AND user_id = {$userId}
             ORDER BY assignment_id DESC LIMIT 1"
        );

        if (!$result || $result->num_rows === 0) {
            return true;
        }

        $existing = $result->fetch_assoc();
        if (($existing['status'] ?? 'registered') === 'registered') {
            return false;
        }

        return true;
    }

    /** 會員已通過身分驗證時回傳其身分證字號（報名時可直接沿用，不必再輸入）；否則回傳 null */
    public function getVerifiedIdNumber($userId) {
        $userId = (int) $userId;
        $rows = $this->query("SELECT id_verify_status, id_number_encrypted FROM users WHERE user_id = {$userId} LIMIT 1");
        if (!$rows || ($rows[0]['id_verify_status'] ?? '') !== 'approved' || empty($rows[0]['id_number_encrypted'])) {
            return null;
        }
        return IdNumberHelper::decrypt($rows[0]['id_number_encrypted']);
    }

    /**
     * 報名活動。參加者都要附上姓名、電話、身分證字號（供活動保險使用）：
     * 企業會員附員工名單，報名人數即名單人數；一般會員附本人 1 筆資料。
     * 兩者可同時報名，不必等企業先認領，只受活動總名額限制。
     */
    public function register($activityId, $userId, $assignmentType = 'individual', $organizationName = null, $headcount = null, array $employees = []) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;
        $this->ensureParticipantsTable();
        $userResult = $this->db->query("SELECT role, member_type, enterprise_name, full_name FROM users WHERE user_id = {$userId} AND status = 'active' LIMIT 1");
        $userInfo = $userResult ? $userResult->fetch_assoc() : null;
        if (!$userInfo) {
            return false;
        }

        if (($userInfo['role'] ?? '') !== 'member') {
            return false;
        }

        if (($userInfo['role'] ?? '') === 'member' && !in_array($userInfo['member_type'] ?? '', ['general', 'enterprise'], true)) {
            return false;
        }

        $isEnterpriseMember = ($userInfo['member_type'] ?? '') === 'enterprise';
        $assignmentType = $isEnterpriseMember ? 'company' : 'individual';
        if ($isEnterpriseMember) {
            $organizationName = trim((string) ($userInfo['enterprise_name'] ?: $organizationName ?: $userInfo['full_name']));
        }

        // 企業會員：人數就是員工名單的人數；個人會員只能登記本人 1 筆。名單都必須完整有效。
        $employees = array_values($employees);
        if (self::validateEmployees($employees) !== null) {
            return false;
        }
        if ($isEnterpriseMember) {
            if ($headcount !== null && (int) $headcount !== count($employees)) {
                return false;
            }
            $headcount = count($employees);
        } elseif (count($employees) !== 1) {
            return false;
        } else {
            $headcount = 1;
        }

        if ($assignmentType === 'company') {
            if (trim((string) $organizationName) === '') {
                return false;
            }
        }

        Transaction::begin($this->db);
        try {
            $activityResult = $this->db->query(
                "SELECT capacity FROM activities
                 WHERE activity_id = {$activityId} AND status IN ('planned', 'ongoing')
                 LIMIT 1 FOR UPDATE"
            );
            $activity = $activityResult ? $activityResult->fetch_assoc() : null;
            if (!$activity) {
                Transaction::rollback($this->db);
                return false;
            }

            $existingResult = $this->db->query(
                "SELECT assignment_id, status FROM activity_assignments
                 WHERE activity_id = {$activityId} AND user_id = {$userId}
                 ORDER BY assignment_id DESC LIMIT 1"
            );
            if (!$existingResult) {
                Transaction::rollback($this->db);
                return false;
            }
            $existingAssignment = $existingResult->fetch_assoc();
            if ($existingAssignment && ($existingAssignment['status'] ?? '') === 'registered') {
                Transaction::rollback($this->db);
                return false;
            }

            $assignedRows = $this->db->query(
                "SELECT participant_count FROM activity_assignments
                 WHERE activity_id = {$activityId} AND status <> 'cancelled'
                 FOR UPDATE"
            );
            if (!$assignedRows) {
                Transaction::rollback($this->db);
                return false;
            }
            $assignedCount = 0;
            while ($assignedRow = $assignedRows->fetch_assoc()) {
                $assignedCount += (int) $assignedRow['participant_count'];
            }

            $capacity = (int) ($activity['capacity'] ?? 0);
            if ($capacity > 0 && $assignedCount + $headcount > $capacity) {
                Transaction::rollback($this->db);
                return false;
            }

            $points = 0;
            $organizationNameEscaped = $organizationName !== null
                ? "'" . $this->db->real_escape_string($organizationName) . "'"
                : 'NULL';

            $assignmentId = $existingAssignment ? (int) $existingAssignment['assignment_id'] : 0;
            if ($existingAssignment) {
                $saved = $this->db->query(
                    "UPDATE activity_assignments
                     SET status = 'registered', cancelled_at = NULL, cancellation_reason = NULL,
                         points = {$points}, assignment_type = '{$assignmentType}',
                         organization_name = {$organizationNameEscaped}, participant_count = {$headcount}
                     WHERE assignment_id = " . (int) $existingAssignment['assignment_id']
                );
            } else {
                $saved = $this->db->query(
                    "INSERT INTO activity_assignments
                        (activity_id, user_id, points, assignment_type, organization_name, participant_count)
                     VALUES ({$activityId}, {$userId}, {$points}, '{$assignmentType}', {$organizationNameEscaped}, {$headcount})"
                );
                $assignmentId = (int) $this->db->insert_id;
            }

            // 重新報名時以新名單為準
            if ($saved) {
                $saved = $this->db->query("DELETE FROM activity_participants WHERE assignment_id = {$assignmentId}");
                $stmt = $saved ? $this->db->prepare("INSERT INTO activity_participants (assignment_id, full_name, phone, id_number_encrypted) VALUES (?, ?, ?, ?)") : false;
                foreach ($employees as $employee) {
                    if (!$stmt) {
                        $saved = false;
                        break;
                    }
                    $name = trim((string) $employee['full_name']);
                    $phone = preg_replace('/[\s-]/', '', (string) $employee['phone']);
                    $encryptedId = IdNumberHelper::encrypt($employee['id_number']);
                    $stmt->bind_param('isss', $assignmentId, $name, $phone, $encryptedId);
                    if (!$stmt->execute()) {
                        $saved = false;
                        break;
                    }
                }
            }

            if (!$saved) {
                Transaction::rollback($this->db);
                return false;
            }
            if (!Transaction::commit($this->db)) {
                Transaction::rollback($this->db);
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            Transaction::rollback($this->db);
            throw $exception;
        }
    }

    public function cancelRegistration($activityId, $userId, $cancellationReason) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;
        $cancellationReason = trim((string) $cancellationReason);

        if ($cancellationReason === '') {
            return false;
        }

        $cancellationReasonEscaped = $this->db->real_escape_string($cancellationReason);
        $this->ensureParticipantsTable();

        $cancelled = $this->db->query(
            "UPDATE activity_assignments
             SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = '{$cancellationReasonEscaped}'
             WHERE activity_id = {$activityId} AND user_id = {$userId} AND status = 'registered' LIMIT 1"
        ) && $this->db->affected_rows === 1;
        if ($cancelled) {
            // 取消報名後不再需要員工的個人資料
            $this->db->query(
                "DELETE ap FROM activity_participants ap INNER JOIN activity_assignments aa ON aa.assignment_id = ap.assignment_id
                 WHERE aa.activity_id = {$activityId} AND aa.user_id = {$userId} AND aa.status = 'cancelled'"
            );
        }
        return $cancelled;
    }

    public function deleteActivity($activityId, $userId, $userRole = null) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;

        if (!$this->canManageActivity($activityId, $userId, $userRole)) {
            return false;
        }

        $this->ensureParticipantsTable();
        // 員工名冊含身分證字號，活動刪除時一併刪除
        $this->db->query("DELETE ap FROM activity_participants ap INNER JOIN activity_assignments aa ON aa.assignment_id = ap.assignment_id WHERE aa.activity_id = {$activityId}");
        $this->db->query("DELETE FROM activity_assignments WHERE activity_id = {$activityId}");
        $this->db->query("DELETE FROM activities WHERE activity_id = {$activityId}");

        return $this->db->affected_rows > 0;
    }

    public function getUserAssignments($userId) {
        $userId = (int) $userId;
        return $this->query(
            "SELECT aa.assignment_id, aa.assignment_type, aa.organization_name, aa.participant_count, aa.status AS assignment_status, aa.points, aa.cancelled_at, aa.cancellation_reason,
                   a.activity_id, a.title, a.status AS activity_status, a.start_at, a.created_by
             FROM activity_assignments aa
             JOIN activities a ON a.activity_id = aa.activity_id
             WHERE aa.user_id = {$userId}
             ORDER BY a.start_at DESC"
        );
    }

    public function getAssignmentForCertificate($assignmentId) {
        $assignmentId = (int) $assignmentId;
        $result = $this->db->query(
            "SELECT aa.*, a.title, a.activity_type, a.status AS activity_status, a.start_at, a.end_at
             FROM activity_assignments aa
             JOIN activities a ON a.activity_id = aa.activity_id
             WHERE aa.assignment_id = {$assignmentId}
             LIMIT 1"
        );
        return $result ? $result->fetch_assoc() : null;
    }
}
