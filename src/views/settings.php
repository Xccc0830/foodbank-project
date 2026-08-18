<?php
/**
 * 系統設置視圖 - SaaS 風格迭代
 */

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
        <h1 class="view-title">系統設置</h1>
        <p class="view-subtitle">管理系統配置、安全策略與郵件服務</p>
    </div>
</div>

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
                    <textarea>一個完整的食物銀行管理系統，支援捐贈、庫存、受益者與採購管理。</textarea>
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
