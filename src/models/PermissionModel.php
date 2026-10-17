<?php
/**
 * 權限模型 (Permission Model)
 * 計算「角色 + 會員類型」預設權限，並疊加使用者個人覆寫 (opt-in/opt-out)，
 * 讓頁面/功能改以「能力 (capability)」渲染，而非寫死的 role 判斷。
 */

require_once 'BaseModel.php';

class PermissionModel extends BaseModel {
    protected $table = 'permissions';

    /**
     * 計算指定使用者的最終有效權限清單。
     * 最終權限 = 角色預設矩陣 ∪ 使用者 override（granted=1 的加，granted=0 的減）。
     *
     * @return string[] 權限鍵值陣列
     */
    public function getEffectivePermissions($userId, $role, $memberType = null) {
        $userId = (int) $userId;
        $role = $this->db->real_escape_string((string) $role);
        // foodbank_staff 角色不區分 member_type；資料庫以空字串 '' 作為「不限」的 sentinel 值
        // （member_type 是複合主鍵欄位之一，MySQL 不允許 PK 欄位為 NULL，因此不能用 NULL 表示）。
        $normalizedMemberType = ($role !== 'foodbank_staff' && $memberType !== null && $memberType !== '')
            ? (string) $memberType
            : '';
        $memberTypeCondition = "member_type = '" . $this->db->real_escape_string($normalizedMemberType) . "'";

        $defaults = $this->query(
            "SELECT permission_key FROM role_permission_defaults
             WHERE role = '{$role}' AND {$memberTypeCondition}"
        );
        $permissions = array_column($defaults, 'permission_key');
        $permissions = array_combine($permissions, $permissions);

        if ($userId > 0) {
            $overrides = $this->query(
                "SELECT permission_key, granted FROM user_permission_overrides WHERE user_id = {$userId}"
            );
            foreach ($overrides as $override) {
                $key = $override['permission_key'];
                if ((int) $override['granted'] === 1) {
                    $permissions[$key] = $key;
                } else {
                    unset($permissions[$key]);
                }
            }
        }

        return array_values($permissions);
    }

    /**
     * 授予使用者個人權限（例如一般會員自行加選 Rider 配送任務）。
     */
    public function grantOverride($userId, $permissionKey) {
        return $this->setOverride($userId, $permissionKey, 1);
    }

    /**
     * 撤銷使用者個人權限。
     */
    public function revokeOverride($userId, $permissionKey) {
        return $this->setOverride($userId, $permissionKey, 0);
    }

    /**
     * 清除使用者的個人覆寫，恢復為角色預設值。
     */
    public function clearOverride($userId, $permissionKey) {
        $userId = (int) $userId;
        $permissionKey = $this->db->real_escape_string((string) $permissionKey);
        return (bool) $this->db->query(
            "DELETE FROM user_permission_overrides WHERE user_id = {$userId} AND permission_key = '{$permissionKey}'"
        );
    }

    private function setOverride($userId, $permissionKey, $granted) {
        $userId = (int) $userId;
        $permissionKeyEscaped = $this->db->real_escape_string((string) $permissionKey);
        $granted = (int) $granted;

        $exists = $this->db->query("SHOW TABLES LIKE 'permissions'");
        if (!$exists || $exists->num_rows === 0) {
            return false;
        }

        return (bool) $this->db->query(
            "INSERT INTO user_permission_overrides (user_id, permission_key, granted)
             VALUES ({$userId}, '{$permissionKeyEscaped}', {$granted})
             ON DUPLICATE KEY UPDATE granted = {$granted}, updated_at = NOW()"
        );
    }

    /**
     * 取得所有權限目錄（供設定頁面渲染用）。
     */
    public function getAllPermissions() {
        return $this->query("SELECT permission_key, label, category FROM permissions ORDER BY category, permission_key");
    }
}
