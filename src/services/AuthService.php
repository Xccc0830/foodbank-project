<?php
/**
 * 授權服務 (Auth Service)
 * 包裝 PermissionModel，提供 can()/canAny() 供 Route Guard 與選單渲染使用。
 *
 * 用法：
 *   $authService = AuthService::forCurrentUser($currentUser);
 *   if ($authService->can('rider.accept_task')) { ... }
 */

require_once __DIR__ . '/../models/PermissionModel.php';

class AuthService {
    private $permissions;
    private $userId;

    public function __construct(array $permissions, $userId = 0) {
        $this->permissions = array_combine($permissions, $permissions);
        $this->userId = (int) $userId;
    }

    /**
     * 每次請求重新載入權限，確保核准或撤銷的權限即時生效。
     */
    public static function forCurrentUser(array $currentUser) {
        $userId = (int) ($currentUser['user_id'] ?? 0);
        $role = $currentUser['role'] ?? 'member';
        $memberType = $currentUser['member_type'] ?? null;

        $permissions = self::resolvePermissions($userId, $role, $memberType);
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['permissions'] = $permissions;
        }

        return new self($permissions, $userId);
    }

    /**
     * 重新計算並更新 session 快取（於權限變更後呼叫，例如使用者加選/取消 Rider 任務）。
     */
    public static function refreshCurrentUser(array $currentUser) {
        $userId = (int) ($currentUser['user_id'] ?? 0);
        $role = $currentUser['role'] ?? 'member';
        $memberType = $currentUser['member_type'] ?? null;
        $permissions = self::resolvePermissions($userId, $role, $memberType);

        if (isset($_SESSION['user'])) {
            $_SESSION['user']['permissions'] = $permissions;
        }

        return new self($permissions, $userId);
    }

    private static function resolvePermissions($userId, $role, $memberType) {
        $permissionModel = new PermissionModel();
        return $permissionModel->getEffectivePermissions($userId, $role, $memberType);
    }

    public function can($permissionKey) {
        return isset($this->permissions[$permissionKey]);
    }

    public function canAny(array $permissionKeys) {
        foreach ($permissionKeys as $permissionKey) {
            if ($this->can($permissionKey)) {
                return true;
            }
        }
        return false;
    }

    public function canAll(array $permissionKeys) {
        foreach ($permissionKeys as $permissionKey) {
            if (!$this->can($permissionKey)) {
                return false;
            }
        }
        return true;
    }

    public function all() {
        return array_values($this->permissions);
    }
}
