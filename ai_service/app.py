import base64
import io
import json
import os
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
VISION_MODEL = os.getenv("FOODBANK_VISION_MODEL", "gemma3:12b")
OLLAMA_TIMEOUT = float(os.getenv("FOODBANK_OLLAMA_TIMEOUT", "90"))
MIN_RESULT_CONFIDENCE = float(os.getenv("FOODBANK_AI_MIN_CONFIDENCE", "0.75"))
MAX_IMAGES = int(os.getenv("FOODBANK_AI_MAX_IMAGES", "6"))
MAX_IMAGE_BYTES = 10 * 1024 * 1024
MAX_IMAGE_SIDE = 2048
MAX_IMAGE_PIXELS = 40_000_000
MAX_RESPONSE_ITEMS = 100
OCR_ENABLED = os.getenv("FOODBANK_OCR_ENABLED", "true").lower() not in {"0", "false", "no"}
_analysis_lock = threading.Lock()

Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS

BASE_PROMPT = (
    "你是食物銀行物資盤點品管員。把所有照片視為同一批捐贈物資，只辨識可直接看到的實體物資。"
    "先判斷 contains_donatable_food：只有實際出現可食用食品、乾貨或包裝飲品才是 true；"
    "人物、車輛、儀錶、文件、螢幕、建築物或其他非食品為 false，且 items 必須是空陣列。"
    "若多張照片是同一組物資的不同角度，只能計一次，不可把各張照片相加。"
    "逐一建立視覺對象清單後再輸出，不要只描述場景。"
    "規則："
    "1) 包裝盒、包裝袋、罐頭、瓶子各自以外觀完整且可分辨的個體計數；"
    "2) 雞蛋逐顆數，完整或露出大部分蛋殼才算，不能把蛋盒格、蛋的倒影、印刷圖案算進去；"
    "3) 牛奶盒逐盒數，只數獨立容器，不把品牌文字或廣告圖當物資；"
    "4) 印刷品、照片、背景、人、倒影、鏡像與遮蔽後看不到的物件都不計；"
    "5) 看不到完整輪廓、無法確認類別或數量時，不要猜，直接省略；"
    "6) 物資名稱使用不含品牌的簡短中文，單位只可用顆、盒、罐、包、袋、瓶、箱、份、件、組。"
    "最後以嚴格 JSON 回覆，格式為"
    '{"contains_donatable_food":布林值,'
    '"items":[{"name":"中文物資名稱","quantity":正整數,"unit":"件"}],'
    '"confidence":0到1的小數}。'
)

ANALYSIS_PROMPTS = (
    BASE_PROMPT + " 請進行第一輪精細盤點。",
    BASE_PROMPT + " 請獨立從零重新判定，不沿用任何既有答案，並優先排除不確定物件。",
    BASE_PROMPT + " 請做最保守的第三輪稽核，只保留跨角度可確認、輪廓不重疊且數量明確的物資。",
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

    enhanced = ImageEnhance.Contrast(corrected).enhance(1.06)
    enhanced = ImageEnhance.Sharpness(enhanced).enhance(1.08)
    return {
        "original": image_bytes,
        "standard": _encode_jpeg(corrected),
        "enhanced": _encode_jpeg(enhanced),
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


def analyze_with_ollama(image_variants, pass_index, ocr_hints):
    payload = json.dumps({
        "model": VISION_MODEL,
        "prompt": ANALYSIS_PROMPTS[pass_index] + _ocr_prompt(ocr_hints),
        "images": [
            base64.b64encode(image_bytes).decode("ascii")
            for image_bytes in image_variants
        ],
        "stream": False,
        "format": RESPONSE_SCHEMA,
        "options": {
            "temperature": (0.0, 0.05, 0.1)[pass_index],
            "num_predict": 700,
            "repeat_penalty": 1.05,
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

    if not isinstance(result, dict) or not isinstance(result.get("response"), str):
        if isinstance(result, dict) and result.get("error"):
            raise VisionModelError("Ollama 模型拒絕請求")
        raise VisionModelError("Ollama 未回傳辨識結果")

    try:
        return normalize_analysis(json.loads(result["response"]))
    except (json.JSONDecodeError, ValueError) as error:
        raise VisionModelError("視覺模型未依要求回傳有效 JSON") from error


def analyze_batch(prepared_images):
    with _analysis_lock:
        original_images = [image["original"] for image in prepared_images]
        standard_images = [image["standard"] for image in prepared_images]
        enhanced_images = [image["enhanced"] for image in prepared_images]
        variants = (standard_images, enhanced_images, standard_images)
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
                if analyses_agree(passes[0], passes[1]) and min(
                    item["confidence"] for item in passes
                ) >= 0.65:
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


@app.get("/health")
def health():
    return {
        "status": "ok",
        "model": VISION_MODEL,
        "max_images": MAX_IMAGES,
        "minimum_confidence": MIN_RESULT_CONFIDENCE,
        "ocr_enabled": OCR_ENABLED,
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
                "model": VISION_MODEL,
            },
        )

    return JSONResponse({
        "items": items,
        "uncertain_items": uncertain,
        "model": VISION_MODEL,
        "passes": pass_count,
        "minimum_confidence": MIN_RESULT_CONFIDENCE,
        "image_count": len(prepared_images),
        "errors": image_errors + model_errors,
    })
