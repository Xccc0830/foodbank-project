<?php
/**
 * 公益活動與認領模型
 */

require_once __DIR__ . '/BaseModel.php';

class ActivityModel extends BaseModel {
    protected $table = 'activities';

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
        $creatorRole = $activity['creator_role'] ?? 'foodbank_staff';
        $role = $userRole ?? 'foodbank_staff';

        if ($role === 'member') {
            return false;
        }

        if ($isCreator) {
            return true;
        }

        $foodbankRoles = ['foodbank_staff'];
        if (in_array($creatorRole, $foodbankRoles, true)) {
            return in_array($role, $foodbankRoles, true);
        }

        return false;
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

    public function register($activityId, $userId, $assignmentType = 'individual', $organizationName = null, $headcount = null) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;
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

        // 僅企業會員可自填活動參與人數，個人會員一律以 1 人計。
        $headcount = $isEnterpriseMember ? (int) $headcount : 1;
        if ($headcount < 1) {
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
                "SELECT participant_count, assignment_type FROM activity_assignments
                 WHERE activity_id = {$activityId} AND status <> 'cancelled'
                 FOR UPDATE"
            );
            if (!$assignedRows) {
                $this->db->rollback();
                return false;
            }
            $assignedCount = 0;
            $hasCompanyClaim = false;
            while ($assignedRow = $assignedRows->fetch_assoc()) {
                $assignedCount += (int) $assignedRow['participant_count'];
                $hasCompanyClaim = $hasCompanyClaim || ($assignedRow['assignment_type'] ?? '') === 'company';
            }

            $capacity = (int) ($activity['capacity'] ?? 0);
            if (!$isEnterpriseMember && $capacity > 0 && !$hasCompanyClaim) {
                $this->db->rollback();
                return false;
            }
            if ($capacity > 0 && $assignedCount + $headcount > $capacity) {
                $this->db->rollback();
                return false;
            }

            $points = $assignmentType === 'individual' ? 5 : 0;
            $organizationNameEscaped = $organizationName !== null
                ? "'" . $this->db->real_escape_string($organizationName) . "'"
                : 'NULL';

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

        return (bool) $this->db->query(
            "UPDATE activity_assignments
             SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = '{$cancellationReasonEscaped}'
             WHERE activity_id = {$activityId} AND user_id = {$userId} AND status = 'registered' LIMIT 1"
        );
    }

    public function deleteActivity($activityId, $userId, $userRole = null) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;

        if (!$this->canManageActivity($activityId, $userId, $userRole)) {
            return false;
        }

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
