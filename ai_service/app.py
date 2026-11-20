import io
import os
from collections import defaultdict

from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image


app = FastAPI(title="Foodbank Local Material Vision Demo")

MODEL_NAME = os.getenv("FOODBANK_YOLO_MODEL", "yolov8n.pt")
CONFIDENCE = float(os.getenv("FOODBANK_AI_CONFIDENCE", "0.35"))
_model = None


def get_model():
    global _model
    if _model is None:
        from ultralytics import YOLO
        _model = YOLO(MODEL_NAME)
    return _model


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME}


@app.post("/analyze")
async def analyze(files: list[UploadFile] = File(...)):
    model = get_model()
    grouped = defaultdict(lambda: {"quantity": 0, "confidence_total": 0.0})
    detections = []

    for upload in files:
        image_bytes = await upload.read()
        if not image_bytes:
            continue

        image = Image.open(io.BytesIO(image_bytes)).convert("RGB")
        results = model.predict(image, conf=CONFIDENCE, verbose=False)

        for result in results:
            names = result.names
            boxes = result.boxes
            for box in boxes:
                confidence = float(box.conf[0])
                class_id = int(box.cls[0])
                name = str(names[class_id])
                x1, y1, x2, y2 = [round(float(value), 1) for value in box.xyxy[0].tolist()]
                detections.append({
                    "name": name,
                    "confidence": round(confidence, 3),
                    "box": [x1, y1, x2, y2],
                    "source": upload.filename,
                })
                grouped[name]["quantity"] += 1
                grouped[name]["confidence_total"] += confidence

    items = []
    for name, values in grouped.items():
        items.append({
            "name": name,
            "quantity": values["quantity"],
            "unit": "件",
            "confidence": round(values["confidence_total"] / values["quantity"], 3),
        })

    items.sort(key=lambda item: (-item["quantity"], item["name"]))
    return JSONResponse({
        "items": items,
        "detections": detections,
        "model": MODEL_NAME,
    })
