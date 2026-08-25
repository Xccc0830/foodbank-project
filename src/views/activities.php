<?php
/**
 * 公益活動發布與認領
 */

require_once BASE_PATH . '/src/models/ActivityModel.php';

$activityModel = new ActivityModel();
$currentRole = $currentUser['role'] ?? 'foodbank_staff';
$canCreateActivity = in_array($currentRole, ['admin', 'foodbank_staff', 'donor'], true);
$message = null;
$editingActivity = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'create_activity') {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'activity_type' => $_POST['activity_type'] ?? 'other',
            'description' => trim($_POST['description'] ?? ''),
            'start_at' => $_POST['start_at'] ?? date('Y-m-d H:i:s'),
            'end_at' => $_POST['end_at'] ?? null,
            'capacity' => (int) ($_POST['capacity'] ?? 0),
            'created_by' => (int) $currentUser['user_id'],
            'status' => 'planned',
        ];
        $message = $data['title'] !== '' && $activityModel->createActivity($data)
            ? ['type' => 'success', 'text' => '公益活動已發布。']
            : ['type' => 'error', 'text' => '請填寫活動名稱，或活動發布失敗。'];
    }

    if (($_POST['action'] ?? '') === 'register_activity') {
        $assignmentType = ($_POST['assignment_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        $organizationName = $assignmentType === 'company' ? trim($_POST['organization_name'] ?? '') : null;

        if ($assignmentType === 'company' && $organizationName === '') {
            $message = ['type' => 'error', 'text' => '企業認領請填寫企業／組織名稱。'];
        } else {
            $message = $activityModel->register((int) $_POST['activity_id'], (int) $currentUser['user_id'], $assignmentType, $organizationName)
                ? ['type' => 'success', 'text' => $assignmentType === 'company' ? '企業認領已送出，活動結束後可下載永續認證證書。' : '已完成活動認領，預計可獲得 5 點榮譽點數。']
                : ['type' => 'error', 'text' => '認領失敗，可能已經報名過，或剛取消過此活動，需等待 24 小時後才可再認領。'];
        }
    }

    if (($_POST['action'] ?? '') === 'cancel_activity_registration') {
        $cancelled = $activityModel->cancelRegistration((int) $_POST['activity_id'], (int) $currentUser['user_id']);
        $message = $cancelled
            ? ['type' => 'success', 'text' => '已取消此活動認領，24 小時內將無法再次認領該活動。']
            : ['type' => 'error', 'text' => '取消失敗，或該活動並非您的認領記錄。'];
    }

    if (($_POST['action'] ?? '') === 'delete_activity') {
        $deleted = $activityModel->deleteActivity((int) $_POST['activity_id'], (int) $currentUser['user_id'], $currentRole);
        $message = $deleted
            ? ['type' => 'success', 'text' => '已刪除活動。']
            : ['type' => 'error', 'text' => '刪除失敗，只有活動發起人或食物銀行/管理者才可刪除。'];
    }

    if (($_POST['action'] ?? '') === 'load_edit_activity') {
        $editingActivity = $activityModel->getActivityById((int) $_POST['activity_id']);
        if (!$editingActivity || !$activityModel->canManageActivity((int) $_POST['activity_id'], (int) $currentUser['user_id'], $currentRole)) {
            $message = ['type' => 'error', 'text' => '您無權編輯此活動。'];
            $editingActivity = null;
        }
    }

    if (($_POST['action'] ?? '') === 'update_activity') {
        $activityId = (int) $_POST['activity_id'];
        $updated = $activityModel->updateActivity($activityId, (int) $currentUser['user_id'], $currentRole, [
            'title' => trim($_POST['title'] ?? ''),
            'activity_type' => $_POST['activity_type'] ?? 'other',
            'description' => trim($_POST['description'] ?? ''),
            'start_at' => $_POST['start_at'] ?? date('Y-m-d H:i:s'),
            'end_at' => $_POST['end_at'] ?? null,
            'capacity' => (int) ($_POST['capacity'] ?? 0),
        ]);

        $message = $updated
            ? ['type' => 'success', 'text' => '活動已更新。']
            : ['type' => 'error', 'text' => '更新失敗，只有活動發起人或食物銀行/管理者才可編輯。'];
    }
}

$activities = $activityModel->getAllActivities();
foreach ($activities as $index => $activity) {
    $activities[$index]['can_register'] = $activityModel->canUserRegisterActivity((int) $activity['activity_id'], (int) $currentUser['user_id']);
    $activities[$index]['can_manage'] = $activityModel->canManageActivity((int) $activity['activity_id'], (int) $currentUser['user_id'], $currentRole);
    $activities[$index]['is_creator'] = ((int) ($activity['created_by'] ?? 0)) === (int) $currentUser['user_id'];
}
$myAssignments = $activityModel->getUserAssignments((int) $currentUser['user_id']);
?>

<div class="view-header"><div><h1 class="view-title">活動認領</h1><p class="view-subtitle">發布公益活動，讓企業與志工參與在地行動</p></div></div>
<?php if ($message): ?><div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>

<?php if ($canCreateActivity): ?>
<div class="grid-2">
    <div class="card"><div class="card-header"><h2>發布活動</h2><p>支援募資、說明會、淨灘與公益宣導</p></div><div class="card-body">
        <form method="post"><input type="hidden" name="action" value="create_activity">
            <div class="form-group"><label>活動名稱*</label><input name="title" required></div>
            <div class="form-group"><label>活動類型</label><select name="activity_type"><option value="donation_drive">物資募集</option><option value="briefing">說明會</option><option value="cleanup">環境行動</option><option value="promotion">公益宣導</option><option value="other">其他</option></select></div>
            <div class="grid-2"><div class="form-group"><label>開始時間*</label><input type="datetime-local" name="start_at" required></div><div class="form-group"><label>名額</label><input type="number" name="capacity" min="0"></div></div>
            <div class="form-group"><label>活動說明</label><textarea name="description"></textarea></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-calendar-plus"></i> 發布活動</button>
        </form>
    </div></div>
    <div class="card participation-card">
        <div class="card-header">
            <h2>參與方式</h2>
            <p>志工可直接認領公開活動</p>
        </div>
        <div class="card-body">
            <ul class="feature-list">
                <li><span class="feature-icon">●</span><span>企業可集體認領公益專案</span></li>
                <li><span class="feature-icon">●</span><span>個人與志工可線上報名</span></li>
                <li><span class="feature-icon">●</span><span>完成參與後可累積榮譽點數</span></li>
                <li><span class="feature-icon">●</span><span>管理者可追蹤活動人數與狀態</span></li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($editingActivity): ?>
<div class="card mt-32">
    <div class="card-header"><h2>編輯活動</h2></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="update_activity">
            <input type="hidden" name="activity_id" value="<?php echo (int) $editingActivity['activity_id']; ?>">
            <div class="grid-2">
                <div class="form-group"><label>活動名稱</label><input name="title" value="<?php echo htmlspecialchars($editingActivity['title']); ?>" required></div>
                <div class="form-group"><label>活動類型</label><select name="activity_type">
                    <option value="donation_drive" <?php echo $editingActivity['activity_type'] === 'donation_drive' ? 'selected' : ''; ?>>物資募集</option>
                    <option value="briefing" <?php echo $editingActivity['activity_type'] === 'briefing' ? 'selected' : ''; ?>>說明會</option>
                    <option value="cleanup" <?php echo $editingActivity['activity_type'] === 'cleanup' ? 'selected' : ''; ?>>環境行動</option>
                    <option value="promotion" <?php echo $editingActivity['activity_type'] === 'promotion' ? 'selected' : ''; ?>>公益宣導</option>
                    <option value="other" <?php echo $editingActivity['activity_type'] === 'other' ? 'selected' : ''; ?>>其他</option>
                </select></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>開始時間</label><input type="datetime-local" name="start_at" value="<?php echo htmlspecialchars(str_replace(' ', 'T', $editingActivity['start_at'])); ?>" required></div>
                <div class="form-group"><label>名額</label><input type="number" name="capacity" min="0" value="<?php echo (int) ($editingActivity['capacity'] ?? 0); ?>"></div>
            </div>
            <div class="form-group"><label>活動說明</label><textarea name="description"><?php echo htmlspecialchars($editingActivity['description'] ?? ''); ?></textarea></div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">儲存變更</button>
                <a class="btn btn-secondary" href="?page=activities">取消</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mt-32"><div class="card-header"><h2>活動列表</h2></div><div class="card-body">
<?php if ($activities): ?><table class="data-table"><thead><tr><th>活動名稱</th><th>類型</th><th>時間</th><th>參與人數</th><th>狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($activities as $activity): ?><tr><td><strong><?php echo htmlspecialchars($activity['title']); ?></strong><br><small><?php echo htmlspecialchars($activity['description'] ?? ''); ?></small></td><td><?php echo htmlspecialchars($activity['activity_type']); ?></td><td><?php echo htmlspecialchars($activity['start_at']); ?></td><td><?php echo (int) $activity['participant_count']; ?><?php echo $activity['capacity'] ? ' / ' . (int) $activity['capacity'] : ''; ?></td><td><span class="status status-<?php echo htmlspecialchars($activity['status']); ?>"><?php echo htmlspecialchars($activity['status']); ?></span></td><td>
    <?php if ($activity['can_manage']): ?>
        <div class="inline-action-group">
            <form method="post" class="delivery-action-form">
                <input type="hidden" name="action" value="load_edit_activity">
                <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
                <button class="btn btn-secondary btn-sm" type="submit">編輯活動</button>
            </form>
            <form method="post" class="delivery-action-form" onsubmit="return confirm('確定要刪除這個活動嗎？');">
                <input type="hidden" name="action" value="delete_activity">
                <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
                <button class="btn btn-danger btn-sm" type="submit">刪除活動</button>
            </form>
        </div>
    <?php elseif ($activity['can_register']): ?>
        <form method="post" class="delivery-action-form">
            <input type="hidden" name="action" value="register_activity">
            <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
            <select name="assignment_type" aria-label="認領身分"><option value="individual">個人／志工</option><option value="company">企業認領</option></select>
            <input type="text" name="organization_name" placeholder="企業／組織名稱（企業認領填寫）">
            <button class="btn btn-primary btn-sm" type="submit">認領活動</button>
        </form>
    <?php else: ?>
        <span class="status status-warning">24 小時內不可再認領</span>
    <?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-calendar"></i><p>目前沒有公開活動</p></div><?php endif; ?></div></div>

<div class="card mt-32"><div class="card-header"><h2>我的認領紀錄</h2><p>活動結束後可下載企業永續認證證書</p></div><div class="card-body">
<?php if ($myAssignments): ?><table class="data-table"><thead><tr><th>活動名稱</th><th>認領身分</th><th>企業／組織</th><th>活動狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($myAssignments as $assignment): ?><tr>
    <td><?php echo htmlspecialchars($assignment['title']); ?></td>
    <td><?php echo $assignment['assignment_type'] === 'company' ? '企業認領' : '個人／志工'; ?></td>
    <td><?php echo htmlspecialchars($assignment['organization_name'] ?? '-'); ?></td>
    <td><span class="status status-<?php echo htmlspecialchars($assignment['activity_status']); ?>"><?php echo htmlspecialchars($assignment['activity_status']); ?></span></td>
    <td>
        <?php if (($assignment['assignment_status'] ?? 'registered') === 'cancelled'): ?>
            <span class="status status-warning">已取消（24 小時內不可再認領）</span>
        <?php elseif ($assignment['activity_status'] === 'completed'): ?>
            <a class="btn btn-secondary btn-sm" href="?page=activity_certificate&assignment_id=<?php echo (int) $assignment['assignment_id']; ?>" target="_blank">查看證書</a>
        <?php else: ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="cancel_activity_registration">
                <input type="hidden" name="activity_id" value="<?php echo (int) $assignment['activity_id']; ?>">
                <button class="btn btn-secondary btn-sm" type="submit">取消認領</button>
            </form>
        <?php endif; ?>
    </td>
</tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-clipboard-list"></i><p>尚未認領任何活動</p></div><?php endif; ?>
</div></div>
