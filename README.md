# 食物銀行管理系統 (Shinig Yi Food Bank Management System)

## 📋 項目概述

這是一個完整的食物銀行管理系統，用於管理捐贈、庫存、受益者信息和行政運營。系統架構涵蓋多個主要功能模塊，支持從捐贈管理到受益者分配的完整工作流程。

## 🏗️ 系統架構

### 主要部門與功能：

1. **外援服務部**
   - 捐贈管理（食物、用品、現金等）
   - 捐獻者管理
   - 捐贈狀態追蹤

2. **內部倉庫**
   - 庫存管理
   - 庫存異動記錄
   - 庫存分類與位置追蹤

3. **外運營配置**
   - 受益者管理
   - 案例管理
   - 受益者分配

4. **購購管理**
   - 採購單管理
   - 供應商管理
   - 採購追蹤

5. **總務與**
   - 部門管理
   - 人員管理

6. **倉庫安排**
   - 倉庫位置管理
   - 容量管理

7. **銷售與**
   - 銷售記錄
   - 銷售統計

8. **公眾與宣傳**
   - 公眾宣傳事件
   - 社區活動管理

## 📁 項目結構

```
foodbank-project/
├── config/                    # 配置文件
│   └── database.php          # 數據庫連接配置
├── public/                    # 公開文件夾
│   ├── index.php             # 主入口文件
│   └── assets/               # 靜態資源
│       ├── css/              # 樣式表
│       ├── js/               # JavaScript 文件
│       └── images/           # 圖片資源
├── src/                      # 源代碼
│   ├── models/               # 數據模型
│   │   ├── BaseModel.php
│   │   ├── DonationModel.php
│   │   ├── BeneficiaryModel.php
│   │   ├── InventoryModel.php
│   │   └── ...
│   ├── controllers/          # 控制器
│   └── views/                # 視圖文件
├── database/                 # 數據庫相關
│   └── schema.sql           # 數據庫架構文件
├── tests/                    # 測試文件
├── .gitignore               # Git 忽略文件
└── README.md                # 項目文檔
```

## 🚀 快速開始

### 前置條件


### 安裝步驟

1. **複製項目**
   ```bash
   git clone <repository-url>
   cd foodbank-project
   ```

2. **創建數據庫**
   - 打開 phpMyAdmin（http://localhost/phpmyadmin）
   - 創建新數據庫：`shinigyi_foodbank`
   - 導入 `database/schema.sql` 文件

3. **配置數據庫連接**
   - 編輯 `config/database.php`
   - 修改數據庫用戶名、密碼等配置

4. **啟動應用**
   - 確保 Apache 和 MySQL 服務運行
   - 訪問 http://localhost/foodbank-project/foodbank-project/public

### 默認登錄憑證

- **用戶名**: `admin`
- **密碼**: `admin123`

⚠️ **重要**: 首次登錄後，請立即修改密碼！

## 📊 數據庫表說明

### 核心表

| 表名 | 說明 |
|------|------|
| `users` | 系統用戶 |
| `donations` | 捐贈記錄 |
| `inventory` | 庫存項目 |
| `beneficiaries` | 受益者信息 |
| `purchases` | 採購單 |
| `warehouses` | 倉庫信息 |
| `sales` | 銷售記錄 |
| `public_relations` | 公眾宣傳事件 |

## 🔐 安全性考慮

1. **密碼加密**: 使用 SHA-256 或 bcrypt 加密密碼
2. **SQL 防注入**: 使用準備語句（Prepared Statements）
3. **輸入驗證**: 對所有用戶輸入進行驗證
4. **訪問控制**: 基於角色的訪問控制（RBAC）
5. **審核日誌**: 記錄所有重要操作

## 📚 開發指南

### 添加新模型

1. 在 `src/models/` 中創建新文件，例如 `UserModel.php`
2. 繼承 `BaseModel` 類
3. 實現所需的方法

```php
<?php
require_once 'BaseModel.php';

class UserModel extends BaseModel {
    protected $table = 'users';
    
    // 添加自定義方法
}
?>
```

### 添加新視圖

1. 在 `src/views/` 中創建新文件
2. 使用 PHP 模板語法
3. 通過 `index.php` 的路由訪問

## 🧪 測試

待實現...

## 📝 API 文檔

待完成...

## 🤝 貢獻

歡迎提交問題和拉取請求。

## 📄 許可證

待定...

## 👥 聯繫方式

- 項目負責人: [待填寫]
- Email: [待填寫]

## 📞 支持

如有問題，請聯繫技術支持或提交 Issue。

---

**最後更新**: 2026年8月18日  
**版本**: 1.0.0
