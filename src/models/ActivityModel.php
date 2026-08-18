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

    public function register($activityId, $userId = 1) {
        $activityId = (int) $activityId;
        $userId = (int) $userId;
        return $this->db->query("INSERT INTO activity_assignments (activity_id, user_id, points) SELECT {$activityId}, {$userId}, 5 FROM activities WHERE activity_id = {$activityId} AND status IN ('planned','ongoing')");
    }
}
