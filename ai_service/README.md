# 本機高一致性影像辨識服務

服務只供 XAMPP 本機入口使用，不需要外部 API 金鑰。圖片會送到本機 Ollama
視覺模型，網站再以多輪一致性檢查篩掉數量不穩定的結果。

## 安裝

Windows PowerShell：

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r ai_service\requirements.txt
```

另外需先啟動 Ollama 並下載已設定的視覺模型。

## 啟動

```powershell
.\.venv\Scripts\Activate.ps1
uvicorn ai_service.app:app --host 127.0.0.1 --port 8001
```

健康檢查：

```powershell
curl.exe http://127.0.0.1:8001/health
```

## 精準度策略

- 修正 EXIF 方向、正規化色彩、保留最多 2048 像素邊長。
- 將全部照片視為同一批，要求模型避免把不同角度的同一組物資重複相加。
- 使用 PaddleOCR 讀取繁簡中文包裝文字，僅作為品名交叉核對，不參與數量計算。
- 第一次使用標準圖、第二次使用增強圖獨立辨識。
- 兩輪結果不同時自動加入第三輪保守稽核。
- 只有名稱、單位與數量達成門檻一致，結果才允許套用表單。
- 不一致或低信心結果保留為「需人工確認」，不會提供套用按鈕。

可用環境變數：

```text
FOODBANK_OLLAMA_URL=http://127.0.0.1:11434
FOODBANK_VISION_MODEL=gemma3:12b
FOODBANK_OLLAMA_TIMEOUT=90
FOODBANK_AI_MIN_CONFIDENCE=0.75
FOODBANK_AI_MAX_IMAGES=6
FOODBANK_OCR_ENABLED=true
FOODBANK_OCR_MIN_CONFIDENCE=0.6
```

即使啟用多輪共識，影像辨識仍不應宣稱 100% 正確。遮擋、鏡頭反光、
低解析度或不同照片拍攝同一批物資，仍需由捐贈者在送出前人工確認。

## 測試

在專案根目錄執行：

```powershell
python -m unittest discover -s tests -p "test_ai_service.py"
```
