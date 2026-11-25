import base64
import io
import json
import os
import re
import threading
import urllib.error
import urllib.request

import numpy as np
from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image, ImageEnhance, ImageOps, UnidentifiedImageError
from starlette.concurrency import run_in_threadpool

from ai_service.analysis import (
    analyses_agree,
    normalize_analysis,
    reconcile_analyses,
    refine_item_names_from_ocr,
    suggest_donation_fields,
)
from ai_service.ocr import extract_text_hints


app = FastAPI(title="Foodbank Local Material Vision")

OLLAMA_URL = os.getenv("FOODBANK_OLLAMA_URL", "http://127.0.0.1:11434").rstrip("/")
VISION_MODEL = os.getenv("FOODBANK_VISION_MODEL", "gemma3:12b")
FAST_VISION_MODEL = os.getenv("FOODBANK_FAST_VISION_MODEL", "gemma3:4b")
DEFAULT_FAST_VISION_MODEL = "gemma3:4b"
OLLAMA_TIMEOUT = float(os.getenv("FOODBANK_OLLAMA_TIMEOUT", "90"))
FAST_OLLAMA_TIMEOUT = float(os.getenv("FOODBANK_FAST_OLLAMA_TIMEOUT", "45"))
OLLAMA_KEEP_ALIVE = os.getenv("FOODBANK_OLLAMA_KEEP_ALIVE", "30m")
MIN_RESULT_CONFIDENCE = float(os.getenv("FOODBANK_AI_MIN_CONFIDENCE", "0.95"))
MAX_IMAGES = int(os.getenv("FOODBANK_AI_MAX_IMAGES", "6"))
MAX_ANALYSIS_PASSES = int(os.getenv("FOODBANK_AI_MAX_PASSES", "3"))
FAST_ANALYSIS_PASSES = 2
MAX_IMAGE_BYTES = 10 * 1024 * 1024
MAX_IMAGE_SIDE = 2048
MAX_IMAGE_PIXELS = 40_000_000
MAX_RESPONSE_ITEMS = 100
OCR_ENABLED = os.getenv("FOODBANK_OCR_ENABLED", "true").lower() not in {"0", "false", "no"}
_analysis_lock = threading.Lock()
_detected_model = None
_detected_fast_model = None


def get_installed_vision_models():
    try:
        req = urllib.request.Request(f"{OLLAMA_URL}/api/tags")
        with urllib.request.urlopen(req, timeout=3) as response:
            data = json.loads(response.read().decode("utf-8"))
    except (OSError, ValueError):
        return []
    if not isinstance(data, dict) or not isinstance(data.get("models"), list):
        return []
    return [model for model in data["models"] if isinstance(model, dict)]


def find_installed_vision_model(candidates):
    installed = get_installed_vision_models()
    installed_names = [model.get("name") for model in installed]
    for candidate in candidates:
        if candidate in installed_names:
            return candidate
    for model in installed:
        capabilities = model.get("capabilities")
        if isinstance(capabilities, list) and "vision" in capabilities:
            return model.get("name")
    return None


def get_preferred_vision_model():
    global _detected_model
    configured = os.getenv("FOODBANK_VISION_MODEL")
    if configured:
        return configured
    if _detected_model:
        return _detected_model
    _detected_model = find_installed_vision_model(
        ["gemma3:12b", "gemma4:latest", "llava:7b"]
    )
    return _detected_model or VISION_MODEL


def get_preferred_fast_vision_model():
    global _detected_fast_model
    configured = os.getenv("FOODBANK_FAST_VISION_MODEL")
    if configured:
        return configured
    if FAST_VISION_MODEL != DEFAULT_FAST_VISION_MODEL:
        return FAST_VISION_MODEL
    if _detected_fast_model:
        return _detected_fast_model
    _detected_fast_model = find_installed_vision_model(
        ["gemma3:4b", "gemma4:latest", "llava:7b"]
    )
    return _detected_fast_model or FAST_VISION_MODEL


if MAX_ANALYSIS_PASSES not in {2, 3}:
    raise ValueError("FOODBANK_AI_MAX_PASSES 必須是 2 或 3")

Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS

BASE_PROMPT = (
    "你是食物銀行物資盤點的高精度品管主任。請以最嚴謹的盤點標準，將所有照片視為同一批捐贈物資進行精準識別。"
    "【食品真確性檢驗】判斷 contains_donatable_food：只要照片中明確包含可食用的包裝食品、乾貨、罐頭、生鮮食材、烘焙食品（如麵包、餐包、吐司）或飲品，即為 true；"
    "若為人物、車輛、建築、儀表、文件、螢幕、空容器或無可食用物資之場景，必須設為 false 且 items 保持空陣列 []。"
    "【重要品類規範】"
    "1) 雞蛋：蛋盒只是容器，只要看到蛋體就列出「雞蛋」，單位用「顆」；由左到右逐排（含前後排）逐顆計數，沒有看到雞蛋時不可憑空列出。"
    "2) 麵包／餐包：烤盤或架上的散裝麵包／小餐包屬於可捐贈烘焙食品，品名請用「麵包」或「餐包」，單位用「顆」，逐排清點每顆實體（邊緣露出一半以上也計入）。"
    "3) 鮮乳／牛奶：紙盒裝或塑膠瓶裝的液態鮮乳／牛奶／保久乳，請統一標記品名為「牛奶」（不可誤寫為「乳粉」或「奶粉」）；若整批皆為鮮乳（含盒裝與瓶裝），請合併計算總數量。"
    "4) 多品項合照：請逐一讀取包裝上的繁體中文品名（如「白米」、「麵條」、「燕麥片」、「餅乾」、「肉醬罐頭」、「牛奶」），未在畫面中出現的品項嚴禁憑空猜測。"
    "【跨視角防重計】多張照片若為同一組物資之不同拍攝角度或局部特寫，必須合併判定，嚴禁重複疊加數量。"
    "【高精度計數與盤點規範】"
    "1) 空間定位盤點：由前向後、由左至右逐排清點實體物件，建立精確視覺定位，不遺漏、不虛報；"
    "2) 單位與包裝層級：封箱完整且標有箱裝數量的整箱物資單位請用「箱」；已開箱或散裝擺放的物資，請清點獨立實體（罐、瓶、包、盒、袋、顆）；不可同時計算外箱又累計箱內散裝；"
    "3) 個體計數精度：罐頭（罐）、瓶裝水/油（瓶）、袋裝米/粉/麵（包或袋）、利樂包/紙盒食品（盒）、散裝雞蛋或餐包（顆，露出一半以上實體才計，嚴禁把空格、包裝圖案、倒影算入）；"
    "4) 遮擋與雜物排除：被完全遮蔽、模糊無法確認、反光倒影、包裝空袋、提袋、瓦楞紙箱本體等非食品，一律嚴格排除；"
    "5) 品名精確度：物資名稱請使用精準繁體中文（如「白米」、「燕麥片」、「鮪魚罐頭」、「肉醬罐頭」、「麵條」、「牛奶」、「麵包」、「雞蛋」、「餅乾」），若有文字標示，請提取其主要物資名稱；"
    "6) 保存與包裝建議：依物資特性提供 storage（room=常溫, chilled=冷藏, frozen=冷凍）與 package_status（sealed=原包裝未拆封, opened=已拆封或無包裝散裝）；"
    "7) 信心度客觀評定：畫面清晰、數量輪廓完全明確時給予 0.95~1.00；若有輕度反光或角度遮擋但可確認物資時給予 0.85~0.94；難以確定者直接排除不猜測。"
    "最後只回覆一個符合 JSON schema 的 JSON 物件，不要輸出說明或 Markdown。"
    "回覆範例："
    '{"contains_donatable_food":true,"items":[{"name":"白米","quantity":2,"unit":"包","storage":"room","package_status":"sealed"}],"confidence":0.97}'
    "；沒有可確認食品時回覆："
    '{"contains_donatable_food":false,"items":[],"confidence":0.0}。'
)

ANALYSIS_PROMPTS = (
    BASE_PROMPT + "【第 1 輪：標準全景細緻盤點】請仔細清點每一項可見物資的正確名稱、數量、單位與保存／包裝建議。",
    BASE_PROMPT + "【第 2 輪：獨立複核計數】請獨立重新清點每項實體食品的名稱、數量與單位，確認沒有漏算（如後排雞蛋或邊緣品項）也沒有把倒影或空位算入。",
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
                    "storage": {
                        "type": "string",
                        "enum": ["room", "chilled", "frozen"],
                    },
                    "package_status": {
                        "type": "string",
                        "enum": ["sealed", "opened"],
                    },
                },
            },
        },
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    },
}

PACKAGE_COUNT_LABELS = {
    "牛奶": "milk packages (cartons or bottles)",
    "白米": "rice packages (bags or boxes)",
    "泡麵": "instant noodle packages",
    "麵條": "noodle packages",
    "米粉": "rice noodle packages",
    "冬粉": "glass noodle packages",
    "燕麥片": "oatmeal packages",
    "餅乾": "cookie packages",
    "奶粉": "milk powder packages",
    "食用油": "cooking oil bottles",
    "醬油": "soy sauce bottles",
    "鮪魚罐頭": "cans of tuna",
    "麵包": "bread buns or rolls",
}

PACKAGE_COUNT_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["count"],
    "properties": {
        "count": {"type": "integer", "minimum": 0, "maximum": 10000},
    },
}


class VisionModelError(RuntimeError):
    pass


class InvalidVisionResponseError(VisionModelError):
    pass


def _encode_jpeg(image):
    output = io.BytesIO()
    image.save(output, format="JPEG", quality=95, optimize=True)
    return output.getvalue()


def _find_reflection_boundary(image):
    if image.height < 128:
        return None

    scale = min(1.0, 256 / image.width)
    sample = image.resize(
        (round(image.width * scale), round(image.height * scale)),
        Image.Resampling.BILINEAR,
    )
    pixels = np.asarray(sample.convert("L"), dtype=np.float32)
    height = pixels.shape[0]
    patch_height = max(8, round(height * 0.12))
    start = max(patch_height, round(height * 0.5))
    end = min(height - patch_height, round(height * 0.9))
    correlations = []

    for boundary in range(start, end):
        above = pixels[boundary - patch_height:boundary]
        below = pixels[boundary:boundary + patch_height][::-1]
        above = above - above.mean()
        below = below - below.mean()
        denominator = above.std() * below.std()
        correlation = float(np.mean(above * below) / denominator) if denominator else 0
        correlations.append((correlation, boundary))

    if not correlations:
        return None

    best_correlation, best_boundary = max(correlations)
    median_correlation = float(np.median([score for score, _ in correlations]))
    if best_correlation < 0.72 or best_correlation - median_correlation < 0.10:
        return None

    return round(best_boundary / height * image.height)


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

    reflection_boundary = _find_reflection_boundary(corrected)
    if reflection_boundary is not None:
        corrected = corrected.crop((0, 0, corrected.width, reflection_boundary))

    contrast_balanced = ImageOps.autocontrast(corrected, cutoff=0.5)
    enhanced = ImageEnhance.Sharpness(contrast_balanced).enhance(1.20)
    enhanced = ImageEnhance.Contrast(enhanced).enhance(1.08)

    shadow_boost = ImageEnhance.Brightness(corrected).enhance(1.12)
    shadow_boost = ImageEnhance.Contrast(shadow_boost).enhance(1.05)
    shadow_boost = ImageEnhance.Sharpness(shadow_boost).enhance(1.15)

    return {
        "original": image_bytes,
        "analysis_original": _encode_jpeg(corrected),
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


def _request_ollama(payload, timeout):
    request = urllib.request.Request(
        OLLAMA_URL + "/api/generate",
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            result = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        detail = ""
        try:
            error_result = json.loads(error.read().decode("utf-8"))
            if isinstance(error_result, dict):
                detail = str(error_result.get("error") or "")
        except (UnicodeDecodeError, json.JSONDecodeError):
            pass
        message = f"Ollama 回覆 HTTP {error.code}"
        if detail:
            message += f"：{detail}"
        raise VisionModelError(message) from error
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

    response_text = result.get("response")
    if not isinstance(response_text, str) or not response_text.strip():
        raise InvalidVisionResponseError("Ollama 未回傳可解析的辨識文字")

    try:
        return _safe_parse_json(response_text)
    except (json.JSONDecodeError, ValueError) as error:
        raise InvalidVisionResponseError(
            f"視覺模型未回傳有效 JSON ({error})"
        ) from error


def analyze_with_ollama(
    image_variants,
    pass_index,
    ocr_hints,
    vision_model=None,
    timeout=None,
):
    vision_model = vision_model or get_preferred_vision_model()
    timeout = timeout or OLLAMA_TIMEOUT
    payload = {
        "model": vision_model,
        "system": "You are a precise foodbank inventory engine. Output strict JSON only matching the schema. Do not output any thoughts, reasoning, markdown or explanation.",
        "prompt": ANALYSIS_PROMPTS[pass_index] + _ocr_prompt(ocr_hints),
        "images": [
            base64.b64encode(image_bytes).decode("ascii")
            for image_bytes in image_variants
        ],
        "stream": False,
        "think": False,
        "keep_alive": OLLAMA_KEEP_ALIVE,
        "format": RESPONSE_SCHEMA,
        "options": {
            "temperature": (0.0, 0.0, 0.05)[pass_index],
            "num_predict": 1000,
            "repeat_penalty": 1.08,
            "top_k": 20,
            "top_p": 0.9,
        },
    }
    for attempt in range(2):
        request_payload = payload
        if attempt:
            request_payload = {
                **payload,
                "prompt": payload["prompt"] + (
                    "\n上一個回覆不是有效 JSON。請重新檢視圖片，只輸出一個符合 schema "
                    "的 JSON 物件；布林值使用 true/false，數量使用整數，不要有任何其他文字。"
                ),
            }
        try:
            response = _request_ollama(request_payload, timeout)
            return normalize_analysis(response)
        except (InvalidVisionResponseError, ValueError) as error:
            if attempt == 0:
                continue
            raise VisionModelError(f"格式修正重試後仍失敗：{error}") from error


def count_item_packages(image_variants, item_name, vision_model=None, timeout=None):
    label = PACKAGE_COUNT_LABELS.get(item_name)
    if not label:
        raise ValueError(f"尚未設定「{item_name}」的獨立數量辨識名稱")

    prompt = (
        f"Count the separate physical {label} visible in THIS image crop. "
        "Count each real package body once; ignore all printed package pictures, "
        "logos, text, reflections, and labels. Do not estimate from brands. "
    )
    if len(image_variants) > 1:
        prompt += "If multiple views show the same batch, do not count a package twice. "
    prompt += "Reply only JSON with integer total count."
    payload = {
        "model": vision_model or get_preferred_vision_model(),
        "prompt": prompt,
        "images": [
            base64.b64encode(image_bytes).decode("ascii")
            for image_bytes in image_variants
        ],
        "stream": False,
        "think": False,
        "keep_alive": OLLAMA_KEEP_ALIVE,
        "format": PACKAGE_COUNT_SCHEMA,
        "options": {
            "temperature": 0,
            "num_predict": 100,
        },
    }

    for attempt in range(2):
        request_payload = payload
        if attempt:
            request_payload = {
                **payload,
                "prompt": payload["prompt"] + (
                    " The previous response was invalid. Recheck the image and return "
                    "only one JSON object with a non-negative integer count; no reasoning or markdown."
                ),
            }
        try:
            result = _request_ollama(request_payload, timeout or OLLAMA_TIMEOUT)
            count = result.get("count") if isinstance(result, dict) else None
            if isinstance(count, bool) or not isinstance(count, int) or not 0 <= count <= 10000:
                raise InvalidVisionResponseError(
                    "包裝數量複核未回傳有效整數"
                )
            return count
        except InvalidVisionResponseError as error:
            if attempt == 0:
                continue
            raise VisionModelError(f"包裝數量複核格式修正重試後失敗：{error}") from error


def _is_milk_variant(item_name):
    name = str(item_name).casefold()
    return any(marker in name for marker in ("milk", "奶", "乳"))


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


def _analysis_profile(profile):
    if profile == "fast":
        return {
            "model": get_preferred_fast_vision_model(),
            "timeout": FAST_OLLAMA_TIMEOUT,
            "max_passes": FAST_ANALYSIS_PASSES,
            "ocr_enabled": False,
        }
    if profile == "strict":
        return {
            "model": get_preferred_vision_model(),
            "timeout": OLLAMA_TIMEOUT,
            "max_passes": MAX_ANALYSIS_PASSES,
            "ocr_enabled": OCR_ENABLED,
        }
    raise ValueError("辨識模式必須是 fast 或 strict")


def analyze_batch(prepared_images, profile="strict"):
    config = _analysis_profile(profile)
    with _analysis_lock:
        original_images = [
            image.get("analysis_original", image["original"])
            for image in prepared_images
        ]
        standard_images = [image["standard"] for image in prepared_images]
        enhanced_images = [image["enhanced"] for image in prepared_images]
        shadow_images = [image.get("shadow_boost", image["standard"]) for image in prepared_images]
        variants = (standard_images, enhanced_images, shadow_images)
        passes = []
        errors = []
        ocr_hints = []

        if config["ocr_enabled"]:
            for index, image_bytes in enumerate(original_images, start=1):
                try:
                    for hint in extract_text_hints(image_bytes):
                        hint["image_index"] = index
                        ocr_hints.append(hint)
                except (ImportError, OSError, RuntimeError, ValueError) as error:
                    errors.append(f"OCR 第 {index} 張照片失敗：{error}")

        for pass_index in range(config["max_passes"]):
            try:
                passes.append(analyze_with_ollama(
                    variants[pass_index],
                    pass_index,
                    ocr_hints,
                    vision_model=config["model"],
                    timeout=config["timeout"],
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
                if config["max_passes"] == 2 or (agreed and (is_empty or high_conf)):
                    break

        if len(passes) < 2:
            raise VisionModelError("；".join(dict.fromkeys(errors)) or "模型未完成足夠的獨立辨識")

        items, uncertain = reconcile_analyses(
            passes,
            minimum_confidence=MIN_RESULT_CONFIDENCE,
        )
        items = refine_item_names_from_ocr(items, ocr_hints)
        uncertain = refine_item_names_from_ocr(uncertain, ocr_hints)
        if len(items) == 1 and items[0]["name"] in PACKAGE_COUNT_LABELS:
            item = items[0]
            count_checks = []
            try:
                for image_variant in (standard_images, enhanced_images):
                    count_checks.append(count_item_packages(
                        image_variant,
                        item["name"],
                        vision_model=config["model"],
                    ))
            except VisionModelError as error:
                errors.append(f"物資數量複核失敗：{error}")
            else:
                if count_checks[0] == count_checks[1] and count_checks[0] > 0:
                    item["quantity"] = count_checks[0]
                    item["unit"] = "件"
                    item["model_observed_quantities"] = item.get("observed_quantities", [])
                    item["observed_quantities"] = count_checks
                    item["count_verified"] = True
                    item["count_observations"] = count_checks
                    if item["name"] == "牛奶":
                        uncertain = [
                            candidate for candidate in uncertain
                            if not _is_milk_variant(candidate["name"])
                        ]
                else:
                    items = []
                    item["observed_quantities"] = count_checks
                    item["reason"] = "獨立物件計數結果不一致，請人工核對照片"
                    uncertain.append(item)

        for entry in items + uncertain:
            suggested_storage, suggested_package = suggest_donation_fields(
                entry.get("name"),
                entry.get("unit"),
                entry.get("storage"),
                entry.get("package_status"),
            )
            entry["storage"] = suggested_storage
            entry["package_status"] = suggested_package

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
        "fast_model": get_preferred_fast_vision_model(),
        "max_images": MAX_IMAGES,
        "max_passes": MAX_ANALYSIS_PASSES,
        "minimum_confidence": MIN_RESULT_CONFIDENCE,
        "ocr_enabled": OCR_ENABLED,
        "fast_max_passes": FAST_ANALYSIS_PASSES,
        "fast_ocr_enabled": False,
        "requires_unanimous_agreement": True,
        "requires_human_confirmation": True,
    }


@app.post("/analyze")
async def analyze(files: list[UploadFile] = File(...), profile: str = "strict"):
    profile = profile.strip().lower()
    if profile not in {"fast", "strict"}:
        return JSONResponse(
            status_code=400,
            content={"error": "辨識模式必須是 fast 或 strict"},
        )

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

    model_name = _analysis_profile(profile)["model"]
    try:
        items, uncertain, pass_count, model_errors = await run_in_threadpool(
            analyze_batch,
            prepared_images,
            profile,
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
        "profile": profile,
        "model": model_name,
        "passes": pass_count,
        "max_passes": _analysis_profile(profile)["max_passes"],
        "minimum_confidence": MIN_RESULT_CONFIDENCE,
        "requires_unanimous_agreement": True,
        "requires_human_confirmation": True,
        "image_count": len(prepared_images),
        "errors": image_errors + model_errors,
    })
