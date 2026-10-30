# Vercel + Supabase 部署

本專案使用 Vercel Container Runtime 執行 PHP，Supabase 提供 PostgreSQL、session 儲存及圖片物件儲存。Container Runtime 目前為 Beta；部署前請先確認 Vercel 帳戶可使用此功能。

## 已準備的程式設定

- 根目錄的 `Dockerfile.vercel` 會以 Apache/PHP 8.3 啟動網站，並啟用 PostgreSQL、MySQL 相容及 cURL/mbstring 擴充。
- 設定 `DATABASE_URL` 後，應用程式使用 PostgreSQL；本機未設定此變數時仍使用 XAMPP MySQL。
- `DB_SCHEMA` 預設為 `shinigyi_foodbank`，與目前 pgloader 匯入的 schema 相同。
- PostgreSQL 模式將 PHP sessions 存入資料庫的 `app_sessions` 表。
- PostgreSQL 模式的新上傳使用 Supabase Storage；公用圖片與私有駕照分別存放。舊 XAMPP 上傳檔案不會由 pgloader 搬移，需另行匯入 Storage。

## Supabase 準備

1. 確認匯入結果在 `shinigyi_foodbank` schema，且保留 XAMPP 備份。
2. 在 Supabase Storage 建立 `foodbank-public` 公開 bucket，以及 `rider-licenses` 私有 bucket。駕照 bucket 不可設為公開。
3. 從 Supabase 專案設定取得專案 URL，以及只供伺服器使用的 Service Role Secret。不要放在 Git、前端程式或公開環境變數中。
4. 連線請在 Supabase **Connect** 選擇 PostgreSQL session pooler（通常為 5432）；不可使用 transaction pooler，因庫存流程需要 session advisory lock。使用 Supabase 提供的 SSL 連線資訊。

## Vercel 環境變數

在 Vercel Project Settings → Environment Variables 設定：

| 名稱 | 值 |
| --- | --- |
| `DATABASE_URL` | Supabase PostgreSQL session-pooler 連線字串（包含 SSL 設定） |
| `DB_SCHEMA` | `shinigyi_foodbank` |
| `APP_DEBUG` | `false` |
| `APP_URL` | 正式網站完整 HTTPS 網址 |
| `SUPABASE_URL` | Supabase 專案 URL |
| `SUPABASE_SERVICE_ROLE_KEY` | Supabase 伺服器端 Service Role Secret，設為 Sensitive |
| `SUPABASE_STORAGE_PUBLIC_BUCKET` | `foodbank-public` |
| `SUPABASE_STORAGE_PRIVATE_BUCKET` | `rider-licenses` |
| `GOOGLE_MAPS_API_KEY` | 若系統需要地圖功能，填入受限的 API key |

不要把 `DATABASE_URL` 或 Service Role Secret 貼在聊天、issue、前端或 Git。環境變數變更後需重新部署。

## 部署

1. 將程式推送至 GitHub，並在 Vercel 匯入 repository。
2. Vercel 會偵測根目錄 `Dockerfile.vercel`。不需要自行設定 `vercel.json`。
3. 加入上表環境變數後部署。Apache 會監聽 Vercel 提供的 `PORT`。
4. 首次請求會在 `shinigyi_foodbank` schema 建立 `app_sessions`。若資料庫角色沒有建表權限，需先由管理員在該 schema 建立此表。
5. 逐項驗證登入/session、角色權限、捐贈、配送、點數、庫存、報表、公開圖片上傳與私有駕照檢視。

## 上線前仍需完成的驗收

- 本機環境沒有 `pdo_pgsql`，所以程式尚未在此環境對 Supabase 執行完整功能測試。Docker image 建置、PHP lint 與本機 MySQL 回歸測試不能取代 PostgreSQL 整合測試。
- 確認資料表、序列、ENUM、索引與外鍵在 Supabase 的型態符合程式預期；若出現 SQL 錯誤，先在 Preview 部署驗證，不要直接以正式資料測試破壞性操作。
- 將原本 `public/uploads/` 與 `storage/rider_licenses/` 中需要保留的檔案，安全地匯入各自 bucket，並更新資料庫中的檔案路徑。不要把駕照照片設為公開。
- 檢查舊資料庫的預設帳號、密碼、Google API key 與其他 secrets；重設曾在終端輸出或聊天中曝光的資料庫密碼。
- 確認 session 表、資料庫備份及 Storage 權限設定；完成備份還原演練後才切換正式使用。

Vercel Hobby 有用途及用量限制，Container Runtime 也仍是 Beta；Supabase Free 有資源額度及閒置暫停條件。若系統用於機構正式營運或處理個資，先核對平台條款、服務額度、備份與可用性需求。
