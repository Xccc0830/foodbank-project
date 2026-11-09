<?php
/**
 * 系統設置視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/helpers/AddressHelper.php';

$settingsIsEnterprise = ($currentUser['role'] ?? '') === 'member' && ($currentUser['member_type'] ?? '') === 'enterprise';
$settingsIsStaff = ($currentUser['role'] ?? '') === 'foodbank_staff';
$businessProfile = [];
$businessMessage = null;
if ($settingsIsEnterprise) {
    $settingsDonationModel = new DonationModel();
    $settingsUserId = (int) ($currentUser['user_id'] ?? 0);

    // 商家可隨時更新聯絡人、取貨地址、營業時間與取貨備註（例如搬家）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_business_profile') {
        $addressError = validateAddress($_POST['business_address'] ?? '', '商家地址');
        if ($addressError !== null) {
            $businessMessage = ['type' => 'error', 'text' => $addressError];
        } else {
            $saveResult = $settingsDonationModel->saveBusinessProfile($settingsUserId, $_POST);
            $businessMessage = ['type' => $saveResult['success'] ? 'success' : 'error', 'text' => $saveResult['message']];
        }
    }
    $businessProfile = $settingsDonationModel->getBusinessProfile($settingsUserId);
}

$mysqlVersion = 'N/A';
if (isset($db) && $db instanceof Database) {
    $versionResult = $db->query('SELECT VERSION() AS version');
    if ($versionResult) {
        $versionRow = $versionResult->fetch_assoc();
        $mysqlVersion = $versionRow['version'] ?? 'N/A';
    }
}
?>

<div class="view-header">
    <div>
        <h1 class="view-title"><?php echo $settingsIsStaff ? '系統設置' : '設置'; ?></h1>
        <p class="view-subtitle"><?php echo $settingsIsStaff ? '管理系統配置、安全策略與郵件服務' : '管理你的商家資料'; ?></p>
    </div>
</div>

<?php if ($settingsIsEnterprise):
?>
<div class="card mb-20">
    <div class="card-header"><h2>商家資料</h2></div>
    <div class="card-body">
        <?php if ($businessMessage): ?>
            <div class="alert alert-<?php echo $businessMessage['type'] === 'success' ? 'success' : 'error'; ?>">
                <i class="fas fa-<?php echo $businessMessage['type'] === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($businessMessage['text']); ?>
            </div>
        <?php endif; ?>

        <h3 class="mb-10">基本資料（註冊時登記，無法自行修改）</h3>
        <div class="grid-2">
            <div class="form-group"><label>企業／組織名稱</label><input type="text" value="<?php echo htmlspecialchars($businessProfile['enterprise_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
            <div class="form-group"><label>統一編號</label><input type="text" value="<?php echo htmlspecialchars($businessProfile['tax_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
            <div class="form-group"><label>登入帳號</label><input type="text" value="<?php echo htmlspecialchars($businessProfile['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
            <div class="form-group"><label>電子郵件</label><input type="text" value="<?php echo htmlspecialchars($businessProfile['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
            <div class="form-group"><label>手機號碼<?php echo ($businessProfile['phone_verified'] ?? '0') === '1' ? '（已驗證）' : ''; ?></label><input type="text" value="<?php echo htmlspecialchars($businessProfile['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
        </div>
        <p class="form-hint">如需更改企業名稱、統一編號、Email 或手機，請聯絡食物銀行。</p>

        <h3 class="mb-10 mt-20">取貨與聯絡資訊（可隨時更新）</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_business_profile">
            <div class="form-group">
                <label for="contactName">聯絡人姓名 <span class="required-mark">*</span></label>
                <input id="contactName" type="text" name="contact_name" required maxlength="100" value="<?php echo htmlspecialchars($businessProfile['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="businessAddress">商家取貨地址 <span class="required-mark">*</span></label>
                <input id="businessAddress" type="text" name="business_address" required minlength="5" maxlength="255" autocomplete="off" placeholder="例：台北市信義區○○路 1 號" value="<?php echo htmlspecialchars($businessProfile['default_pickup_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <p class="form-hint">新增物資時會自動帶入這個地址。搬家後請在這裡更新；已送出的物資單仍使用當時的地址，並會通知食物銀行。</p>
            </div>
            <div class="form-group">
                <label>營業日（選填，勾選有營業的日子）</label>
                <div class="closed-days-picker">
                    <?php $selectedOpenDays = explode(',', (string) ($businessProfile['open_days'] ?? '')); ?>
                    <?php foreach (DonationModel::WEEKDAY_LABELS as $dayNumber => $dayLabel): ?>
                        <label class="closed-day-option"><input type="checkbox" name="open_days[]" value="<?php echo $dayNumber; ?>"<?php echo in_array((string) $dayNumber, $selectedOpenDays, true) ? ' checked' : ''; ?>> <?php echo $dayLabel; ?></label>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint">不勾選代表不限制。沒勾到的日子，不能預約取貨。</p>
            </div>
            <div class="form-group">
                <label for="businessHours">營業時間（選填）</label>
                <input id="businessHours" type="text" name="business_hours" maxlength="100" placeholder="例：09:00–21:00" value="<?php echo htmlspecialchars($businessProfile['business_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="closedDays">公休日（選填）</label>
                <input id="closedDays" type="text" name="closed_days" maxlength="100" placeholder="例：國定假日、每月第一個週日" value="<?php echo htmlspecialchars($businessProfile['closed_days'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <p class="form-hint">用文字說明不定期的公休，騎手到店前會看到。</p>
            </div>
            <div class="form-group">
                <label for="pickupNote">取貨備註（選填，GO Rider 到店時會看到）</label>
                <input id="pickupNote" type="text" name="pickup_note" maxlength="255" placeholder="例：請從後門進入，按鈴找店長" value="<?php echo htmlspecialchars($businessProfile['pickup_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <button type="submit" class="btn btn-primary">儲存</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($settingsIsStaff): ?>
<div class="card">
    <div class="card-header">
        <div class="settings-tabs" role="tablist" aria-label="系統設定分頁">
            <button class="tab-button active" onclick="switchTab('general', event)">常規設置</button>
            <button class="tab-button" onclick="switchTab('security', event)">安全設置</button>
            <button class="tab-button" onclick="switchTab('email', event)">郵件設置</button>
            <button class="tab-button" onclick="switchTab('system', event)">系統信息</button>
        </div>
    </div>

    <div class="card-body">
        <div id="general" class="tab-content active">
            <h3 class="mb-20">常規設置</h3>
            <form>
                <div class="form-group">
                    <label>系統名稱</label>
                    <input type="text" value="食物銀行管理系統">
                </div>
                <div class="form-group">
                    <label>系統描述</label>
                    <textarea>一個完整的食物銀行管理系統，支援捐贈、配送與公益活動管理。</textarea>
                </div>
                <div class="form-group">
                    <label>時區</label>
                    <select>
                        <option>亞洲/台北 (UTC+8)</option>
                        <option>亞洲/香港 (UTC+8)</option>
                        <option>亞洲/上海 (UTC+8)</option>
                        <option>UTC+0</option>
                    </select>
                </div>
                <div class="form-group form-check-row">
                    <label><input type="checkbox" checked> 啟用日誌記錄</label>
                </div>
                <button type="submit" class="btn btn-primary">保存設置</button>
            </form>
        </div>

        <div id="security" class="tab-content">
            <h3 class="mb-20">安全設置</h3>
            <form>
                <div class="alert alert-warning">
                    <i class="fas fa-shield-halved"></i>
                    <div>修改安全設置可能影響系統存取，請由管理員審核後再發布。</div>
                </div>
                <div class="form-group">
                    <label>會話逾時（分鐘）</label>
                    <input type="number" value="30" min="5" max="480">
                </div>
                <div class="form-group form-check-row"><label><input type="checkbox" checked> 啟用雙因素認證（2FA）</label></div>
                <div class="form-group form-check-row"><label><input type="checkbox" checked> 記錄所有登入嘗試</label></div>
                <div class="form-group form-check-row"><label><input type="checkbox" checked> 要求強密碼</label></div>
                <div class="form-group">
                    <label>密碼失敗最大嘗試次數</label>
                    <input type="number" value="5" min="1" max="10">
                </div>
                <button type="submit" class="btn btn-primary">保存設置</button>
            </form>
        </div>

        <div id="email" class="tab-content">
            <h3 class="mb-20">郵件設置</h3>
            <form>
                <div class="form-group">
                    <label>SMTP 服務器</label>
                    <input type="text" placeholder="mail.example.com">
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>SMTP 端口</label>
                        <input type="number" value="587">
                    </div>
                    <div class="form-group">
                        <label>發送者郵箱</label>
                        <input type="email" placeholder="noreply@foodbank.local">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>用戶名</label>
                        <input type="text">
                    </div>
                    <div class="form-group">
                        <label>密碼</label>
                        <input type="password">
                    </div>
                </div>
                <div class="form-group form-check-row"><label><input type="checkbox"> 使用 SSL/TLS</label></div>
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary">測試連接</button>
                    <button type="submit" class="btn btn-primary">保存設置</button>
                </div>
            </form>
        </div>

        <div id="system" class="tab-content">
            <h3 class="mb-20">系統信息</h3>
            <table class="data-table">
                <tbody>
                    <tr><td><strong>系統版本</strong></td><td><?php echo APP_VERSION ?? '1.0.0'; ?></td></tr>
                    <tr><td><strong>PHP 版本</strong></td><td><?php echo phpversion(); ?></td></tr>
                    <tr><td><strong>MySQL 版本</strong></td><td><?php echo htmlspecialchars($mysqlVersion); ?></td></tr>
                    <tr><td><strong>服務器</strong></td><td><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'); ?></td></tr>
                    <tr><td><strong>操作系統</strong></td><td><?php echo htmlspecialchars(PHP_OS_FAMILY . ' / ' . php_uname('m')); ?></td></tr>
                    <tr><td><strong>數據庫</strong></td><td><?php echo htmlspecialchars(DB_NAME); ?></td></tr>
                </tbody>
            </table>

            <div class="mt-20">
                <h3 class="mb-10">維護工具</h3>
                <div class="btn-group">
                    <button class="btn btn-success">立即備份</button>
                    <button class="btn btn-secondary">備份歷史</button>
                    <button class="btn btn-danger">清理應用緩存</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
