<?php
/**
 * 身分證驗證：會員送出字號；食物銀行人員審核。
 */

require_once BASE_PATH . '/src/models/IdVerificationModel.php';

$idModel = new IdVerificationModel();
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$canReviewId = $authService->can('id.review');
$idMessage = null;
$needsOwnVerification = ($currentUser['role'] ?? '') === 'member' && ($currentUser['member_type'] ?? '') === 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'submit_id_number' && $needsOwnVerification) {
        $result = $idModel->submit($currentUserId, $_POST['id_number'] ?? '');
        $idMessage = $result === true
            ? ['type' => 'success', 'text' => '已送出，等待食物銀行審核。']
            : ['type' => 'error', 'text' => $result];
    } elseif ($canReviewId && in_array($postAction, ['approve_id', 'reject_id'], true)) {
        $decision = $postAction === 'approve_id' ? 'approved' : 'rejected';
        $idMessage = $idModel->review((int) ($_POST['user_id'] ?? 0), $currentUserId, $decision, $_POST['review_note'] ?? '')
            ? ['type' => 'success', 'text' => $decision === 'approved' ? '已核准。' : '已退回。']
            : ['type' => 'error', 'text' => '操作失敗：退回時必須填寫原因，也不能審核自己，且該筆可能已被處理。'];
    }
}

$myStatus = $idModel->getStatus($currentUserId);
$statusLabels = ['none' => '尚未驗證', 'pending' => '審核中', 'approved' => '已通過', 'rejected' => '未通過'];
$statusClasses = ['none' => 'pending', 'pending' => 'pending', 'approved' => 'active', 'rejected' => 'suspended'];
$myIdStatus = $myStatus['id_verify_status'] ?? 'none';
?>

<div class="view-header">
    <div>
        <h1 class="view-title">身分驗證</h1>
        <p class="view-subtitle">身分證字號會加密保存，審核畫面只會顯示遮罩後的內容</p>
    </div>
</div>

<?php if ($idMessage): ?>
    <div class="alert alert-<?php echo $idMessage['type'] === 'success' ? 'success' : 'error'; ?>">
        <?php echo htmlspecialchars($idMessage['text']); ?>
    </div>
<?php endif; ?>

<?php if ($needsOwnVerification): ?>
<div class="card">
    <div class="card-header"><h2>我的驗證狀態</h2></div>
    <div class="card-body">
        <p>
            <span class="status status-<?php echo $statusClasses[$myIdStatus]; ?>"><?php echo htmlspecialchars($statusLabels[$myIdStatus]); ?></span>
            <?php if (!empty($myStatus['masked'])): ?> <small><?php echo htmlspecialchars($myStatus['masked']); ?></small><?php endif; ?>
        </p>
        <?php if ($myIdStatus === 'rejected' && !empty($myStatus['id_verify_note'])): ?>
            <p class="alert alert-error">未通過原因：<?php echo htmlspecialchars($myStatus['id_verify_note']); ?></p>
        <?php endif; ?>
        <?php if (in_array($myIdStatus, ['none', 'rejected'], true)): ?>
            <form method="post" id="idNumberForm" autocomplete="off">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="submit_id_number">
                <div class="form-group">
                    <label for="idNumberInput">身分證字號 / 居留證號</label>
                    <input id="idNumberInput" type="text" name="id_number" maxlength="10" required placeholder="例如 A123456789" style="text-transform: uppercase">
                    <small id="idNumberHint"></small>
                </div>
                <button type="submit" class="btn btn-primary">送出審核</button>
            </form>
            <script>
            (function () {
                var codes = {A:10,B:11,C:12,D:13,E:14,F:15,G:16,H:17,I:34,J:18,K:19,L:20,M:21,N:22,O:35,P:23,Q:24,R:25,S:26,T:27,U:28,V:29,W:32,X:30,Y:31,Z:33};
                function valid(v) {
                    v = v.toUpperCase().replace(/\s+/g, '');
                    var digits;
                    if (/^[A-Z][12489][0-9]{8}$/.test(v)) {
                        digits = String(codes[v[0]]) + v.slice(1);
                    } else if (/^[A-Z][A-D][0-9]{8}$/.test(v)) {
                        digits = String(codes[v[0]]) + (codes[v[1]] % 10) + v.slice(2);
                    } else {
                        return false;
                    }
                    var w = [1, 9, 8, 7, 6, 5, 4, 3, 2, 1, 1], sum = 0;
                    for (var i = 0; i < digits.length; i++) { sum += Number(digits[i]) * w[i]; }
                    return sum % 10 === 0;
                }
                var form = document.getElementById('idNumberForm');
                var input = document.getElementById('idNumberInput');
                var hint = document.getElementById('idNumberHint');
                input.addEventListener('blur', function () {
                    hint.textContent = input.value && !valid(input.value) ? '格式或檢查碼不正確，請再確認。' : '';
                });
                form.addEventListener('submit', function (e) {
                    if (!valid(input.value)) {
                        e.preventDefault();
                        hint.textContent = '格式或檢查碼不正確，請再確認。';
                    }
                });
            })();
            </script>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canReviewId): ?>
    <?php $reviewList = $idModel->listForReview(); ?>
    <div class="card">
        <div class="card-header"><h2>身分證審核</h2><p>共 <?php echo count($reviewList); ?> 筆</p></div>
        <div class="card-body">
            <?php if ($reviewList): ?>
                <table class="data-table">
                    <thead><tr><th>會員</th><th>字號（遮罩）</th><th>狀態</th><th>審核</th></tr></thead>
                    <tbody>
                    <?php foreach ($reviewList as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['username']); ?></small></td>
                            <td><?php echo htmlspecialchars($row['masked']); ?></td>
                            <td>
                                <span class="status status-<?php echo $statusClasses[$row['id_verify_status']]; ?>"><?php echo htmlspecialchars($statusLabels[$row['id_verify_status']]); ?></span>
                                <?php if (!empty($row['id_verify_note'])): ?><br><small><?php echo htmlspecialchars($row['id_verify_note']); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['id_verify_status'] === 'pending' && (int) $row['user_id'] !== $currentUserId): ?>
                                    <form method="post" class="delivery-action-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="user_id" value="<?php echo (int) $row['user_id']; ?>">
                                        <input name="review_note" placeholder="備註（退回時必填）">
                                        <button class="btn btn-primary btn-sm" type="submit" name="action" value="approve_id">核准</button>
                                        <button class="btn btn-danger btn-sm" type="submit" name="action" value="reject_id">退回</button>
                                    </form>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-id-card"></i><p>目前沒有待審核的身分證</p></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
