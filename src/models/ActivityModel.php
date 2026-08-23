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

    public function register($activityId, $userId, $assignmentType = 'individual', $organizationName = null) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;
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

        return $this->db->query(
            "INSERT INTO activity_assignments (activity_id, user_id, points, assignment_type, organization_name)
             SELECT {$activityId}, {$userId}, {$points}, '{$assignmentType}', {$organizationNameEscaped}"
        );
    }

    public function getUserAssignments($userId) {
        $userId = (int) $userId;
        return $this->query(
            "SELECT aa.assignment_id, aa.assignment_type, aa.organization_name, aa.status AS assignment_status, aa.points,
                    a.activity_id, a.title, a.status AS activity_status, a.start_at
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
