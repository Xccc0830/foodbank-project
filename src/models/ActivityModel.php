<?php
/**
 * 公益活動與認領模型
 */

require_once __DIR__ . '/BaseModel.php';

class ActivityModel extends BaseModel {
    protected $table = 'activities';

    public function getAllActivities() {
        return $this->query("SELECT a.*, COUNT(aa.assignment_id) AS participant_count FROM activities a LEFT JOIN activity_assignments aa ON aa.activity_id = a.activity_id AND aa.status <> 'cancelled' GROUP BY a.activity_id ORDER BY a.start_at ASC");
    }

    public function createActivity($data) {
        return $this->insert($data);
    }

    public function getActivityById($activityId) {
        $activityId = (int) $activityId;
        $result = $this->db->query("SELECT a.*, u.role AS creator_role FROM activities a LEFT JOIN users u ON u.user_id = a.created_by WHERE a.activity_id = {$activityId} LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
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

        if ($isCreator) {
            return true;
        }

        $foodbankRoles = ['admin', 'foodbank_staff'];
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

        $allowedKeys = ['title', 'activity_type', 'description', 'start_at', 'end_at', 'capacity'];
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

        $cancelledAt = $existing['cancelled_at'] ?? null;
        if ($cancelledAt === null) {
            return true;
        }

        $cooldownUntil = new DateTime($cancelledAt, new DateTimeZone('UTC'));
        $cooldownUntil->modify('+24 hours');
        return new DateTime('now', new DateTimeZone('UTC')) >= $cooldownUntil;
    }

    public function register($activityId, $userId, $assignmentType = 'individual', $organizationName = null) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;

        $existingAssignmentResult = $this->db->query(
            "SELECT assignment_id, status, cancelled_at FROM activity_assignments
             WHERE activity_id = {$activityId} AND user_id = {$userId}
             ORDER BY assignment_id DESC LIMIT 1"
        );

        if ($existingAssignmentResult && $existingAssignmentResult->num_rows > 0) {
            $existingAssignment = $existingAssignmentResult->fetch_assoc();
            $status = $existingAssignment['status'] ?? 'registered';
            $cancelledAt = $existingAssignment['cancelled_at'] ?? null;

            if ($status === 'registered') {
               return false;
            }

            if ($status === 'cancelled' && $cancelledAt !== null) {
               $cooldownUntil = new DateTime($cancelledAt, new DateTimeZone('UTC'));
               $cooldownUntil->modify('+24 hours');
               if (new DateTime('now', new DateTimeZone('UTC')) < $cooldownUntil) {
                   return false;
               }
            }

            $activityResult = $this->db->query("SELECT capacity FROM activities WHERE activity_id = {$activityId} AND status IN ('planned','ongoing') LIMIT 1");
            $activity = $activityResult ? $activityResult->fetch_assoc() : null;
            if (!$activity) {
               return false;
            }

            $participantResult = $this->db->query("SELECT COUNT(*) AS total FROM activity_assignments WHERE activity_id = {$activityId} AND status <> 'cancelled'");
            $participantCount = $participantResult ? (int) $participantResult->fetch_assoc()['total'] : 0;
            if ($activity['capacity'] !== null && $participantCount >= (int) $activity['capacity']) {
               return false;
            }

            $assignmentType = $assignmentType === 'company' ? 'company' : 'individual';
            $points = $assignmentType === 'individual' ? 5 : 0;
            $organizationNameEscaped = $organizationName !== null ? "'" . $this->db->real_escape_string($organizationName) . "'" : 'NULL';

            return (bool) $this->db->query(
               "UPDATE activity_assignments
                SET status = 'registered', cancelled_at = NULL, points = {$points}, assignment_type = '{$assignmentType}', organization_name = {$organizationNameEscaped}
                WHERE assignment_id = {$existingAssignment['assignment_id']}"
            );
        }

        if (!$this->canUserRegisterActivity($activityId, $userId)) {
            return false;
        }

        $assignmentType = $assignmentType === 'company' ? 'company' : 'individual';
        $points = $assignmentType === 'individual' ? 5 : 0;
        $organizationNameEscaped = $organizationName !== null ? "'" . $this->db->real_escape_string($organizationName) . "'" : 'NULL';

        $activityResult = $this->db->query("SELECT capacity FROM activities WHERE activity_id = {$activityId} AND status IN ('planned','ongoing') LIMIT 1");
        $activity = $activityResult ? $activityResult->fetch_assoc() : null;
        if (!$activity) {
            return false;
        }

        $participantResult = $this->db->query("SELECT COUNT(*) AS total FROM activity_assignments WHERE activity_id = {$activityId} AND status <> 'cancelled'");
        $participantCount = $participantResult ? (int) $participantResult->fetch_assoc()['total'] : 0;
        if ($activity['capacity'] !== null && $participantCount >= (int) $activity['capacity']) {
            return false;
        }

        try {
            return $this->db->query(
               "INSERT INTO activity_assignments (activity_id, user_id, points, assignment_type, organization_name)
                SELECT {$activityId}, {$userId}, {$points}, '{$assignmentType}', {$organizationNameEscaped}"
            );
        } catch (mysqli_sql_exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
               return false;
            }

            throw $e;
        }
    }

    public function cancelRegistration($activityId, $userId) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;

        return (bool) $this->db->query(
            "UPDATE activity_assignments
             SET status = 'cancelled', cancelled_at = NOW()
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
            "SELECT aa.assignment_id, aa.assignment_type, aa.organization_name, aa.status AS assignment_status, aa.points, aa.cancelled_at,
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
