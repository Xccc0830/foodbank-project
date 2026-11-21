import io
import os
import unicodedata

import numpy as np
from PIL import Image


MIN_CONFIDENCE = float(os.getenv("FOODBANK_OCR_MIN_CONFIDENCE", "0.6"))
MAX_TEXT_LENGTH = 500
_ocr = None


def get_ocr():
    global _ocr
    if _ocr is None:
        os.environ.setdefault("PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK", "True")
        from paddleocr import PaddleOCR

        _ocr = PaddleOCR(
            lang="ch",
            device="cpu",
            enable_mkldnn=False,
            use_doc_orientation_classify=False,
            use_doc_unwarping=False,
            use_textline_orientation=False,
        )
    return _ocr


def extract_text_hints(image_bytes):
    with Image.open(io.BytesIO(image_bytes)) as source:
        image = np.asarray(source.convert("RGB"))

    hints = []
    length = 0
    for page in get_ocr().predict(image):
        texts = page.get("rec_texts") or []
        scores = page.get("rec_scores") or []
        for text, score in zip(texts, scores):
            clean = unicodedata.normalize("NFKC", str(text or "")).strip()
            if not clean or float(score) < MIN_CONFIDENCE:
                continue
            if any(character.isalnum() or "\u4e00" <= character <= "\u9fff" for character in clean):
                length += len(clean)
                if length > MAX_TEXT_LENGTH:
                    break
                hints.append({
                    "text": clean[:80],
                    "confidence": round(float(score), 3),
                })
        if length > MAX_TEXT_LENGTH:
            break

    return hints
