<?php
/**
 * 物資分類管理
 */

require_once BASE_PATH . '/src/models/ItemCategoryModel.php';

$itemCategoryModel = new ItemCategoryModel();
$currentRole = $currentUser['role'] ?? 'member';
$isOfficial = $currentRole === 'foodbank_staff';
$message = null;
$editingCategory = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOfficial) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_category') {
        $data = [
            'category_name' => trim($_POST['category_name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'keywords' => $_POST['keywords'] ?? '',
            'default_storage' => $_POST['default_storage'] ?? '',
            'carbon_factor' => $_POST['carbon_factor'] ?? '',
            'expiry_alert_days' => $_POST['expiry_alert_days'] ?? '',
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
            'keywords' => $_POST['keywords'] ?? '',
            'default_storage' => $_POST['default_storage'] ?? '',
            'carbon_factor' => $_POST['carbon_factor'] ?? '',
            'expiry_alert_days' => $_POST['expiry_alert_days'] ?? '',
            'icon' => trim($_POST['icon'] ?? ''),
            'display_order' => (int) ($_POST['display_order'] ?? 0),
            'status' => $_POST['status'] ?? 'active',
        ]);
        $message = $updated
            ? ['type' => 'success', 'text' => '物資分類已更新。']
            : ['type' => 'error', 'text' => '分類更新失敗。'];
    } elseif ($action === 'add_correction_keyword') {
        $added = $itemCategoryModel->addKeyword((int) ($_POST['category_id'] ?? 0), $_POST['keyword'] ?? '');
        if ($added) {
            $itemCategoryModel->resolveCorrections((string) ($_POST['item_name'] ?? ''), (int) ($_POST['category_id'] ?? 0));
        }
        $message = $added
            ? ['type' => 'success', 'text' => '已加入關鍵字，之後會自動判斷到這個分類。']
            : ['type' => 'error', 'text' => '加入失敗，請確認關鍵字不是空的。'];
    } elseif ($action === 'dismiss_correction') {
        $itemCategoryModel->resolveCorrections((string) ($_POST['item_name'] ?? ''), (int) ($_POST['category_id'] ?? 0));
        $message = ['type' => 'success', 'text' => '已忽略這筆修正紀錄。'];
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
$pendingCorrections = $itemCategoryModel->getPendingCorrections();

/** 建議保存方式、減碳係數、即期提醒天數三個欄位（新增與編輯共用） */
$renderProfileFields = static function ($category = []) {
    $storage = $category['default_storage'] ?? '';
    ?>
    <div class="grid-3 category-profile-fields">
        <div class="form-group">
            <label>建議保存方式</label>
            <select name="default_storage">
                <option value="">不指定</option>
                <?php foreach (ItemCategoryModel::STORAGE_LABELS as $storageValue => $storageLabel): ?>
                    <option value="<?php echo $storageValue; ?>"<?php echo $storage === $storageValue ? ' selected' : ''; ?>><?php echo $storageLabel; ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">商家選到這個分類時，保存方式自動帶入。</p>
        </div>
        <div class="form-group">
            <label>即期提醒天數</label>
            <input type="number" name="expiry_alert_days" min="0" max="365" value="<?php echo htmlspecialchars((string) ($category['expiry_alert_days'] ?? '')); ?>" placeholder="預設 <?php echo ItemCategoryModel::DEFAULT_EXPIRY_ALERT_DAYS; ?>">
            <p class="form-hint">庫存剩這麼多天內到期就提醒。</p>
        </div>
        <div class="form-group">
            <label>減碳係數（kgCO2e／公斤）</label>
            <input type="number" name="carbon_factor" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($category['carbon_factor'] ?? '')); ?>" placeholder="預設 <?php echo ItemCategoryModel::DEFAULT_CARBON_FACTOR; ?>">
            <p class="form-hint">永續報告估算用，請依環保單位或參考資料填寫。</p>
        </div>
    </div>
    <?php
};
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
            <div class="form-group">
                <label>自動分類關鍵字</label>
                <textarea name="keywords" rows="3"><?php echo htmlspecialchars($editingCategory['keywords'] ?? ''); ?></textarea>
                <p class="form-hint">物資名稱含有這些字時，商家填物資與入庫登記會自動選這個分類（可手動更改）。用頓號、逗號或空白分隔，例如：蘋果、香蕉、果汁。</p>
            </div>
            <?php $renderProfileFields($editingCategory); ?>
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
                <label>自動分類關鍵字</label>
                <textarea name="keywords" rows="2" placeholder="例：尿布、奶瓶、嬰兒"></textarea>
                <p class="form-hint">物資名稱含有這些字時，商家填物資與入庫登記會自動選這個分類（可手動更改）。用頓號、逗號或空白分隔，例如：蘋果、香蕉、果汁。</p>
            </div>
            <?php $renderProfileFields(); ?>
            <div class="form-group">
                <label>顯示順序</label>
                <input type="number" name="display_order" min="0" value="0">
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> 新增分類</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($pendingCorrections): ?>
<div class="card mt-32">
    <div class="card-header"><h2>自動分類的手動修正</h2><p>商家或食物銀行改掉系統判斷的分類時會記在這裡。按「加入關鍵字」，之後同樣的物資就會自動判斷正確。</p></div>
    <div class="card-body">
        <div class="data-table-wrapper"><table class="data-table corrections-table">
            <thead><tr><th>物資名稱</th><th>系統原本判斷</th><th>使用者改成</th><th>次數</th><th>加入關鍵字</th></tr></thead>
            <tbody>
            <?php foreach ($pendingCorrections as $correction): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($correction['item_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($correction['suggested_name'] ?? '判斷不出'); ?></td>
                    <td><?php echo htmlspecialchars($correction['chosen_name']); ?></td>
                    <td><?php echo (int) $correction['times']; ?> 次</td>
                    <td>
                        <form method="post" class="inline-action-group">
                            <input type="hidden" name="action" value="add_correction_keyword">
                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($correction['item_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="category_id" value="<?php echo (int) $correction['chosen_category_id']; ?>">
                            <input type="text" name="keyword" maxlength="20" required value="<?php echo htmlspecialchars(ItemCategoryModel::suggestKeywordFromName($correction['item_name']), ENT_QUOTES, 'UTF-8'); ?>" aria-label="要加入的關鍵字">
                            <button class="btn btn-primary btn-sm" type="submit">加入「<?php echo htmlspecialchars($correction['chosen_name']); ?>」</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="dismiss_correction">
                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($correction['item_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="category_id" value="<?php echo (int) $correction['chosen_category_id']; ?>">
                            <button class="btn btn-secondary btn-sm" type="submit">忽略</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
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
                        <th>自動分類關鍵字</th>
                        <th>建議設定</th>
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
                            <?php $categoryKeywords = CategorySuggestHelper::parseKeywords($category['keywords'] ?? ''); ?>
                            <?php if ($categoryKeywords): ?>
                                <span class="keyword-preview" title="<?php echo htmlspecialchars(implode('、', $categoryKeywords), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(implode('、', array_slice($categoryKeywords, 0, 5))); ?><?php echo count($categoryKeywords) > 5 ? '…等 ' . count($categoryKeywords) . ' 個' : ''; ?></span>
                            <?php else: ?>
                                <span class="text-muted">未設定（只能手選或交給 AI）</span>
                            <?php endif; ?>
                        </td>
                        <td class="category-profile-cell">
                            <?php echo htmlspecialchars(ItemCategoryModel::STORAGE_LABELS[$category['default_storage'] ?? ''] ?? '不指定保存'); ?><br>
                            <small>提醒 <?php echo (int) ($category['expiry_alert_days'] ?? ItemCategoryModel::DEFAULT_EXPIRY_ALERT_DAYS); ?> 天・係數 <?php echo htmlspecialchars((string) ($category['carbon_factor'] ?? ItemCategoryModel::DEFAULT_CARBON_FACTOR)); ?></small>
                        </td>
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
