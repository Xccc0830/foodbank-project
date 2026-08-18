# 食物銀行管理系統 - 快速安裝指南

## 📋 系統要求

- **PHP**: 7.4 或更高版本
- **MySQL**: 5.7 或更高版本
- **Web 服務器**: Apache（XAMPP 已包含）
- **瀏覽器**: 現代瀏覽器（Chrome、Firefox、Safari 等）

## 🚀 安裝步驟

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
mysql -u root shinigyi_foodbank < C:\xampp\htdocs\foodbank-project\database\schema.sql
```

### 第 5 步：驗證安裝

在瀏覽器中訪問：
```
http://localhost/foodbank-project/foodbank-project/public
```

### 第 6 步：登錄應用

使用默認憑證登錄：

- **用戶名**: `admin`
- **密碼**: `admin123`

## 🔐 安全建議（重要）

### 首次登錄後立即執行：

1. **修改默認密碼**
   - 訪問設置頁面
   - 更改 admin 用戶密碼

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
│   └── schema.sql            # 數據庫架構
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
- database/schema.sql - 數據庫結構說明
- 瀏覽器控制台 - JavaScript 錯誤信息
- PHP 錯誤日誌 - `C:\xampp\apache\logs\error.log`

## 🎉 安裝完成

恭喜！你的食物銀行管理系統已成功安裝。

開始探索系統的各項功能，開展食物銀行的日常運營管理。

---

**版本**: 1.0.0  
**最後更新**: 2026年8月18日
