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

    /** 企業報名的參加員工名冊（身分證字號加密保存，供活動保險使用）。DDL 會自動提交，須在交易外執行。 */
    public function ensureParticipantsTable() {
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
            "SELECT aa.assignment_id, aa.user_id, aa.assignment_type, aa.organization_name, aa.participant_count, aa.status AS assignment_status,
                    u.full_name, u.username, u.email, u.phone
             FROM activity_assignments aa
             JOIN users u ON u.user_id = aa.user_id
             WHERE aa.activity_id = {$activityId} AND aa.status <> 'cancelled'
             ORDER BY aa.created_at ASC"
        );
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

    /**
     * 報名活動。企業會員須附上員工名單（姓名、電話、身分證字號），報名人數即名單人數；
     * 一般會員以 1 人計。兩者可同時報名，不必等企業先認領，只受活動總名額限制。
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

        // 企業會員：人數就是員工名單的人數，且名單必須完整有效；個人會員一律以 1 人計。
        if ($isEnterpriseMember) {
            $employees = array_values($employees);
            if (self::validateEmployees($employees) !== null || ($headcount !== null && (int) $headcount !== count($employees))) {
                return false;
            }
            $headcount = count($employees);
        } else {
            $headcount = 1;
        }

        if ($assignmentType === 'company') {
            if (trim((string) $organizationName) === '') {
                return false;
            }
        }

        $this->db->begin_transaction();
        try {
            $activityResult = $this->db->query(
                "SELECT capacity FROM activities
                 WHERE activity_id = {$activityId} AND status IN ('planned', 'ongoing')
                 LIMIT 1 FOR UPDATE"
            );
            $activity = $activityResult ? $activityResult->fetch_assoc() : null;
            if (!$activity) {
                $this->db->rollback();
                return false;
            }

            $existingResult = $this->db->query(
                "SELECT assignment_id, status FROM activity_assignments
                 WHERE activity_id = {$activityId} AND user_id = {$userId}
                 ORDER BY assignment_id DESC LIMIT 1"
            );
            if (!$existingResult) {
                $this->db->rollback();
                return false;
            }
            $existingAssignment = $existingResult->fetch_assoc();
            if ($existingAssignment && ($existingAssignment['status'] ?? '') === 'registered') {
                $this->db->rollback();
                return false;
            }

            $assignedRows = $this->db->query(
                "SELECT participant_count FROM activity_assignments
                 WHERE activity_id = {$activityId} AND status <> 'cancelled'
                 FOR UPDATE"
            );
            if (!$assignedRows) {
                $this->db->rollback();
                return false;
            }
            $assignedCount = 0;
            while ($assignedRow = $assignedRows->fetch_assoc()) {
                $assignedCount += (int) $assignedRow['participant_count'];
            }

            $capacity = (int) ($activity['capacity'] ?? 0);
            if ($capacity > 0 && $assignedCount + $headcount > $capacity) {
                $this->db->rollback();
                return false;
            }

            $points = $assignmentType === 'individual' ? 5 : 0;
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
            if ($saved && $isEnterpriseMember) {
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
                $this->db->rollback();
                return false;
            }
            if (!$this->db->commit()) {
                $this->db->rollback();
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
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
