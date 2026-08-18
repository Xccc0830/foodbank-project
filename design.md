# Food Bank Admin Design System

Last Updated: 2026-08-18  
Scope: Admin Web UI (Dashboard, Donations, Inventory, Beneficiaries, Purchases, Settings)

## 1. Design Goals

- 建立穩定、清晰、可擴展的後台視覺語言。
- 優先可讀性與資訊層級，避免過度裝飾。
- 用一致規則完成所有新頁與新元件，減少「每頁長得不同」的問題。
- 桌面優先，手機與平板有明確降級規則。

## 2. Visual Principles

### 2.1 Tone
- 產品感: 專業、克制、資訊導向。
- 視覺節奏: 大區塊留白 + 細緻邊框 + 輕陰影。
- 色彩策略: 中性灰為主，品牌色只用於主要行動與重點狀態。

### 2.2 Hierarchy
- 第一層: 頁面標題、主要 KPI。
- 第二層: 區塊卡片標題、表格標題列。
- 第三層: 輔助資訊、說明文字、提示訊息。

## 3. Design Tokens

### 3.1 Brand and Semantic Colors
- Primary: #635BFF
- Primary Light: #7A73FF
- Primary Dark: #4E47E5
- Accent: #FF385C
- Success: #10B981
- Warning: #F59E0B
- Danger: #EF4444
- Info: #3B82F6

### 3.2 Neutral Palette
- Gray 50: #FCFCFD
- Gray 100: #F6F7FA
- Gray 150: #EEF1F6
- Gray 200: #E5E7EB
- Gray 300: #D1D5DB
- Gray 400: #9CA3AF
- Gray 500: #6B7280
- Gray 700: #374151
- Gray 900: #111827

### 3.3 Backgrounds
- App Background: #F7F9FC
- Surface: #FFFFFF
- Soft Surface: #F2F5FF
- Sidebar: #0F172A 到 #111C33 (垂直漸層)

### 3.4 Typography
- Font Family: Plus Jakarta Sans, Segoe UI, PingFang TC, Noto Sans TC, sans-serif
- Page Title: 34px, 700
- Card Title: 18px, 600
- Body: 14px, 400
- Caption/Meta: 12-13px, 500-600

### 3.5 Radius
- Small: 6px
- Medium: 8px
- Large: 12px
- XL: 16px
- Pill: 999px

### 3.6 Shadow
- XS: 0 1px 1px rgba(17, 24, 39, 0.02)
- SM: 0 2px 6px rgba(17, 24, 39, 0.05)
- MD: 0 6px 20px rgba(17, 24, 39, 0.08)
- LG: 0 14px 32px rgba(17, 24, 39, 0.10)

### 3.7 Spacing Scale
- 8, 10, 12, 14, 16, 20, 24, 32, 40
- 區塊主間距以 24 和 32 為主

## 4. Layout System

### 4.1 Global Shell
- Sidebar 固定寬: 260px
- Main Container 寬度: calc(100% - 260px)
- Content Shell 最大寬度: 1360px
- Topbar 高度由內容撐開，保持 sticky

### 4.2 Responsive Rules
- 1200px 以下: 內容內距縮到 24px，3 欄卡片降到 2 欄。
- 768px 以下: 側欄改抽屜，主區寬度 100%，表格字級與間距縮小。
- 480px 以下: 按鈕可全寬，卡片內距縮到 16px。

## 5. Component Standards

### 5.1 Sidebar
- 深色背景，淡色文字。
- Active 項使用品牌色半透明底與邊框高亮。
- Icon 尺寸統一 14px，文字 14px。

### 5.2 Topbar
- 白底 + 淺邊框。
- 搜尋框為 pill 外型，聚焦時 3px 品牌光暈。
- 右上 icon button 為 36x36，圓角 10px。

### 5.3 Card
- 白底、1px 邊框、輕陰影。
- Header 與 Body 分開，Header 下邊框清楚。
- Hover 只加強陰影，不改背景色。

### 5.4 KPI Stat Card
- KPI 數字使用 36px，字重 700。
- 標題全大寫可選，推薦 13px + 字距 0.5px。
- 保持每張卡同高，避免版面抖動。

### 5.5 Buttons
- Primary: 實心品牌色，hover 變深。
- Secondary: 白底描邊。
- Danger/Success: 僅用在明確語意行為。
- Small 按鈕用於表格列操作。

### 5.6 Table
- Header 背景使用淺灰藍。
- Cell 內距: 14px 16px。
- Row hover 使用極淡底色。
- 表格操作按鈕放在同一組，不可散開。

### 5.7 Form
- Input 高度統一，邊框 #D1D5DB。
- Focus 時使用品牌色 ring。
- Label 14px，600。

### 5.8 Status Badge
- Approved/Active: 綠系
- Pending: 黃系
- Rejected/Inactive/Cancelled: 紅系
- Draft: 中性灰
- Badge 需保持小尺寸與低對比底色，避免喧賓奪主。

### 5.9 Empty State
- Icon + 一句說明 + 一個主行動按鈕。
- 不要放過多文字。

## 6. Page Template Rules

### 6.1 Header Block
每個頁面固定包含:
- 左側: 頁面標題 + 一行副標
- 右側: 1 個主要操作按鈕

### 6.2 Content Blocks
建議順序:
1) KPI 區塊
2) 主資料卡片或表格
3) 次要資訊卡片
4) 快速操作區

### 6.3 Toolbar Pattern
表格上方固定工具列:
- 搜尋 input
- 篩選 select
- 匯出或新增按鈕

## 7. Interaction and Motion

- Transition 時長: 150ms 到 300ms。
- Hover 以顏色或陰影細微變化為主，不做大位移。
- 按鈕可保留 1px 上浮，避免誇張動畫。

## 8. Accessibility Baseline

- 文字與背景至少符合一般可讀對比。
- 所有 icon button 需要 title 或可見文字。
- 鍵盤可聚焦元素需有明確 focus 樣式。

## 9. Implementation Mapping

本規範對應的主要檔案:
- public/assets/css/style.css
- public/index.php
- src/views/dashboard.php
- src/views/donations.php
- src/views/inventory.php
- src/views/beneficiaries.php
- src/views/purchases.php
- src/views/settings.php

## 10. New Page Checklist

新頁面上線前，必須全部通過:

- 使用全域色票與字體，不新增臨時顏色。
- 使用 view-header、card、btn、data-table 等既有樣式。
- 不可新增內嵌 style 區塊。
- 手機版 768px 以下可正常閱讀與點擊。
- 表格欄位過多時可水平滾動，不得擠壓成一欄。
- 主要操作按鈕明確，次要操作不搶焦點。

## 11. Future Evolution Rules

- 若需改版，先改 token，再改元件，不直接逐頁硬改。
- 新增元件要先定義語意與使用情境，再加入 CSS。
- 每次大改版更新本文件版本與日期。