import math
import re
import unicodedata


MAX_QUANTITY = 10000

NAME_ALIASES = {
    "egg": "雞蛋",
    "eggs": "雞蛋",
    "蛋": "雞蛋",
    "生蛋": "雞蛋",
    "新鮮雞蛋": "雞蛋",
    "雞卵": "雞蛋",
    "milk": "牛奶",
    "鮮乳": "牛奶",
    "鮮奶": "牛奶",
    "牛乳": "牛奶",
    "牛奶盒": "牛奶",
    "利樂包牛奶": "牛奶",
    "罐頭食品": "罐頭",
    "rice": "白米",
    "白飯": "白米",
    "米": "白米",
}

UNIT_ALIASES = {
    "個": "件",
    "个": "件",
    "piece": "件",
    "pieces": "件",
    "can": "罐",
    "cans": "罐",
    "bottle": "瓶",
    "bottles": "瓶",
    "box": "盒",
    "boxes": "盒",
    "carton": "盒",
    "cartons": "盒",
    "pack": "包",
    "packs": "包",
    "袋": "包",
    "bag": "包",
    "bags": "包",
    "cartons": "盒",
    "egg": "顆",
    "eggs": "顆",
}

ALLOWED_UNITS = {"件", "包", "袋", "箱", "瓶", "盒", "份", "罐", "顆", "組"}


def _clean_text(value, limit=80):
    text = unicodedata.normalize("NFKC", str(value or "")).strip()
    text = re.sub(r"[\x00-\x1f\x7f]", "", text)
    return text if text and len(text) <= limit else ""


def normalize_name(value):
    name = _clean_text(value)
    if not name:
        return ""
    lookup = name.casefold()
    return NAME_ALIASES.get(lookup, NAME_ALIASES.get(name, name))


def normalize_unit(value):
    unit = _clean_text(value, 20) or "件"
    normalized = UNIT_ALIASES.get(unit.casefold(), UNIT_ALIASES.get(unit, unit))
    return normalized if normalized in ALLOWED_UNITS else ""


def normalize_quantity(value):
    if isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    if not math.isfinite(number) or number < 1 or number > MAX_QUANTITY:
        return None
    rounded = round(number)
    return int(rounded) if abs(number - rounded) < 0.0001 else None


def normalize_confidence(value):
    try:
        confidence = float(value)
    except (TypeError, ValueError):
        return 0.0
    if not math.isfinite(confidence):
        return 0.0
    return max(0.0, min(1.0, confidence))


def refine_item_names_from_ocr(items, hints):
    grouped_hints = {}
    for hint in hints:
        if not isinstance(hint, dict):
            continue
        text = _clean_text(hint.get("text"), 80)
        confidence = normalize_confidence(hint.get("confidence"))
        if len(text) < 2 or confidence < 0.6:
            continue
        if not any("\u4e00" <= character <= "\u9fff" for character in text):
            continue
        grouped_hints.setdefault(hint.get("image_index"), []).append((text, confidence))

    candidates = []
    for values in grouped_hints.values():
        for start in range(len(values)):
            for size in (3, 2):
                subset = values[start:start + size]
                if len(subset) != size:
                    continue
                name = "".join(text for text, _ in subset)
                if len(name) <= 16:
                    candidates.append({
                        "name": name,
                        "confidence": sum(score for _, score in subset) / len(subset),
                    })
    candidates.sort(key=lambda candidate: (-len(candidate["name"]), -candidate["confidence"]))

    refined_items = []
    for item in items:
        refined = dict(item)
        current_name = normalize_name(item.get("name"))
        for candidate in candidates:
            if current_name in candidate["name"] and len(candidate["name"]) > len(current_name):
                refined["name"] = candidate["name"]
                refined["ocr_name_confidence"] = round(candidate["confidence"], 3)
                break
        refined_items.append(refined)

    merged = {}
    for item in refined_items:
        key = (item.get("name"), item.get("unit"))
        existing = merged.get(key)
        rank = (
            normalize_confidence(item.get("confidence")),
            normalize_confidence(item.get("agreement")),
            normalize_quantity(item.get("quantity")) or 0,
        )
        existing_rank = (
            normalize_confidence(existing.get("confidence")) if existing else -1.0,
            normalize_confidence(existing.get("agreement")) if existing else -1.0,
            normalize_quantity(existing.get("quantity")) if existing else 0,
        )
        if existing is None or rank > existing_rank:
            merged[key] = item
    return list(merged.values())


def normalize_analysis(payload):
    if not isinstance(payload, dict) or not isinstance(payload.get("items"), list):
        raise ValueError("模型回覆缺少 items 陣列")

    if payload.get("contains_donatable_food") is False:
        return {
            "items": [],
            "confidence": normalize_confidence(payload.get("confidence")),
        }

    grouped = {}
    for raw_item in payload["items"]:
        if not isinstance(raw_item, dict):
            continue
        name = normalize_name(raw_item.get("name"))
        unit = normalize_unit(raw_item.get("unit"))
        quantity = normalize_quantity(raw_item.get("quantity"))
        if not name or not unit or quantity is None:
            continue
        key = (name, unit)
        grouped[key] = grouped.get(key, 0) + quantity

    items = [
        {"name": name, "quantity": quantity, "unit": unit}
        for (name, unit), quantity in grouped.items()
        if quantity <= MAX_QUANTITY
    ]
    items.sort(key=lambda item: (-item["quantity"], item["name"], item["unit"]))
    return {
        "items": items,
        "confidence": normalize_confidence(payload.get("confidence")),
    }


def analysis_signature(analysis):
    return tuple(
        (item["name"], item["quantity"], item["unit"])
        for item in analysis.get("items", [])
    )


def analyses_agree(first, second):
    return analysis_signature(first) == analysis_signature(second)


def reconcile_analyses(analyses, minimum_confidence=0.95):
    if not analyses:
        return [], []

    normalized = [normalize_analysis(analysis) for analysis in analyses]
    all_keys = set()
    quantities_by_pass = []
    for analysis in normalized:
        quantities = {
            (item["name"], item["unit"]): item["quantity"]
            for item in analysis["items"]
        }
        quantities_by_pass.append(quantities)
        all_keys.update(quantities)

    accepted = []
    uncertain = []
    minimum_votes = len(normalized)

    for name, unit in sorted(all_keys):
        observations = [quantities.get((name, unit), 0) for quantities in quantities_by_pass]
        votes = {}
        for quantity in observations:
            if quantity > 0:
                votes[quantity] = votes.get(quantity, 0) + 1

        selected_quantity = None
        support = 0
        if votes:
            ordered_votes = sorted(votes.items(), key=lambda entry: (-entry[1], -entry[0]))
            selected_quantity, support = ordered_votes[0]

        agreement = support / len(normalized)
        matching_confidences = [
            analysis["confidence"]
            for analysis, quantity in zip(normalized, observations)
            if quantity == selected_quantity and quantity > 0
        ]
        model_confidence = (
            sum(matching_confidences) / len(matching_confidences)
            if matching_confidences
            else 0.0
        )
        confidence = normalize_confidence(0.45 * agreement + 0.55 * model_confidence)
        item = {
            "name": name,
            "quantity": selected_quantity or 0,
            "unit": unit,
            "confidence": round(confidence, 3),
            "agreement": round(agreement, 3),
            "observed_quantities": observations,
        }

        if selected_quantity is not None and support >= minimum_votes and confidence >= minimum_confidence:
            accepted.append(item)
        else:
            if support < minimum_votes:
                item["reason"] = "多輪辨識的名稱或數量沒有達成足夠共識"
            else:
                item["reason"] = "辨識一致性或模型信心未達可信門檻"
            uncertain.append(item)

    accepted.sort(key=lambda item: (-item["quantity"], item["name"], item["unit"]))
    uncertain.sort(key=lambda item: (-item["confidence"], item["name"], item["unit"]))
    return accepted, uncertain
