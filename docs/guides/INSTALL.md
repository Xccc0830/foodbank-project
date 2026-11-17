# 食物銀行管理系統 - 快速安裝指南

## 📋 系統要求

- **PHP**: XAMPP PHP 8.2（需啟用 `mysqli`、`curl`、`fileinfo`、`mbstring`、`openssl`）
- **資料庫**: XAMPP MariaDB 10.4；以 phpMyAdmin 匯入
- **Web 服務器**: Apache（XAMPP 已包含）
- **瀏覽器**: 現代瀏覽器（Chrome、Firefox、Safari 等）

## 🚀 安裝步驟

### 資料庫

`database/schema.sql` 是本專案提供的 SQL，包含資料表、權限與既有示範資料，僅供新安裝使用。
匯入前請先備份資料庫；新安裝時，先建立空的 `shinigyi_foodbank`，再直接匯入 `database/schema.sql`。
此 SQL 會刪除並重建其中列出的資料表，不可匯入已有資料的資料庫。本專案目前不提供既有資料庫升級 SQL；若需保留既有資料，請勿匯入。
本 SQL 含有測試帳號和示範資料，不能直接視為正式環境的空白資料庫。正式使用前須清理不需要的資料及停用或更換所有測試帳號。

駕照照片存放於 `storage/rider_licenses/`，身分證加密金鑰存放於 `storage/keys/`。部署時請確認 PHP 可寫入 `storage/`、Apache 已啟用 `.htaccess` 並禁止直接存取 `storage/`。備份資料庫時也須安全備份金鑰和上傳檔案，否則無法還原資料或解密身分證資料。
物資捐贈表單在「商家取貨地址」與「配送地址」都填寫完成後，會呼叫伺服器端的 `?action=calculate_distance` API 自動計算兩地之間的路程距離與預估運送時間並帶入表單。此功能透過 OpenStreetMap 的 Nominatim（地理編碼）與 OSRM（路徑規劃）公開服務運作，**需要伺服器可對外連線**（PHP 需啟用 `curl` 擴充套件）；由於台灣部分門牌號碼未完整收錄於 OpenStreetMap，系統會自動從完整地址逐步簡化為路段層級查詢，若僅能定位到路段會標示為概略估算。

**正式上線限制：**目前尚未串接簡訊服務。`APP_DEBUG=false` 時不會顯示模擬驗證碼，註冊手機驗證及忘記密碼無法完成；應先串接簡訊服務或安排其他安全的身分驗證流程，勿以開啟除錯模式代替。

### 第 1 步：獲取項目文件

本項目應已位於以下位置：
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

### 第 4 步：導入數據庫架構

1. 在 phpMyAdmin 中，選擇 `shinigyi_foodbank` 數據庫
2. 點擊 **導入** 選項卡
3. 選擇文件：`database/schema.sql`
4. 點擊 **執行**

**或者** 使用命令行：

```bash
mysql -u root shinigyi_foodbank < "C:\xampp\htdocs\SA\foodbank-project\foodbank-project\database\schema.sql"
```

### 第 5 步：驗證安裝

在瀏覽器中訪問：
```
http://localhost/SA/foodbank-project/foodbank-project/public/
```
其他安裝路徑請依 `htdocs` 下的實際資料夾名稱調整網址。

### 第 6 步：登錄應用

新安裝的示範資料包含食物銀行、商家及 GO Rider 測試帳號。僅在本機開發時啟用除錯模式，登入頁才會顯示測試帳號快捷按鈕。正式使用前必須停用或更換測試帳號及密碼。

## 🔐 安全建議（重要）

### 首次登錄後立即執行：

1. **修改默認密碼**
   - 訪問設置頁面
   - 更改食物銀行職員帳號密碼

2. **更新數據庫配置**
   - 如需自訂連線，在 Git 忽略的 `config/local.php` 設定 `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER` 和 `DB_PASS`，不要提交密碼。
   - 預設為 `127.0.0.1:3306`、資料庫 `shinigyi_foodbank`、帳號 `root`、空密碼；正式環境應使用有密碼且權限適當的帳號。

3. **配置生產環境設置**
   - 預設 `APP_DEBUG` 為 `false`；正式使用時維持關閉，並啟用 HTTPS。
   - 設定 Apache 使用 `public/` 作為網站根目錄，或確認專案根目錄及 `public/uploads/` 的 `.htaccess` 生效；不要公開設定檔、備份、金鑰或資料庫工具。
   - 切勿將 phpMyAdmin 或資料庫管理介面直接公開至網際網路，並定期備份。

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
│   └── schema.sql            # 唯一 SQL；僅供新安裝
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
- 確認帳號密碼正確
- 檢查數據庫中是否有 users 表
- 查看 PHP 錯誤日誌

### Q4: 上傳文件失敗
**A**:
- 檢查 `public/uploads/` 與 `storage/` 是否可由 PHP 寫入
- 確認 PHP 上傳大小限制設置

## 🚀 開發環境配置

### 啟用調試模式

僅供本機測試：在 Git 忽略的 `config/local.php` 加入：
```php
<?php
define('APP_DEBUG', true);
```
此模式會顯示測試帳號及模擬驗證碼；不要在可公開存取的網站啟用。

### 啟用錯誤報告

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📚 后續步驟

1. **自定義品牌** - 編輯 `config/database.php` 中的 `APP_NAME`
2. **添加更多用戶** - 在後台管理添加新用戶
3. **串接正式簡訊驗證** - 手機驗證與忘記密碼目前僅有開發用模擬
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
