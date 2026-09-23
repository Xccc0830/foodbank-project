<?php
/**
 * 公益活動發布與認領
 */

require_once BASE_PATH . '/src/models/ActivityModel.php';
require_once BASE_PATH . '/src/models/NotificationModel.php';

$activityModel = new ActivityModel();
$notificationModel = new NotificationModel();
$currentRole = $currentUser['role'] ?? 'foodbank_staff';
$canCreateActivity = in_array($currentRole, ['admin', 'foodbank_staff'], true);
$canRegisterActivities = in_array($currentRole, ['volunteer', 'donor'], true);
$message = null;
$editingActivity = null;
$participantLists = [];

$connection = $db->getConnection();
$userIdEscaped = $connection->real_escape_string((string) $currentUser['user_id']);
$userResult = $connection->query("SELECT is_enterprise_verified FROM users WHERE user_id = {$userIdEscaped} LIMIT 1");
$userInfo = $userResult ? $userResult->fetch_assoc() : ['is_enterprise_verified' => 0];
$isEnterpriseVerified = (int) ($userInfo['is_enterprise_verified'] ?? 0) === 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'create_activity') {
        if (!$canCreateActivity) {
            $message = ['type' => 'error', 'text' => '只有食物銀行人員可以發布公益活動。'];
        } else {
        $activityType = $_POST['activity_type'] ?? 'other';
        $activityTypeDetail = trim($_POST['activity_type_detail'] ?? '');
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'activity_type' => $activityType,
            'activity_type_detail' => $activityType === 'other' ? $activityTypeDetail : null,
            'description' => trim($_POST['description'] ?? ''),
            'start_at' => $_POST['start_at'] ?? date('Y-m-d H:i:s'),
            'end_at' => $_POST['end_at'] ?? null,
            'capacity' => (int) ($_POST['capacity'] ?? 0),
            'created_by' => (int) $currentUser['user_id'],
            'status' => 'planned',
        ];
        $message = $data['title'] !== '' && ($activityType !== 'other' || $activityTypeDetail !== '') && $activityModel->createActivity($data)
            ? ['type' => 'success', 'text' => '公益活動已發布。']
            : ['type' => 'error', 'text' => $activityType === 'other' && $activityTypeDetail === '' ? '請填寫其他活動類型。' : '請填寫活動名稱，或活動發布失敗。'];
        }
    }

    if (($_POST['action'] ?? '') === 'register_activity') {
        $assignmentType = ($_POST['assignment_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        $organizationName = $assignmentType === 'company' ? trim($_POST['organization_name'] ?? '') : null;

        if ($assignmentType === 'company' && $currentRole !== 'donor' && $organizationName === '') {
            $message = ['type' => 'error', 'text' => '企業認領請填寫企業／組織名稱。'];
        } else {
            $message = $activityModel->register((int) $_POST['activity_id'], (int) $currentUser['user_id'], $assignmentType, $organizationName)
                ? ['type' => 'success', 'text' => $assignmentType === 'company' ? '企業認領已送出，活動結束後可下載永續認證證書。' : '已完成活動認領，預計可獲得 5 點榮譽點數。']
                : ['type' => 'error', 'text' => '認領失敗，可能已經認領過此活動，或活動名額已滿。'];
        }
    }

    if (($_POST['action'] ?? '') === 'cancel_activity_registration') {
        $cancellationReason = trim($_POST['cancellation_reason'] ?? '');
        if ($cancellationReason === '') {
            $message = ['type' => 'error', 'text' => '請填寫取消認領原因。'];
        } else {
            $cancelled = $activityModel->cancelRegistration((int) $_POST['activity_id'], (int) $currentUser['user_id'], $cancellationReason);
            $cancelledActivity = $activityModel->getActivityById((int) $_POST['activity_id']);
            $message = $cancelled
                ? ['type' => 'success', 'text' => '已取消此活動認領，現在即可再次認領。']
                : ['type' => 'error', 'text' => '取消失敗，或該活動並非您的認領記錄。'];

            if ($cancelled && $cancelledActivity) {
                $officialUsers = $connection->query("SELECT user_id FROM users WHERE role IN ('admin', 'foodbank_staff') AND status = 'active'");
                $volunteerName = trim((string) ($currentUser['full_name'] ?? $currentUser['username'] ?? '志工'));
                $notificationTitle = '志工取消活動認領';
                $notificationMessage = sprintf(
                    '%s 取消認領活動「%s」，原因：%s',
                    $volunteerName,
                    $cancelledActivity['title'],
                    $cancellationReason
                );
                if ($officialUsers) {
                    while ($officialUser = $officialUsers->fetch_assoc()) {
                        $notificationModel->notify((int) $officialUser['user_id'], $notificationTitle, $notificationMessage, 'warning');
                    }
                }
            }
        }
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
        $activityType = $_POST['activity_type'] ?? 'other';
        $activityTypeDetail = trim($_POST['activity_type_detail'] ?? '');
        $updated = $activityModel->updateActivity($activityId, (int) $currentUser['user_id'], $currentRole, [
            'title' => trim($_POST['title'] ?? ''),
            'activity_type' => $activityType,
            'activity_type_detail' => $activityType === 'other' ? $activityTypeDetail : null,
            'description' => trim($_POST['description'] ?? ''),
            'start_at' => $_POST['start_at'] ?? date('Y-m-d H:i:s'),
            'end_at' => $_POST['end_at'] ?? null,
            'capacity' => (int) ($_POST['capacity'] ?? 0),
        ]);

        $message = $activityType === 'other' && $activityTypeDetail === ''
            ? ['type' => 'error', 'text' => '請填寫其他活動類型。']
            : ($updated
            ? ['type' => 'success', 'text' => '活動已更新。']
            : ['type' => 'error', 'text' => '更新失敗，只有活動發起人或食物銀行/管理者才可編輯。']);
    }
}

$activities = $activityModel->getAllActivities();
foreach ($activities as $index => $activity) {
    $activities[$index]['can_register'] = $canRegisterActivities && $activityModel->canUserRegisterActivity((int) $activity['activity_id'], (int) $currentUser['user_id']);
    $activities[$index]['can_manage'] = $activityModel->canManageActivity((int) $activity['activity_id'], (int) $currentUser['user_id'], $currentRole);
    $activities[$index]['can_view_participants'] = $activities[$index]['can_manage'] || in_array($currentRole, ['admin', 'foodbank_staff'], true);
    if ($activities[$index]['can_view_participants']) {
        $participantLists[(int) $activity['activity_id']] = $activityModel->getParticipants((int) $activity['activity_id']);
    }
    $activities[$index]['is_creator'] = ((int) ($activity['created_by'] ?? 0)) === (int) $currentUser['user_id'];
}
$myAssignments = $activityModel->getUserAssignments((int) $currentUser['user_id']);
$activityTypeLabels = [
    'donation_drive' => '物資募集',
    'briefing' => '說明會',
    'cleanup' => '環境行動',
    'promotion' => '公益宣導',
    'other' => '其他',
];
$getActivityTypeLabel = static function ($activity) use ($activityTypeLabels) {
    if (($activity['activity_type'] ?? '') === 'other' && trim((string) ($activity['activity_type_detail'] ?? '')) !== '') {
        return $activity['activity_type_detail'];
    }

    return $activityTypeLabels[$activity['activity_type']] ?? $activity['activity_type'];
};
$activityStatusLabels = [
    'planned' => '已規劃',
    'ongoing' => '進行中',
    'completed' => '已完成',
    'cancelled' => '已取消',
];
?>

<div class="view-header"><div><h1 class="view-title"><?php echo in_array($currentRole, ['volunteer', 'donor'], true) ? '活動認領' : '活動發布'; ?></h1><p class="view-subtitle"><?php echo $currentRole === 'volunteer' ? '認領公益活動，參與在地行動' : ($currentRole === 'donor' ? '以愛心商家身分認領公益活動，參與在地行動' : '發布公益活動，讓企業與志工參與在地行動'); ?></p></div></div>
<?php if ($message): ?><div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>

<?php if ($canCreateActivity): ?>
<div class="activity-publish-layout">
    <div class="card"><div class="card-header"><h2>發布活動</h2><p>支援募資、說明會、淨灘與公益宣導</p></div><div class="card-body">
        <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="create_activity">
            <div class="form-group"><label>活動名稱*</label><input name="title" required></div>
            <div class="form-group"><label>活動類型</label><select name="activity_type" class="activity-type-select"><option value="donation_drive">物資募集</option><option value="briefing">說明會</option><option value="cleanup">環境行動</option><option value="promotion">公益宣導</option><option value="other">其他</option></select></div>
            <div class="form-group other-activity-type-field" hidden><label>其他活動類型*</label><input name="activity_type_detail" maxlength="100" placeholder="請輸入活動類型"></div>
            <div class="grid-2 activity-form-grid"><div class="form-group"><label>開始時間*</label><input type="datetime-local" name="start_at" required></div><div class="form-group"><label>名額</label><input type="number" name="capacity" min="0"></div></div>
            <div class="form-group"><label>活動說明</label><textarea name="description"></textarea></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-calendar-plus"></i> 發布活動</button>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if ($editingActivity): ?>
<div class="card mt-32">
    <div class="card-header"><h2>編輯活動</h2></div>
    <div class="card-body">
        <form method="post"><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_activity">
            <input type="hidden" name="activity_id" value="<?php echo (int) $editingActivity['activity_id']; ?>">
            <div class="grid-2">
                <div class="form-group"><label>活動名稱</label><input name="title" value="<?php echo htmlspecialchars($editingActivity['title']); ?>" required></div>
                <div class="form-group"><label>活動類型</label><select name="activity_type" class="activity-type-select">
                    <option value="donation_drive" <?php echo $editingActivity['activity_type'] === 'donation_drive' ? 'selected' : ''; ?>>物資募集</option>
                    <option value="briefing" <?php echo $editingActivity['activity_type'] === 'briefing' ? 'selected' : ''; ?>>說明會</option>
                    <option value="cleanup" <?php echo $editingActivity['activity_type'] === 'cleanup' ? 'selected' : ''; ?>>環境行動</option>
                    <option value="promotion" <?php echo $editingActivity['activity_type'] === 'promotion' ? 'selected' : ''; ?>>公益宣導</option>
                    <option value="other" <?php echo $editingActivity['activity_type'] === 'other' ? 'selected' : ''; ?>>其他</option>
                </select></div>
                <div class="form-group other-activity-type-field" hidden><label>其他活動類型*</label><input name="activity_type_detail" maxlength="100" value="<?php echo htmlspecialchars($editingActivity['activity_type_detail'] ?? ''); ?>" placeholder="請輸入活動類型"></div>
            </div>
            <div class="grid-2 activity-form-grid">
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

<div class="card mt-32"><div class="card-header"><h2>活動列表</h2></div><div class="card-body activities-table-body">
<?php if ($activities): ?><table class="data-table activities-table"><thead><tr><th>活動名稱</th><th>類型</th><th>時間</th><th>參與人數</th><th>狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($activities as $activity): ?><tr><td><strong><?php echo htmlspecialchars($activity['title']); ?></strong><?php if (!empty($activity['description']) && trim($activity['description']) !== trim($activity['title'])): ?><br><small class="<?php echo mb_strlen(trim($activity['description']), 'UTF-8') > 80 ? 'activity-description' : 'activity-description-short'; ?>"><?php echo htmlspecialchars($activity['description']); ?></small><?php if (mb_strlen(trim($activity['description']), 'UTF-8') > 80): ?><button type="button" class="activity-description-button" data-activity-title="<?php echo htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8'); ?>" data-activity-description="<?php echo htmlspecialchars($activity['description'], ENT_QUOTES, 'UTF-8'); ?>">查看完整說明</button><?php endif; ?><?php endif; ?></td><td><?php echo htmlspecialchars($getActivityTypeLabel($activity)); ?></td><td><?php echo htmlspecialchars($activity['start_at']); ?></td><td><?php echo (int) $activity['participant_count']; ?><?php echo $activity['capacity'] ? ' / ' . (int) $activity['capacity'] : ''; ?></td><td><span class="status status-<?php echo htmlspecialchars($activity['status']); ?>"><?php echo htmlspecialchars($activityStatusLabels[$activity['status']] ?? $activity['status']); ?></span></td><td>
    <?php if ($activity['can_manage']): ?>
        <div class="inline-action-group">
            <form method="post" class="delivery-action-form"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="load_edit_activity">
                <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
                <button class="btn btn-secondary btn-sm" type="submit">編輯活動</button>
            </form>
            <button class="btn btn-secondary btn-sm view-participants-button" type="button" data-activity-id="<?php echo (int) $activity['activity_id']; ?>" data-activity-title="<?php echo htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8'); ?>">參與者</button>
            <form method="post" class="delivery-action-form" onsubmit="return confirm('確定要刪除這個活動嗎？');"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_activity">
                <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
                <button class="btn btn-danger btn-sm" type="submit">刪除活動</button>
            </form>
        </div>
    <?php elseif ($activity['can_view_participants']): ?>
        <button class="btn btn-secondary btn-sm view-participants-button" type="button" data-activity-id="<?php echo (int) $activity['activity_id']; ?>" data-activity-title="<?php echo htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8'); ?>">參與者</button>
    <?php elseif ($activity['can_register']): ?>
        <form method="post" class="delivery-action-form"><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="register_activity">
            <input type="hidden" name="activity_id" value="<?php echo (int) $activity['activity_id']; ?>">
            <?php if ($currentRole === 'donor'): ?>
            <input type="hidden" name="assignment_type" value="company">
            <?php elseif ($isEnterpriseVerified): ?>
            <select name="assignment_type" class="assignment-type-select" aria-label="認領身分"><option value="individual">個人／志工</option><option value="company">企業認領</option></select>
            <?php else: ?>
            <input type="hidden" name="assignment_type" value="individual">
            <?php endif; ?>
            <span class="organization-name-field" hidden><input type="text" name="organization_name" placeholder="企業／組織名稱（企業認領填寫）"></span>
            <button class="btn btn-primary btn-sm" type="submit">認領活動</button>
        </form>
    <?php else: ?>
        <span class="status status-warning">已認領或已逾期</span>
    <?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><div class="empty-state"><i class="fas fa-calendar"></i><p>目前沒有公開活動</p></div><?php endif; ?></div></div>

<div id="activity-description-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="activity-description-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="activity-description-modal-title">活動說明</h3>
            <button type="button" class="modal-close activity-description-modal-close" aria-label="關閉活動說明視窗">×</button>
        </div>
        <div class="modal-body">
            <h4 id="activity-description-title"></h4>
            <p id="activity-description-content" class="activity-description-full"></p>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('activity-description-modal');
    const title = document.getElementById('activity-description-title');
    const content = document.getElementById('activity-description-content');

    if (!modal || !title || !content) {
        return;
    }

    function closeDescriptionModal() {
        modal.style.display = 'none';
    }

    document.querySelectorAll('.activity-description-button').forEach(function (button) {
        button.addEventListener('click', function () {
            title.textContent = button.dataset.activityTitle || '活動說明';
            content.textContent = button.dataset.activityDescription || '';
            modal.style.display = 'flex';
        });
    });

    modal.querySelector('.activity-description-modal-close').addEventListener('click', closeDescriptionModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeDescriptionModal();
        }
    });
})();
</script>

<script>
document.querySelectorAll('.assignment-type-select').forEach(function (select) {
    const field = select.form.querySelector('.organization-name-field');
    const input = field.querySelector('input');

    function updateOrganizationField() {
        const isCompany = select.value === 'company';
        field.hidden = !isCompany;
        input.disabled = !isCompany;
        input.required = isCompany;
        if (!isCompany) {
            input.value = '';
        }
    }

    select.addEventListener('change', updateOrganizationField);
    updateOrganizationField();
});
</script>

<script>
document.querySelectorAll('.activity-type-select').forEach(function (select) {
    const field = select.form.querySelector('.other-activity-type-field');
    const input = field.querySelector('input');

    function updateActivityTypeField() {
        const isOther = select.value === 'other';
        field.hidden = !isOther;
        input.disabled = !isOther;
        input.required = isOther;
    }

    select.addEventListener('change', updateActivityTypeField);
    updateActivityTypeField();
});
</script>

<div id="participants-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="participants-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="participants-modal-title">參與者</h3>
            <button type="button" class="modal-close participants-modal-close" aria-label="關閉參與志工視窗">×</button>
        </div>
        <div class="modal-body" id="participants-modal-body"></div>
    </div>
</div>

<script>
(function () {
    const participantData = <?php echo json_encode($participantLists, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const modal = document.getElementById('participants-modal');
    const title = document.getElementById('participants-modal-title');
    const body = document.getElementById('participants-modal-body');

    if (!modal || !title || !body) {
        return;
    }

    function closeParticipantsModal() {
        modal.style.display = 'none';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            }[character];
        });
    }

    document.querySelectorAll('.view-participants-button').forEach(function (button) {
        button.addEventListener('click', function () {
            const participants = participantData[button.dataset.activityId] || [];
            title.textContent = '參與者：' + (button.dataset.activityTitle || '');
            const companies = participants.filter(function (participant) {
                return participant.assignment_type === 'company';
            });
            const volunteers = participants.filter(function (participant) {
                return participant.assignment_type !== 'company';
            });
            function renderParticipant(participant) {
                    const name = escapeHtml(participant.full_name || participant.username || '');
                    const type = participant.assignment_type === 'company' ? '企業認領' : '個人／志工';
                    const organization = participant.organization_name ? ' · ' + escapeHtml(participant.organization_name) : '';
                    const phone = participant.phone ? '<small>電話：' + escapeHtml(participant.phone) + '</small>' : '';
                    return '<div class="participant-item"><strong>' + name + '</strong><span>' + type + organization + '</span>' + phone + '</div>';
            }
            function renderGroup(titleText, group) {
                return group.length
                    ? '<section class="participant-group"><h4>' + titleText + '</h4><div class="participants-list">' + group.map(renderParticipant).join('') + '</div></section>'
                    : '';
            }
            body.innerHTML = participants.length
                ? renderGroup('愛心商家', companies) + renderGroup('志工', volunteers)
                : '<div class="empty-state"><i class="fas fa-users-slash"></i><p>目前沒有參與志工</p></div>';
            modal.style.display = 'flex';
        });
    });

    modal.querySelector('.participants-modal-close').addEventListener('click', closeParticipantsModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeParticipantsModal();
        }
    });
})();
</script>

<?php if (in_array($currentRole, ['volunteer', 'donor'], true)): ?>
<div class="card mt-32"><div class="card-header"><h2>我的認領紀錄</h2><p>活動結束後可下載企業永續認證證書</p></div><div class="card-body">
<?php if ($myAssignments): ?><div class="activities-assignments-table-body"><table class="data-table activities-assignments-table"><thead><tr><th>活動名稱</th><th>認領身分</th><th>企業／組織</th><th>活動狀態</th><th>操作</th></tr></thead><tbody>
<?php foreach ($myAssignments as $assignment): ?><tr>
    <td><?php echo htmlspecialchars($assignment['title']); ?></td>
    <td><?php echo $assignment['assignment_type'] === 'company' ? '企業認領' : '個人／志工'; ?></td>
    <td><?php echo htmlspecialchars($assignment['organization_name'] ?? '-'); ?></td>
    <td><span class="status status-<?php echo htmlspecialchars($assignment['activity_status']); ?>"><?php echo htmlspecialchars($activityStatusLabels[$assignment['activity_status']] ?? $assignment['activity_status']); ?></span></td>
    <td>
        <?php if (($assignment['assignment_status'] ?? 'registered') === 'cancelled'): ?>
            <span class="status status-warning">已取消，可再次認領</span>
        <?php elseif ($assignment['activity_status'] === 'completed'): ?>
            <a class="btn btn-secondary btn-sm" href="?page=activity_certificate&assignment_id=<?php echo (int) $assignment['assignment_id']; ?>" target="_blank">查看證書</a>
        <?php else: ?>
            <button class="btn btn-secondary btn-sm cancel-activity-button" type="button" data-activity-id="<?php echo (int) $assignment['activity_id']; ?>">取消認領</button>
        <?php endif; ?>
    </td>
</tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="empty-state"><i class="fas fa-clipboard-list"></i><p>尚未認領任何活動</p></div><?php endif; ?>
</div></div>
<?php endif; ?>

<div id="cancel-activity-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cancel-activity-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="cancel-activity-modal-title">取消活動認領</h3>
            <button type="button" class="modal-close" aria-label="關閉取消認領視窗">×</button>
        </div>
        <div class="modal-body">
            <p>請填寫取消此活動認領的原因。</p>
            <form method="post" id="cancel-activity-form"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="cancel_activity_registration">
                <input type="hidden" name="activity_id" id="cancel-activity-id">
                <div class="form-group">
                    <label for="cancellation-reason">取消原因*</label>
                    <textarea id="cancellation-reason" name="cancellation_reason" rows="4" maxlength="500" required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary cancel-activity-modal-close">返回</button>
                    <button type="submit" class="btn btn-primary">送出取消原因</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('cancel-activity-modal');
    const form = document.getElementById('cancel-activity-form');
    const activityIdInput = document.getElementById('cancel-activity-id');
    const reasonInput = document.getElementById('cancellation-reason');

    if (!modal || !form || !activityIdInput || !reasonInput) {
        return;
    }

    function closeCancelModal() {
        modal.style.display = 'none';
        reasonInput.value = '';
        activityIdInput.value = '';
    }

    document.querySelectorAll('.cancel-activity-button').forEach(function (button) {
        button.addEventListener('click', function () {
            activityIdInput.value = button.dataset.activityId || '';
            modal.style.display = 'flex';
            reasonInput.focus();
        });
    });

    modal.querySelector('.modal-close').addEventListener('click', closeCancelModal);
    modal.querySelector('.cancel-activity-modal-close').addEventListener('click', closeCancelModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeCancelModal();
        }
    });
})();
</script>
