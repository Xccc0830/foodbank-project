<?php
/**
 * 物資分類管理
 */

require_once BASE_PATH . '/src/models/ItemCategoryModel.php';

$itemCategoryModel = new ItemCategoryModel();
$currentRole = $currentUser['role'] ?? 'volunteer';
$isOfficial = in_array($currentRole, ['admin', 'foodbank_staff'], true);
$message = null;
$editingCategory = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOfficial) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_category') {
        $data = [
            'category_name' => trim($_POST['category_name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'icon' => trim($_POST['icon'] ?? 'fa-solid fa-box'),
            'display_order' => (int) ($_POST['display_order'] ?? 0),
        ];
        $created = $data['category_name'] !== '' && $itemCategoryModel->createCategory($data);
        $message = $created
            ? ['type' => 'success', 'text' => '物資分類已建立。']
            : ['type' => 'error', 'text' => '分類建立失敗，請確認分類名稱不重複。'];
    } elseif ($action === 'load_edit_category') {
        $editingCategory = $itemCategoryModel->getCategoryById((int) $_POST['category_id']);
        if (!$editingCategory) {
            $message = ['type' => 'error', 'text' => '無法找到此分類。'];
            $editingCategory = null;
        }
    } elseif ($action === 'update_category') {
        $categoryId = (int) $_POST['category_id'];
        $updated = $itemCategoryModel->updateCategory($categoryId, [
            'category_name' => trim($_POST['category_name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'icon' => trim($_POST['icon'] ?? ''),
            'display_order' => (int) ($_POST['display_order'] ?? 0),
            'status' => $_POST['status'] ?? 'active',
        ]);
        $message = $updated
            ? ['type' => 'success', 'text' => '物資分類已更新。']
            : ['type' => 'error', 'text' => '分類更新失敗。'];
    } elseif ($action === 'delete_category') {
        $deleted = $itemCategoryModel->deleteCategory((int) $_POST['category_id']);
        $message = $deleted
            ? ['type' => 'success', 'text' => '物資分類已刪除。']
            : ['type' => 'error', 'text' => '分類刪除失敗。'];
    }
}

if (!$isOfficial) {
    echo '<div class="alert alert-error">只有食物銀行官方人員可以管理物資分類。</div>';
    return;
}

$categories = $itemCategoryModel->getAllCategories();
?>

<div class="view-header">
    <div>
        <h1 class="view-title">物資分類管理</h1>
        <p class="view-subtitle">管理物資的類別分類，便於追蹤和記錄</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<?php if ($editingCategory): ?>
<div class="card mt-32">
    <div class="card-header"><h2>編輯物資分類</h2></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="category_id" value="<?php echo (int) $editingCategory['category_id']; ?>">
            <div class="grid-2">
                <div class="form-group">
                    <label>分類名稱*</label>
                    <input name="category_name" value="<?php echo htmlspecialchars($editingCategory['category_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>狀態</label>
                    <select name="status">
                        <option value="active" <?php echo $editingCategory['status'] === 'active' ? 'selected' : ''; ?>>啟用</option>
                        <option value="inactive" <?php echo $editingCategory['status'] === 'inactive' ? 'selected' : ''; ?>>停用</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>描述</label>
                <textarea name="description"><?php echo htmlspecialchars($editingCategory['description'] ?? ''); ?></textarea>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>圖示</label>
                    <input name="icon" value="<?php echo htmlspecialchars($editingCategory['icon']); ?>" placeholder="e.g. fa-solid fa-leaf">
                </div>
                <div class="form-group">
                    <label>顯示順序</label>
                    <input type="number" name="display_order" value="<?php echo (int) $editingCategory['display_order']; ?>">
                </div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">儲存變更</button>
                <a class="btn btn-secondary" href="?page=item_categories">取消</a>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><h2>新增物資分類</h2></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="create_category">
            <div class="grid-2">
                <div class="form-group">
                    <label>分類名稱*</label>
                    <input name="category_name" required placeholder="例：蔬菜">
                </div>
                <div class="form-group">
                    <label>圖示</label>
                    <input name="icon" placeholder="e.g. fa-solid fa-leaf">
                </div>
            </div>
            <div class="form-group">
                <label>描述</label>
                <textarea name="description" placeholder="描述此分類所包含的物資類型"></textarea>
            </div>
            <div class="form-group">
                <label>顯示順序</label>
                <input type="number" name="display_order" min="0" value="0">
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> 新增分類</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mt-32">
    <div class="card-header"><h2>分類列表</h2><p>目前共 <?php echo count($categories); ?> 個分類</p></div>
    <div class="card-body">
        <?php if ($categories): ?>
        <div class="categories-table-body">
        <table class="data-table categories-table">
                <thead>
                    <tr>
                        <th>分類名稱</th>
                        <th>描述</th>
                        <th>狀態</th>
                        <th>順序</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                    <tr>
                        <td>
                            <?php if ($category['icon']): ?>
                                <i class="<?php echo htmlspecialchars($category['icon']); ?>"></i>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($category['description'] ?? ''); ?></td>
                        <td>
                            <span class="status status-<?php echo $category['status']; ?>">
                                <?php echo $category['status'] === 'active' ? '啟用' : '停用'; ?>
                            </span>
                        </td>
                        <td><?php echo (int) $category['display_order']; ?></td>
                        <td>
                            <div class="inline-action-group">
                                <form method="post" class="delivery-action-form">
                                    <input type="hidden" name="action" value="load_edit_category">
                                    <input type="hidden" name="category_id" value="<?php echo (int) $category['category_id']; ?>">
                                    <button class="btn btn-secondary btn-sm" type="submit">編輯</button>
                                </form>
                                <form method="post" class="delivery-action-form" onsubmit="return confirm('確定要刪除此分類嗎？');">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?php echo (int) $category['category_id']; ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">刪除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-layer-group"></i><p>尚未建立任何分類</p></div>
        <?php endif; ?>
    </div>
</div>
