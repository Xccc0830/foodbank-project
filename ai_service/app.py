import io
import base64
import json
import os
import urllib.request
import urllib.error
from collections import defaultdict

from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image


app = FastAPI(title="Foodbank Local Material Vision Demo")

MODEL_NAME = os.getenv("FOODBANK_YOLO_MODEL", "yolov8n.pt")
CONFIDENCE = float(os.getenv("FOODBANK_AI_CONFIDENCE", "0.35"))
OLLAMA_URL = os.getenv("FOODBANK_OLLAMA_URL", "http://127.0.0.1:11434")
VISION_MODEL = os.getenv("FOODBANK_VISION_MODEL", "gemma4:latest")
_model = None


def get_model():
    global _model
    if _model is None:
        from ultralytics import YOLO
        _model = YOLO(MODEL_NAME)
    return _model


@app.get("/health")
def health():
    return {"status": "ok", "model": VISION_MODEL, "fallback_model": MODEL_NAME}


def analyze_with_ollama(image_bytes):
    prompt = (
        "你是食物銀行物資盤點助手。只分析圖片中的捐贈物資，不要描述背景或包裝品牌。"
        "請回傳嚴格 JSON，不要 Markdown。格式是 "
        '{"items":[{"name":"中文物資名稱","quantity":數字,"unit":"件"}],"confidence":0到1的數字}。'
        "先逐一數完所有可見物件，再回傳數量，不要只描述場景。若圖片呈現 8 盒牛奶，就回 quantity 8、name「牛奶」、unit「盒」。"
        "未看清楚或不是捐贈物資時 quantity=0，不要猜品牌或商品。"
    )
    payload = json.dumps({
        "model": VISION_MODEL,
        "prompt": prompt,
        "images": [base64.b64encode(image_bytes).decode("ascii")],
        "stream": False,
        "format": "json",
        "options": {"temperature": 0.1},
    }).encode("utf-8")
    request = urllib.request.Request(
        OLLAMA_URL.rstrip("/") + "/api/generate",
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=90) as response:
        result = json.loads(response.read().decode("utf-8"))
    return json.loads(result.get("response", "{}"))


@app.post("/analyze")
async def analyze(files: list[UploadFile] = File(...)):
    grouped = defaultdict(lambda: {"quantity": 0, "confidence_total": 0.0, "record_count": 0})
    detections = []
    items = []
    vision_errors = []
    for upload in files:
        image_bytes = await upload.read()
        if not image_bytes:
            continue
        try:
            vision = analyze_with_ollama(image_bytes)
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, KeyError) as error:
            vision_errors.append(str(error))
            continue

        overall_confidence = float(vision.get("confidence", 0.75))
        for detected in vision.get("items", []):
            name = str(detected.get("name", "")).strip()
            quantity = int(detected.get("quantity", 0) or 0)
            unit = str(detected.get("unit", "件")).strip() or "件"
            if name == "" or quantity <= 0:
                continue
            grouped[name]["quantity"] += quantity
            grouped[name]["confidence_total"] += max(0.0, min(1.0, overall_confidence))
            grouped[name]["record_count"] += 1
            grouped[name]["unit"] = unit

    for name, values in grouped.items():
        items.append({
            "name": name,
            "quantity": values["quantity"],
            "unit": values["unit"],
            "confidence": round(values["confidence_total"] / max(1, values["record_count"]), 3),
        })

    items.sort(key=lambda item: (-item["quantity"], item["name"]))
    return JSONResponse({
        "items": items,
        "detections": detections,
        "model": VISION_MODEL,
        "fallback_model": MODEL_NAME,
        "errors": vision_errors,
    })
