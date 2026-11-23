import base64
import io
import json
import os
import re
import threading
import urllib.error
import urllib.request

from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image, ImageEnhance, ImageOps, UnidentifiedImageError
from starlette.concurrency import run_in_threadpool

from ai_service.analysis import (
    analyses_agree,
    normalize_analysis,
    reconcile_analyses,
    refine_item_names_from_ocr,
)
from ai_service.ocr import extract_text_hints


app = FastAPI(title="Foodbank Local Material Vision")

OLLAMA_URL = os.getenv("FOODBANK_OLLAMA_URL", "http://127.0.0.1:11434").rstrip("/")
VISION_MODEL = os.getenv("FOODBANK_VISION_MODEL", "gemma4:latest")
OLLAMA_TIMEOUT = float(os.getenv("FOODBANK_OLLAMA_TIMEOUT", "90"))
MIN_RESULT_CONFIDENCE = float(os.getenv("FOODBANK_AI_MIN_CONFIDENCE", "0.95"))
MAX_IMAGES = int(os.getenv("FOODBANK_AI_MAX_IMAGES", "6"))
MAX_IMAGE_BYTES = 10 * 1024 * 1024
MAX_IMAGE_SIDE = 2048
MAX_IMAGE_PIXELS = 40_000_000
MAX_RESPONSE_ITEMS = 100
OCR_ENABLED = os.getenv("FOODBANK_OCR_ENABLED", "true").lower() not in {"0", "false", "no"}
_analysis_lock = threading.Lock()
_detected_model = None


def get_preferred_vision_model():
    global _detected_model
    configured = os.getenv("FOODBANK_VISION_MODEL")
    if configured:
        return configured
    if _detected_model:
        return _detected_model
    try:
        req = urllib.request.Request(f"{OLLAMA_URL}/api/tags")
        with urllib.request.urlopen(req, timeout=3) as response:
            data = json.loads(response.read().decode("utf-8"))
            installed_names = [m.get("name") for m in data.get("models", [])]
            for candidate in ["gemma4:latest", "llava:7b", "gemma3:12b"]:
                if candidate in installed_names:
                    _detected_model = candidate
                    return candidate
            for m in data.get("models", []):
                if "vision" in m.get("capabilities", []):
                    _detected_model = m.get("name")
                    return _detected_model
    except Exception:
        pass
    _detected_model = "gemma4:latest"
    return _detected_model

Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS

BASE_PROMPT = (
    "你是食物銀行物資盤點的高精度品管主任。請以最嚴謹的盤點標準，將所有照片視為同一批捐贈物資進行精準識別。"
    "【食品真確性檢驗】判斷 contains_donatable_food：只有照片中明確包含可食用的包裝食品、乾貨、罐頭、生鮮或飲品才為 true；"
    "若為人物、車輛、建築、儀表、文件、螢幕、空容器或無可食用物資之場景，必須設為 false 且 items 保持空陣列 []。"
    "【跨視角防重計】多張照片若為同一組物資之不同拍攝角度或局部特寫，必須合併判定，嚴禁重複疊加數量。"
    "【高精度計數與盤點規範】"
    "1) 空間定位盤點：由前向後、由左至右逐排清點實體物件，建立精確視覺定位，不遺漏、不虛報；"
    "2) 單位與包裝層級：封箱完整且標有箱裝數量的整箱物資單位請用「箱」；已開箱或散裝擺放的物資，請清點獨立實體（罐、瓶、包、盒、袋、顆）；不可同時計算外箱又累計箱內散裝；"
    "3) 個體計數精度：罐頭（罐）、瓶裝水/油（瓶）、袋裝米/粉/麵（包或袋）、利樂包/紙盒食品（盒）、散裝雞蛋（顆，露出一半以上實體才計，嚴禁把空格、包裝圖案、倒影算入）；"
    "4) 遮擋與雜物排除：被完全遮蔽、模糊無法確認、反光倒影、包裝空袋、提袋、瓦楞紙箱本體等非食品，一律嚴格排除；"
    "5) 品名精確度：物資名稱請使用精準繁體中文（如「白米」、「燕麥片」、「鮪魚罐頭」、「麵條」、「保久乳」、「食用油」），若有文字標示，請提取其主要物資名稱；"
    "6) 信心度客觀評定：畫面清晰、數量輪廓完全明確時給予 0.95~1.00；若有輕度反光或角度遮擋但可確認物資時給予 0.85~0.94；難以確定者直接排除不猜測。"
    "最後請回覆嚴格 JSON 格式："
    '{"contains_donatable_food":布林值,'
    '"items":[{"name":"中文品名","quantity":正整數,"unit":"標準單位"}],'
    '"confidence":0到1的小數}。'
)

ANALYSIS_PROMPTS = (
    BASE_PROMPT + "【第 1 輪：標準全景細緻盤點】請仔細清點每一項可見物資的正確名稱、數量與單位。",
    BASE_PROMPT + "【第 2 輪：邊緣對比反向查核】請由不同視角/邊緣獨立重新查核，嚴格剔除陰影、反光、重疊與非食品物件。",
    BASE_PROMPT + "【第 3 輪：最保守一致性稽核】僅確認前兩輪完全吻合且邊界清晰無爭議的物資品項與最終數量。",
)

RESPONSE_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["contains_donatable_food", "items", "confidence"],
    "properties": {
        "contains_donatable_food": {"type": "boolean"},
        "items": {
            "type": "array",
            "maxItems": MAX_RESPONSE_ITEMS,
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["name", "quantity", "unit"],
                "properties": {
                    "name": {"type": "string", "minLength": 1, "maxLength": 80},
                    "quantity": {"type": "integer", "minimum": 1, "maximum": 10000},
                    "unit": {
                        "type": "string",
                        "enum": ["顆", "盒", "罐", "包", "袋", "瓶", "箱", "份", "件", "組"],
                    },
                },
            },
        },
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    },
}


class VisionModelError(RuntimeError):
    pass


def _encode_jpeg(image):
    output = io.BytesIO()
    image.save(output, format="JPEG", quality=95, optimize=True)
    return output.getvalue()


def prepare_image(image_bytes):
    if not image_bytes or len(image_bytes) > MAX_IMAGE_BYTES:
        raise ValueError("照片大小無效或超過 10MB")

    try:
        with Image.open(io.BytesIO(image_bytes)) as source:
            source.load()
            corrected = ImageOps.exif_transpose(source)
            if corrected.mode == "RGBA":
                background = Image.new("RGB", corrected.size, "white")
                background.paste(corrected, mask=corrected.getchannel("A"))
                corrected = background
            elif corrected.mode != "RGB":
                corrected = corrected.convert("RGB")
            else:
                corrected = corrected.copy()
    except Image.DecompressionBombError as error:
        raise ValueError("照片像素過大") from error
    except (OSError, UnidentifiedImageError) as error:
        raise ValueError("照片不是可讀取的影像") from error

    width, height = corrected.size
    if width < 64 or height < 64:
        raise ValueError("照片解析度過低，長與寬至少需要 64 像素")
    if width * height > MAX_IMAGE_PIXELS:
        raise ValueError("照片像素過大")

    scale = min(1.0, MAX_IMAGE_SIDE / max(width, height))
    if scale < 1:
        corrected = corrected.resize(
            (round(width * scale), round(height * scale)),
            Image.Resampling.LANCZOS,
        )

    contrast_balanced = ImageOps.autocontrast(corrected, cutoff=0.5)
    enhanced = ImageEnhance.Sharpness(contrast_balanced).enhance(1.20)
    enhanced = ImageEnhance.Contrast(enhanced).enhance(1.08)

    shadow_boost = ImageEnhance.Brightness(corrected).enhance(1.12)
    shadow_boost = ImageEnhance.Contrast(shadow_boost).enhance(1.05)
    shadow_boost = ImageEnhance.Sharpness(shadow_boost).enhance(1.15)

    return {
        "original": image_bytes,
        "standard": _encode_jpeg(corrected),
        "enhanced": _encode_jpeg(enhanced),
        "shadow_boost": _encode_jpeg(shadow_boost),
        "width": corrected.width,
        "height": corrected.height,
    }


def _ocr_prompt(hints):
    if not hints:
        return ""
    rendered = ", ".join(
        f"{hint['text']}（{hint['confidence']:.0%}）"
        for hint in hints
    )
    return (
        " 包裝文字由獨立 OCR 取得，僅作為品名核對提示："
        f"{rendered}。"
        "必須同時符合圖中內容與外觀才可使用；不可把 OCR 雜訊、品牌、重量或廣告文字當成物資名稱。"
    )


def _safe_parse_json(text):
    clean = (text or "").strip()
    if "```" in clean:
        match = re.search(r"```(?:json)?\s*([\s\S]*?)\s*```", clean)
        if match:
            clean = match.group(1).strip()
    start = clean.find("{")
    end = clean.rfind("}")
    if start != -1 and end != -1 and end > start:
        clean = clean[start : end + 1]
    return json.loads(clean)


def analyze_with_ollama(image_variants, pass_index, ocr_hints):
    model = get_preferred_vision_model()
    payload = json.dumps({
        "model": model,
        "system": "You are a precise foodbank inventory engine. Output strict JSON only matching the schema. Do not output any thoughts, reasoning, markdown or explanation.",
        "prompt": ANALYSIS_PROMPTS[pass_index] + _ocr_prompt(ocr_hints),
        "images": [
            base64.b64encode(image_bytes).decode("ascii")
            for image_bytes in image_variants
        ],
        "stream": False,
        "format": RESPONSE_SCHEMA,
        "options": {
            "temperature": (0.0, 0.0, 0.05)[pass_index],
            "num_predict": 1000,
            "repeat_penalty": 1.08,
            "top_k": 20,
            "top_p": 0.9,
        },
    }).encode("utf-8")
    request = urllib.request.Request(
        OLLAMA_URL + "/api/generate",
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=OLLAMA_TIMEOUT) as response:
            result = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        raise VisionModelError(f"Ollama 回覆 HTTP {error.code}") from error
    except urllib.error.URLError as error:
        raise VisionModelError("無法連線到本機 Ollama") from error
    except TimeoutError as error:
        raise VisionModelError("Ollama 分析逾時") from error
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise VisionModelError("Ollama 回覆格式無效") from error

    if not isinstance(result, dict):
        raise VisionModelError("Ollama 回覆無效")

    if result.get("error"):
        raise VisionModelError(f"Ollama 模型錯誤：{result['error']}")

    response_text = result.get("response") or ""
    if not response_text.strip() and result.get("thinking"):
        response_text = result["thinking"]

    if not response_text.strip():
        raise VisionModelError("Ollama 未回傳可解析的辨識文字")

    try:
        parsed = _safe_parse_json(response_text)
        return normalize_analysis(parsed)
    except (json.JSONDecodeError, ValueError) as error:
        raise VisionModelError(f"視覺模型未依要求回傳有效 JSON ({error})") from error


def analyze_batch(prepared_images):
    with _analysis_lock:
        original_images = [image["original"] for image in prepared_images]
        standard_images = [image["standard"] for image in prepared_images]
        enhanced_images = [image["enhanced"] for image in prepared_images]
        shadow_images = [image.get("shadow_boost", image["standard"]) for image in prepared_images]
        variants = (standard_images, enhanced_images, shadow_images)
        passes = []
        errors = []
        ocr_hints = []

        if OCR_ENABLED:
            for index, image_bytes in enumerate(original_images, start=1):
                try:
                    for hint in extract_text_hints(image_bytes):
                        hint["image_index"] = index
                        ocr_hints.append(hint)
                except (ImportError, OSError, RuntimeError, ValueError) as error:
                    errors.append(f"OCR 第 {index} 張照片失敗：{error}")

        for pass_index in range(3):
            try:
                passes.append(analyze_with_ollama(
                    variants[pass_index],
                    pass_index,
                    ocr_hints,
                ))
            except VisionModelError as error:
                errors.append(str(error))
                if len(passes) == 2:
                    break
                continue

            if len(passes) == 2:
                agreed = analyses_agree(passes[0], passes[1])
                is_empty = not passes[0].get("items") and not passes[1].get("items")
                high_conf = min(item["confidence"] for item in passes) >= 0.65
                if agreed and (is_empty or high_conf):
                    break

        if len(passes) < 2:
            raise VisionModelError("；".join(dict.fromkeys(errors)) or "模型未完成足夠的獨立辨識")

        items, uncertain = reconcile_analyses(
            passes,
            minimum_confidence=MIN_RESULT_CONFIDENCE,
        )
        items = refine_item_names_from_ocr(items, ocr_hints)
        uncertain = refine_item_names_from_ocr(uncertain, ocr_hints)
        return items, uncertain, len(passes), errors


@app.on_event("startup")
def startup_warmup():
    if OCR_ENABLED:
        try:
            from ai_service.ocr import get_ocr
            get_ocr()
        except Exception as e:
            pass


@app.get("/health")
def health():
    return {
        "status": "ok",
        "model": get_preferred_vision_model(),
        "max_images": MAX_IMAGES,
        "minimum_confidence": MIN_RESULT_CONFIDENCE,
        "ocr_enabled": OCR_ENABLED,
        "requires_unanimous_agreement": True,
        "requires_human_confirmation": True,
    }


@app.post("/analyze")
async def analyze(files: list[UploadFile] = File(...)):
    if len(files) > MAX_IMAGES:
        return JSONResponse(
            status_code=400,
            content={"error": f"一次最多可分析 {MAX_IMAGES} 張照片"},
        )

    prepared_images = []
    image_errors = []
    for upload in files:
        if upload.content_type not in {"image/jpeg", "image/png", "image/webp"}:
            image_errors.append(f"{upload.filename or '照片'}：格式不支援")
            continue
        image_bytes = await upload.read(MAX_IMAGE_BYTES + 1)
        try:
            prepared_images.append(prepare_image(image_bytes))
        except ValueError as error:
            image_errors.append(f"{upload.filename or '照片'}：{error}")

    if not prepared_images:
        return JSONResponse(
            status_code=422,
            content={"error": "沒有可分析的照片", "errors": image_errors},
        )

    model_name = get_preferred_vision_model()
    try:
        items, uncertain, pass_count, model_errors = await run_in_threadpool(
            analyze_batch,
            prepared_images,
        )
    except VisionModelError as error:
        return JSONResponse(
            status_code=503,
            content={
                "items": [],
                "uncertain_items": [],
                "error": str(error),
                "errors": image_errors,
                "model": model_name,
            },
        )

    return JSONResponse({
        "items": items,
        "uncertain_items": uncertain,
        "model": model_name,
        "passes": pass_count,
        "minimum_confidence": MIN_RESULT_CONFIDENCE,
        "requires_unanimous_agreement": True,
        "requires_human_confirmation": True,
        "image_count": len(prepared_images),
        "errors": image_errors + model_errors,
    })
