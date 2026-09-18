# 📚 文檔索引 - 食物銀行配送系統

快速導航各類文檔與報告

---

## 📖 開發指南 (`guides/`)
新手開發者與系統使用者的起點

| 文檔 | 用途 |
|------|------|
| [README.md](guides/README.md) | 系統概述與主要功能 |
| [INSTALL.md](guides/INSTALL.md) | 環境安裝與配置指南 |
| [MEETING_PROCESS.md](guides/MEETING_PROCESS.md) | 會議流程與溝通紀錄 |

---

## 🎯 規劃與設計 (`planning/`)
系統架構、功能設計與發展方向

| 文檔 | 內容 |
|------|------|
| [PROJECT_OVERVIEW.md](planning/PROJECT_OVERVIEW.md) | 完整項目概觀與目標 |
| [design.md](planning/design.md) | 技術架構與設計文檔 |
| [SYSTEM_ENHANCEMENT_EVALUATION_2026-0918.md](planning/SYSTEM_ENHANCEMENT_EVALUATION_2026-0918.md) | 系統增強評估 (2026/09/18) |

---

## 📋 實施報告 (`reports/`)
開發進度、修改記錄與待辦清單

| 文檔 | 內容 |
|------|------|
| [ENHANCEMENT_CHECKLIST.md](reports/ENHANCEMENT_CHECKLIST.md) | ✅ **待加強清單** - 優先級劃分 |
| [SYSTEM_MODIFICATIONS_2026-0918.md](reports/SYSTEM_MODIFICATIONS_2026-0918.md) | 2026/09/18 系統修改記錄 |
| [INVENTORY_REMOVAL_REPORT_2026-0918.md](reports/INVENTORY_REMOVAL_REPORT_2026-0918.md) | 庫存系統刪除報告 (2026/09/18) |

---

## 📝 會議日誌 (`logs/`)
歷次會議紀錄與決議

| 文檔 | 時間 |
|------|------|
| [會議整理_2026-0916.md](logs/會議整理_2026-0916.md) | 2026/09/16 |

---

## 🚀 快速開始

### 新開發者
1. 讀 → `guides/README.md`
2. 讀 → `guides/INSTALL.md`
3. 讀 → `planning/design.md`

### 掌握進度
→ `reports/ENHANCEMENT_CHECKLIST.md`

### 瞭解架構
→ `planning/PROJECT_OVERVIEW.md`

### 查看修改歷史
→ `reports/SYSTEM_MODIFICATIONS_2026-0918.md`

---

## 📂 代碼結構參考

```
foodbank-project/
├── public/              # 前端資源與入口
│   ├── index.php       # 主應用入口
│   └── assets/         # CSS、JS、圖片
├── src/
│   ├── models/         # 數據模型（PHP MVC）
│   └── views/          # 視圖模板
├── database/
│   └── migrations/     # 數據庫遷移（SQL）
├── config/             # 配置文件
└── docs/               # 📍 您在這裡
    ├── guides/         # 開發指南
    ├── planning/       # 規劃設計
    ├── reports/        # 實施報告
    └── logs/           # 會議紀錄
```

---

## 🔍 按需求查找

| 我想... | 去看... |
|--------|--------|
| 安裝系統 | `guides/INSTALL.md` |
| 瞭解功能 | `guides/README.md` |
| 檢查開發進度 | `reports/ENHANCEMENT_CHECKLIST.md` |
| 學習代碼架構 | `planning/design.md` |
| 查看最新修改 | `reports/SYSTEM_MODIFICATIONS_2026-0918.md` |
| 回顧會議內容 | `logs/會議整理_2026-0916.md` |

---

**最後更新**: 2026/09/18
