# 本地影像辨識 Demo

此服務只供 localhost Demo 使用，不需要外部 API 金鑰。

## 安裝

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/SA/SA/foodbank-project
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements-ai.txt
```

## 啟動

```bash
source .venv/bin/activate
uvicorn ai_service.app:app --host 127.0.0.1 --port 8001
```

第一次辨識會下載 `yolov8n.pt`。生產環境可設定：

```bash
export FOODBANK_YOLO_MODEL=/absolute/path/to/custom-yolo.pt
export FOODBANK_AI_CONFIDENCE=0.35
```
