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
        $message = $activityModel->register((int) $_POST['activity_id'])
            ? ['type' => 'success', 'text' => '已完成活動認領，預計可獲得 5 點榮譽點數。']
            : ['type' => 'error', 'text' => '認領失敗，可能已經報名過。'];
    }
}

$activities = $activityModel->getAllActivities();
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
<?php foreach ($activities as $activity): ?><tr><td><strong><?php echo htmlspecialchars($activity['title']); ?></strong><br><small><?php echo htmlspecialchars($activity['description'] ?? ''); ?></small></td><td><?php echo htmlspecialchars($activity['activity_type']); ?></td><td><?php echo htmlspecialchars($activity['start_at']); ?></td><td><?php echo (int) $activity['participant_count']; ?><?php echo $activity['capacity'] ? ' / ' . (int) $activity['capacity'] : ''; ?></td><td><span class="status status-<?php echo htmlspecialchars($activity['status']); ?>"><?php echo htmlspecialchars($activity['status']); ?></span></td><td><form method="post"><input type="hidden" name="action" value="register_activity"><input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>"><button class="btn btn-primary btn-sm">認領活動</button></form></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-calendar"></i><p>目前沒有公開活動</p></div><?php endif; ?></div></div>
