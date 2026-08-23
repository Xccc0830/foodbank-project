<?php
/**
 * 公益活動發布與認領
 */

require_once BASE_PATH . '/src/models/ActivityModel.php';

$activityModel = new ActivityModel();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'create_activity') {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'activity_type' => $_POST['activity_type'] ?? 'other',
            'description' => trim($_POST['description'] ?? ''),
            'start_at' => $_POST['start_at'] ?? date('Y-m-d H:i:s'),
            'end_at' => $_POST['end_at'] ?: null,
            'capacity' => (int) ($_POST['capacity'] ?? 0),
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
                : ['type' => 'error', 'text' => '認領失敗，可能已經報名過。'];
        }
    }
}

$activities = $activityModel->getAllActivities();
$myAssignments = $activityModel->getUserAssignments((int) $currentUser['user_id']);
?>

<div class="view-header"><div><h1 class="view-title">活動認領</h1><p class="view-subtitle">發布公益活動，讓企業與志工參與在地行動</p></div></div>
<?php if ($message): ?><div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>

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
    <div class="card"><div class="card-header"><h2>參與方式</h2><p>志工可直接認領公開活動</p></div><div class="card-body"><ul class="feature-list"><li>企業可集體認領公益專案</li><li>個人與志工可線上報名</li><li>完成參與後可累積榮譽點數</li><li>管理者可追蹤活動人數與狀態</li></ul></div></div>
</div>

<div class="card mt-32"><div class="card-header"><h2>活動列表</h2></div><div class="card-body">
<?php if ($activities): ?><table class="data-table"><thead><tr><th>活動名稱</th><th>類型</th><th>時間</th><th>參與人數</th><th>狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($activities as $activity): ?><tr><td><strong><?php echo htmlspecialchars($activity['title']); ?></strong><br><small><?php echo htmlspecialchars($activity['description'] ?? ''); ?></small></td><td><?php echo htmlspecialchars($activity['activity_type']); ?></td><td><?php echo htmlspecialchars($activity['start_at']); ?></td><td><?php echo (int) $activity['participant_count']; ?><?php echo $activity['capacity'] ? ' / ' . (int) $activity['capacity'] : ''; ?></td><td><span class="status status-<?php echo htmlspecialchars($activity['status']); ?>"><?php echo htmlspecialchars($activity['status']); ?></span></td><td>
    <form method="post" class="delivery-action-form">
        <input type="hidden" name="action" value="register_activity">
        <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
        <select name="assignment_type" aria-label="認領身分"><option value="individual">個人／志工</option><option value="company">企業認領</option></select>
        <input type="text" name="organization_name" placeholder="企業／組織名稱（企業認領填寫）">
        <button class="btn btn-primary btn-sm" type="submit">認領活動</button>
    </form>
</td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-calendar"></i><p>目前沒有公開活動</p></div><?php endif; ?></div></div>

<div class="card mt-32"><div class="card-header"><h2>我的認領紀錄</h2><p>活動結束後可下載企業永續認證證書</p></div><div class="card-body">
<?php if ($myAssignments): ?><table class="data-table"><thead><tr><th>活動名稱</th><th>認領身分</th><th>企業／組織</th><th>活動狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($myAssignments as $assignment): ?><tr>
    <td><?php echo htmlspecialchars($assignment['title']); ?></td>
    <td><?php echo $assignment['assignment_type'] === 'company' ? '企業認領' : '個人／志工'; ?></td>
    <td><?php echo htmlspecialchars($assignment['organization_name'] ?? '-'); ?></td>
    <td><span class="status status-<?php echo htmlspecialchars($assignment['activity_status']); ?>"><?php echo htmlspecialchars($assignment['activity_status']); ?></span></td>
    <td><?php if ($assignment['activity_status'] === 'completed'): ?><a class="btn btn-secondary btn-sm" href="?page=activity_certificate&assignment_id=<?php echo (int) $assignment['assignment_id']; ?>" target="_blank">查看證書</a><?php else: ?>活動完成後開放<?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-clipboard-list"></i><p>尚未認領任何活動</p></div><?php endif; ?>
</div></div>
