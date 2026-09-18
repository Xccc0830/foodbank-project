# 🍽️ 食物銀行配送系統 (Food Bank Delivery Platform)

> 從商家上架、食物銀行評估，到志工配送與公益點數，讓每一步都清楚、可靠、可追蹤。

---

## 📚 文檔導航

**所有文檔已分類整理在 [`docs/`](docs/) 目錄中**

👉 **[查看文檔索引 → `docs/INDEX.md`](docs/INDEX.md)**

### 快速連結
- 🚀 [安裝指南](docs/guides/INSTALL.md)
- 📖 [系統概述](docs/guides/README.md)
- 🎯 [系統設計](docs/planning/design.md)
- ✅ [待加強清單](docs/reports/ENHANCEMENT_CHECKLIST.md) ← **開發進度在這**
- 📝 [最新修改記錄](docs/reports/SYSTEM_MODIFICATIONS_2026-0918.md)

---

## 🎯 當前重點 (2026/09/18)

### 🔴 高優先級
- [ ] **執行數據庫遷移** (`database/migrations/`)
- [ ] 商家獎勵方案功能調試
- [ ] 企業驗證流程實作

### 🟡 中優先級  
- [ ] 獎勵兌換紀錄展示
- [ ] 搜索功能修復

詳見 → [`docs/reports/ENHANCEMENT_CHECKLIST.md`](docs/reports/ENHANCEMENT_CHECKLIST.md)

---

## 🏗️ 項目結構

```
foodbank-project/
├── docs/                  # 📍 文檔目錄
│   ├── INDEX.md          # 文檔索引
│   ├── guides/           # 開發指南
│   ├── planning/         # 規劃設計
│   ├── reports/          # 實施報告
│   └── logs/             # 會議紀錄
├── public/               # 前端資源
│   ├── index.php        # 主應用入口
│   └── assets/          # CSS、JS、圖片
├── src/                  # 後端代碼
│   ├── models/          # 數據模型
│   └── views/           # 視圖模板
├── database/            # 數據庫
│   └── migrations/      # 遷移文件 (SQL)
└── config/              # 配置文件
```

---

## 🚀 快速開始

### 新開發者
```bash
1. 閱讀 docs/guides/README.md
2. 閱讀 docs/guides/INSTALL.md  
3. 閱讀 docs/planning/design.md
4. 檢查進度 docs/reports/ENHANCEMENT_CHECKLIST.md
```

### 掌握進度
👉 [`docs/reports/ENHANCEMENT_CHECKLIST.md`](docs/reports/ENHANCEMENT_CHECKLIST.md)

---

## 👤 當前團隊角色

| 角色 | 功能 | 特權 |
|------|------|------|
| **管理員** (admin) | 系統管理、獎品設定 | 全部 |
| **食物銀行職員** (foodbank_staff) | 捐贈評估、配送管理 | 查看活動、配送 |
| **志工** (volunteer) | 領取配送任務、兌換點數 | 認領活動、兌換獎勵 |
| **愛心商家** (donor) | 上架商品、設定獎勵 | 管理獎勵方案、查看兌換 |

---

## 📞 需要幫助？

1. 檢查 [`docs/guides/`](docs/guides/) - 開發指南
2. 查看 [`docs/planning/design.md`](docs/planning/design.md) - 系統設計文檔
3. 查看會議紀錄 [`docs/logs/`](docs/logs/)

---

**更新時間**: 2026/09/18  
**進度追蹤**: [`docs/reports/ENHANCEMENT_CHECKLIST.md`](docs/reports/ENHANCEMENT_CHECKLIST.md)
