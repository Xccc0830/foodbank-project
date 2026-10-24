# 食物銀行管理系統 - 快速安裝指南

## 📋 系統要求

- **PHP**: 7.4 或更高版本
- **MySQL**: 5.7 或更高版本
- **Web 服務器**: Apache（XAMPP 已包含）
- **瀏覽器**: 現代瀏覽器（Chrome、Firefox、Safari 等）

## 🚀 安裝步驟

### 資料庫

`database/schema.sql` 是新安裝用的結構與種子資料；它會刪除並重建其中列出的資料表。
若要還原目前提供的資料庫備份，請使用專案根目錄的 `shinigyi_foodbank.sql`。這是 2026-10-22 匯出的 MariaDB 備份，包含資料表結構與資料，但不會先刪除同名資料表。
兩個 SQL 都只適合匯入新建的空資料庫，不是既有資料庫升級檔。匯入前先備份；若資料庫已有需要保留的資料，請勿直接匯入。
依 2026/10/13 專題會議決議，系統不提供即時 GPS 定位；配送進度以狀態（接單、取貨、送達）呈現。

駕照照片存放於 `storage/rider_licenses/`（不在網站根目錄內），部署時請確認 PHP 可寫入該資料夾，並由網站伺服器禁止直接存取 `storage/`。
物資捐贈表單在「商家取貨地址」與「配送地址」都填寫完成後，會呼叫伺服器端的 `?action=calculate_distance` API 自動計算兩地之間的路程距離與預估運送時間並帶入表單。此功能透過 OpenStreetMap 的 Nominatim（地理編碼）與 OSRM（路徑規劃）公開服務運作，**需要伺服器可對外連線**（PHP 需啟用 `curl` 擴充套件）；由於台灣部分門牌號碼未完整收錄於 OpenStreetMap，系統會自動從完整地址逐步簡化為路段層級查詢，若僅能定位到路段會標示為概略估算。

### 第 1 步：獲取項目文件

將專案放在 XAMPP 的 `htdocs` 目錄下，例如：
```
C:\xampp\htdocs\SA\foodbank-project\foodbank-project
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

### 第 4 步：匯入資料庫

1. 在 phpMyAdmin 中選擇剛建立的空白 `shinigyi_foodbank` 資料庫。
2. 點擊 **匯入**，選擇專案根目錄的 `shinigyi_foodbank.sql`，再執行匯入。
3. 如果你要的是空白測試資料，改匯入 `database/schema.sql`；不要將兩個 SQL 連續匯入同一資料庫。

**或者** 使用命令行：

```bash
mysql -u root shinigyi_foodbank < "C:\xampp\htdocs\SA\foodbank-project\foodbank-project\shinigyi_foodbank.sql"
```

### 第 5 步：驗證安裝

在瀏覽器中訪問：
```
http://localhost/SA/foodbank-project/foodbank-project/
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

### 第 7 步：讓同一個區域網路的組員連線

XAMPP 主機（執行 Apache 和 MySQL 的那台電腦）與組員的電腦必須連上同一個區域網路。

1. 在主機啟動 XAMPP 的 **Apache** 和 **MySQL**。
2. 在主機執行 `ipconfig`，找到目前使用中的 Wi-Fi 或乙太網路介面的 IPv4 位址（例如 `192.168.1.23`；不要使用 `127.0.0.1`）。
3. 組員在瀏覽器開啟 `http://主機IPv4位址/SA/foodbank-project/foodbank-project/`，例如 `http://192.168.1.23/SA/foodbank-project/foodbank-project/`。
4. 若 Windows 防火牆詢問是否允許 Apache，僅允許**私人網路**。若組員仍無法連線，請確認防火牆允許 Apache 接收區域網路的 HTTP 連線；不要設定路由器連接埠轉送，也不要將 MySQL 的 3306 連接埠公開到網際網路。
5. phpMyAdmin 只在主機上使用（`http://localhost/phpmyadmin`），不要讓組員透過網路管理資料庫。組員透過網站登入及操作，所有人看到的是主機上同一份資料庫。

此方式只適用於同一個區域網路及主機保持開機的期間，不是公開網際網路網站。若組員所說的「修改」是修改程式碼，請各自使用 Git 分支提交變更，再由主機端更新及測試；不要多人同時直接修改主機上的同一份檔案。

## 🔐 安全建議（重要）

### 首次登錄後立即執行：

1. **修改默認密碼**
   - 訪問設置頁面
   - 更改食物銀行職員帳號密碼

2. **更新資料庫設定**
   - 預設連線為本機 MySQL：資料庫 `shinigyi_foodbank`、使用者 `root`、密碼空白。
   - 若 XAMPP 的 MySQL root 帳號有設定密碼，請在被 Git 忽略的 `config/local.php` 設定 `DB_DRIVER`、`DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER` 與 `DB_PASS`；不要把密碼提交到 Git。
   - 若主機的環境變數有設定 `DATABASE_URL`，請在 `config/local.php` 明確設定 `DB_DRIVER` 為 `mysql`，避免程式誤選 PostgreSQL。

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
│   └── schema.sql            # 新安裝用結構與種子資料
├── shinigyi_foodbank.sql     # 本機資料庫備份；不要提交至 Git
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
