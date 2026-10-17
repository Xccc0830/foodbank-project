# 食物銀行管理系統 - 快速安裝指南

## 📋 系統要求

- **PHP**: 7.4 或更高版本
- **MySQL**: 5.7 或更高版本
- **Web 服務器**: Apache（XAMPP 已包含）
- **瀏覽器**: 現代瀏覽器（Chrome、Firefox、Safari 等）

## 🚀 安裝步驟

### 資料庫

`database/schema.sql` 是新安裝唯一需要匯入的主 SQL，已整合目前完整資料表、欄位、索引與權限種子資料。
匯入前請先備份資料庫；新安裝或重建資料庫時，先建立 `shinigyi_foodbank`，再直接匯入 `database/schema.sql`，不需另外匯入遷移檔。
`database/migrations/` 僅供既有資料庫逐步升級使用；不可在新安裝匯入主 SQL 後再重複套用。
依 2026/10/13 專題會議決議，系統不提供即時 GPS 定位；配送進度以狀態（接單、取貨、送達）呈現。

既有資料庫先備份，再執行 `database/migrations/20261018_meeting_decisions_consolidated.sql` 一次，即可套用上述會議決議的資料庫變更。此合併遷移檔涵蓋 GPS 移除、防拆封條改取貨照片、外送志工申請審核，以及管理員／行政人員、最新消息公告、配送預估與逾時通知、企業統一編號、黑名單和物資盤點。
合併遷移檔取代以下四個元件遷移檔的逐一執行方式；請勿在執行合併檔後再重複執行元件檔：

- `20261017_remove_live_gps_tracking.sql`
- `20261017_remove_seal_add_pickup_photo.sql`
- `20261017_rider_application.sql`
- `20261018_roles_news_arrival_blacklist.sql`

`database/schema.sql` 仍是新安裝的主 SQL，不可用於升級既有資料庫。若先前已套用部分元件遷移，合併檔使用「若尚未存在才新增／移除」的結構操作，可接續完成變更。

駕照照片存放於 `storage/rider_licenses/`（不在網站根目錄內），部署時請確認 PHP 可寫入該資料夾，並由網站伺服器禁止直接存取 `storage/`。
物資捐贈表單在「商家取貨地址」與「配送地址」都填寫完成後，會呼叫伺服器端的 `?action=calculate_distance` API 自動計算兩地之間的路程距離與預估運送時間並帶入表單。此功能透過 OpenStreetMap 的 Nominatim（地理編碼）與 OSRM（路徑規劃）公開服務運作，**需要伺服器可對外連線**（PHP 需啟用 `curl` 擴充套件）；由於台灣部分門牌號碼未完整收錄於 OpenStreetMap，系統會自動從完整地址逐步簡化為路段層級查詢，若僅能定位到路段會標示為概略估算。

### 第 1 步：獲取項目文件

本項目應已位於以下位置：
```
C:\xampp\htdocs\foodbank-project
```

### 第 2 步：啟動 XAMPP 服務

1. 打開 XAMPP 控制面板
2. 啟動 **Apache** 和 **MySQL** 服務
3. 確認兩個服務都顯示綠色狀態

### 第 3 步：創建數據庫

#### 方式 A：使用 phpMyAdmin（推薦）

1. 打開瀏覽器訪問 http://localhost/phpmyadmin
2. 登錄（默認用戶名：root，無密碼）
3. 點擊左側菜單的 **新建**
4. 輸入數據庫名稱：`shinigyi_foodbank`
5. 選擇字符集：`utf8mb4_unicode_ci`
6. 點擊 **建立**

#### 方式 B：使用命令行

```bash
mysql -u root -p
```

然後執行：
```sql
CREATE DATABASE shinigyi_foodbank CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

### 第 4 步：導入數據庫架構

1. 在 phpMyAdmin 中，選擇 `shinigyi_foodbank` 數據庫
2. 點擊 **導入** 選項卡
3. 選擇文件：`database/schema.sql`
4. 點擊 **執行**

**或者** 使用命令行：

```bash
mysql -u root shinigyi_foodbank < "C:\xampp\htdocs\foodbank-project\database\schema.sql"
```

### 第 5 步：驗證安裝

在瀏覽器中訪問：
```
http://localhost/foodbank-project/foodbank-project/public
```

### 第 6 步：登錄應用

使用以下預設帳號登錄：

#### 管理介面
- **用戶名**: `manager`
- **密碼**: `manager123`

#### 店家身份（捐贈店家）
- **用戶名**: `store_demo`
- **密碼**: `store123`
- **會員類型**: 企業會員

#### 忠信GO RIDER
- **用戶名**: `courier_demo`
- **密碼**: `courier123`
- **會員類型**: 一般會員

> 這些帳號已經在資料庫中建立好，可直接用於測試捐贈與配送流程。

## 🔐 安全建議（重要）

### 首次登錄後立即執行：

1. **修改默認密碼**
   - 訪問設置頁面
   - 更改食物銀行職員帳號密碼

2. **更新數據庫配置**
   - 編輯 `config/database.php`
   - 設置 MySQL 密碼（如已設置）
   - 更改數據庫用戶名

3. **配置生產環境設置**
   - 在 `config/database.php` 中設置 `APP_DEBUG = false`
   - 配置適當的日誌記錄

## 📁 文件結構說明

```
foodbank-project/
├── config/
│   └── database.php           # 數據庫連接配置
├── src/
│   ├── models/                # 數據模型（業務邏輯）
│   ├── controllers/           # 控制器（待實現）
│   └── views/                 # 視圖文件（前端頁面）
├── public/
│   ├── index.php             # 主入口文件
│   └── assets/
│       ├── css/              # 樣式表
│       ├── js/               # JavaScript
│       └── images/           # 圖片資源
├── database/
│   ├── schema.sql            # 新安裝唯一主 SQL
│   └── migrations/           # 既有資料庫升級用
└── README.md                 # 項目文檔
```

## 🔧 常見問題

### Q1: 訪問報錯 "數據庫連接失敗"
**A**: 檢查以下項目：
- MySQL 服務是否已啟動
- `config/database.php` 中的數據庫設置是否正確
- 數據庫是否已創建

### Q2: 頁面無樣式顯示
**A**: 
- 清除瀏覽器緩存（Ctrl+Shift+Delete）
- 檢查 CSS 文件路徑是否正確

### Q3: 無法登錄
**A**:
- 確認密碼正確（默認密碼：`admin123`）
- 檢查數據庫中是否有 users 表
- 查看 PHP 錯誤日誌

### Q4: 上傳文件失敗
**A**:
- 檢查 `uploads/` 文件夾權限（需要 777）
- 確認 PHP 上傳大小限制設置

## 🚀 開發環境配置

### 啟用調試模式

編輯 `config/database.php`：
```php
define('APP_DEBUG', true);  // 開發時設為 true
```

### 啟用錯誤報告

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📚 后續步驟

1. **自定義品牌** - 編輯 `config/database.php` 中的 `APP_NAME`
2. **添加更多用戶** - 在後台管理添加新用戶
3. **配置郵件系統** - 在設置中配置 SMTP
4. **設置備份計劃** - 定期備份數據庫

## 📞 技術支持

如遇到問題，請檢查以下資源：

- README.md - 項目文檔
- database/schema.sql - 完整資料庫架構與必要種子資料
- 瀏覽器控制台 - JavaScript 錯誤信息
- PHP 錯誤日誌 - `C:\xampp\apache\logs\error.log`

## 🎉 安裝完成

恭喜！你的食物銀行管理系統已成功安裝。

開始探索系統的各項功能，開展食物銀行的日常運營管理。

---

**版本**: 1.0.1  
**最後更新**: 2026年9月16日
