import io
import json
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

from ai_service import app
from ai_service.analysis import (
    analyses_agree,
    normalize_analysis,
    reconcile_analyses,
    refine_item_names_from_ocr,
)


def result(items, confidence=0.9):
    return {"items": items, "confidence": confidence}


class ImageAnalysisTests(unittest.TestCase):
    def test_prepare_image_removes_a_detected_mirror_reflection(self):
        rng = np.random.default_rng(42)
        pixels = rng.integers(30, 230, size=(192, 256, 3), dtype=np.uint8)
        boundary = 154
        reflected_height = pixels.shape[0] - boundary
        pixels[boundary:] = (
            pixels[boundary - reflected_height:boundary][::-1].astype(np.float32) * 0.65
            + 35
        ).clip(0, 255).astype(np.uint8)
        image_buffer = io.BytesIO()
        Image.fromarray(pixels).save(image_buffer, format="PNG")

        prepared = app.prepare_image(image_buffer.getvalue())

        self.assertLess(prepared["height"], pixels.shape[0])
        self.assertGreater(prepared["height"], pixels.shape[0] * 0.7)
        with Image.open(io.BytesIO(prepared["analysis_original"])) as cleaned:
            self.assertEqual(cleaned.height, prepared["height"])

    def test_prepare_image_keeps_a_photo_without_a_mirror_reflection(self):
        rng = np.random.default_rng(7)
        pixels = rng.integers(30, 230, size=(240, 320, 3), dtype=np.uint8)
        image_buffer = io.BytesIO()
        Image.fromarray(pixels).save(image_buffer, format="PNG")

        prepared = app.prepare_image(image_buffer.getvalue())

        self.assertEqual(prepared["height"], pixels.shape[0])

    def test_independent_package_count_corrects_a_single_item_suggestion(self):
        analyses = [
            result([{"name": "牛奶", "quantity": 7, "unit": "盒"}], 0.99),
            result([
                {"name": "牛奶", "quantity": 7, "unit": "盒"},
                {"name": "Whole Milk", "quantity": 1, "unit": "瓶"},
                {"name": "燕麥片", "quantity": 2, "unit": "盒"},
            ], 0.99),
        ]
        prepared = [{
            "original": b"original",
            "standard": b"standard",
            "enhanced": b"enhanced",
        }]
        with patch.object(app, "analyze_with_ollama", side_effect=analyses), \
                patch.object(app, "count_item_packages", side_effect=[8, 8]), \
                patch.object(app, "get_preferred_fast_vision_model", return_value="gemma3:test"):
            accepted, uncertain, _, _ = app.analyze_batch(prepared, profile="fast")

        self.assertEqual(len(accepted), 1)
        self.assertEqual(accepted[0]["quantity"], 8)
        self.assertEqual(accepted[0]["unit"], "件")
        self.assertEqual(accepted[0]["observed_quantities"], [8, 8])
        self.assertEqual(accepted[0]["model_observed_quantities"], [7, 7])
        self.assertTrue(accepted[0]["count_verified"])
        self.assertEqual(accepted[0]["count_observations"], [8, 8])
        self.assertEqual([item["name"] for item in uncertain], ["燕麥片"])

    def test_disagreeing_package_count_moves_suggestion_to_manual_review(self):
        analyses = [
            result([{"name": "牛奶", "quantity": 7, "unit": "盒"}], 0.99),
            result([{"name": "牛奶", "quantity": 7, "unit": "盒"}], 0.99),
        ]
        prepared = [{
            "original": b"original",
            "standard": b"standard",
            "enhanced": b"enhanced",
        }]
        with patch.object(app, "analyze_with_ollama", side_effect=analyses), \
                patch.object(app, "count_item_packages", side_effect=[8, 7]), \
                patch.object(app, "get_preferred_fast_vision_model", return_value="gemma3:test"):
            accepted, uncertain, _, _ = app.analyze_batch(prepared, profile="fast")

        self.assertEqual(accepted, [])
        self.assertEqual(uncertain[0]["observed_quantities"], [8, 7])
        self.assertIn("獨立物件計數結果不一致", uncertain[0]["reason"])

    def test_normalizes_common_names_units_and_duplicate_lines(self):
        normalized = normalize_analysis(result([
            {"name": "蛋", "quantity": 5, "unit": "eggs"},
            {"name": "雞蛋", "quantity": 7, "unit": "顆"},
            {"name": "鮮乳", "quantity": "8.0", "unit": "carton"},
        ]))

        self.assertEqual(normalized["items"], [
            {"name": "雞蛋", "quantity": 12, "unit": "顆"},
            {"name": "牛奶", "quantity": 8, "unit": "盒"},
        ])

    def test_accepts_two_unanimous_passes(self):
        first = result([
            {"name": "蛋", "quantity": 12, "unit": "顆"},
        ], 0.88)
        second = result([
            {"name": "雞蛋", "quantity": 12, "unit": "顆"},
        ], 0.92)

        accepted, uncertain = reconcile_analyses([first, second], 0.75)

        self.assertTrue(analyses_agree(normalize_analysis(first), normalize_analysis(second)))
        self.assertEqual(len(accepted), 1)
        self.assertEqual(accepted[0]["quantity"], 12)
        self.assertEqual(accepted[0]["agreement"], 1.0)
        self.assertEqual(uncertain, [])

    def test_rejects_quantity_disagreement_from_two_passes(self):
        first = result([{"name": "牛奶", "quantity": 8, "unit": "盒"}])
        second = result([{"name": "牛奶", "quantity": 7, "unit": "盒"}])

        accepted, uncertain = reconcile_analyses([first, second], 0.75)

        self.assertEqual(accepted, [])
        self.assertEqual(len(uncertain), 1)
        self.assertEqual(uncertain[0]["observed_quantities"], [8, 7])
        self.assertIn("沒有達成足夠共識", uncertain[0]["reason"])

    def test_rejects_majority_when_any_tiebreaker_pass_disagrees(self):
        analyses = [
            result([{"name": "罐頭", "quantity": 8, "unit": "罐"}], 0.95),
            result([{"name": "罐頭", "quantity": 7, "unit": "罐"}], 0.94),
            result([{"name": "罐頭", "quantity": 8, "unit": "罐"}], 0.93),
        ]

        accepted, uncertain = reconcile_analyses(analyses, 0.75)

        self.assertEqual(accepted, [])
        self.assertEqual(len(uncertain), 1)
        self.assertEqual(uncertain[0]["agreement"], 0.667)

    def test_accepts_three_unanimous_high_confidence_passes(self):
        analyses = [
            result([{"name": "罐頭", "quantity": 8, "unit": "罐"}], 0.97),
            result([{"name": "罐頭", "quantity": 8, "unit": "罐"}], 0.98),
            result([{"name": "罐頭", "quantity": 8, "unit": "罐"}], 0.96),
        ]

        accepted, uncertain = reconcile_analyses(analyses, 0.95)

        self.assertEqual(accepted[0]["quantity"], 8)
        self.assertEqual(accepted[0]["agreement"], 1.0)
        self.assertGreaterEqual(accepted[0]["confidence"], 0.95)
        self.assertEqual(uncertain, [])

    def test_drops_fractional_and_unrecognized_units(self):
        normalized = normalize_analysis(result([
            {"name": "麵粉", "quantity": 2.5, "unit": "包"},
            {"name": "麵粉", "quantity": 2, "unit": "公斤"},
            {"name": "", "quantity": 3, "unit": "包"},
        ]))

        self.assertEqual(normalized["items"], [])

    def test_missing_item_in_one_pass_is_not_auto_accepted(self):
        analyses = [
            result([{"name": "瓶裝水", "quantity": 6, "unit": "瓶"}], 0.99),
            result([], 0.99),
        ]

        accepted, uncertain = reconcile_analyses(analyses, 0.75)

        self.assertEqual(accepted, [])
        self.assertEqual(uncertain[0]["observed_quantities"], [6, 0])

    def test_rejects_non_food_scene_without_name_blacklist(self):
        normalized = normalize_analysis({
            "contains_donatable_food": False,
            "items": [
                {"name": "速度表", "quantity": 1, "unit": "件"},
                {"name": "儀錶盤", "quantity": 1, "unit": "件"},
            ],
            "confidence": 0.99,
        })

        self.assertEqual(normalized["items"], [])

    def test_refines_partial_name_from_high_confidence_package_text(self):
        items = [{"name": "粉圓", "quantity": 1, "unit": "包"}]
        hints = [
            {"text": "黑珍珠", "confidence": 0.94, "image_index": 1},
            {"text": "粉圓", "confidence": 0.82, "image_index": 1},
        ]

        self.assertEqual(
            refine_item_names_from_ocr(items, hints)[0]["name"],
            "黑珍珠粉圓",
        )

    def test_treats_package_and_bag_as_same_counting_unit(self):
        normalized = normalize_analysis(result([
            {"name": "粉圓", "quantity": 1, "unit": "袋"},
            {"name": "粉圓", "quantity": 1, "unit": "包"},
        ]))

        self.assertEqual(normalized["items"][0]["quantity"], 2)
        self.assertEqual(normalized["items"][0]["unit"], "包")

    def test_does_not_duplicate_items_after_ocr_name_refinement(self):
        items = [
            {"name": "粉圓", "quantity": 1, "unit": "包", "confidence": 0.9, "agreement": 0.67},
            {"name": "珍珠粉圓", "quantity": 1, "unit": "包", "confidence": 0.97, "agreement": 1.0},
        ]
        hints = [
            {"text": "黑珍珠", "confidence": 0.94, "image_index": 1},
            {"text": "粉圓", "confidence": 0.82, "image_index": 1},
        ]

        refined = refine_item_names_from_ocr(items, hints)

        self.assertEqual(len(refined), 1)
        self.assertEqual(refined[0]["name"], "黑珍珠粉圓")

    def test_fast_profile_uses_light_model_and_stops_after_two_passes(self):
        analyses = [
            result([{"name": "白米", "quantity": 1, "unit": "包"}], 0.96),
            result([{"name": "白米", "quantity": 2, "unit": "包"}], 0.96),
        ]
        with patch.object(app, "FAST_VISION_MODEL", "gemma3:test-fast"), \
                patch.object(app, "analyze_with_ollama", side_effect=analyses) as model:
            _, uncertain, passes, _ = app.analyze_batch([{
                "original": b"original",
                "standard": b"standard",
                "enhanced": b"enhanced",
            }], profile="fast")

        self.assertEqual(passes, 2)
        self.assertEqual(model.call_count, 2)
        self.assertEqual(
            {call.kwargs["vision_model"] for call in model.call_args_list},
            {"gemma3:test-fast"},
        )
        self.assertEqual(uncertain[0]["observed_quantities"], [1, 2])

    def test_fast_profile_falls_back_to_an_installed_vision_model(self):
        response = io.BytesIO(b'{"models":['
                              b'{"name":"gemma4:latest","capabilities":["vision"]},'
                              b'{"name":"llama3.1:8b","capabilities":["completion"]}'
                              b']}')
        with patch.object(app, "FAST_VISION_MODEL", "gemma3:4b"), \
                patch.object(app, "_detected_fast_model", None), \
                patch.object(app.urllib.request, "urlopen", return_value=response):
            profile = app._analysis_profile("fast")

        self.assertEqual(profile["model"], "gemma4:latest")

    def test_fast_profile_honors_an_explicit_model(self):
        with patch.dict("os.environ", {"FOODBANK_FAST_VISION_MODEL": "custom:vision"}), \
                patch.object(app.urllib.request, "urlopen") as urlopen:
            profile = app._analysis_profile("fast")

        self.assertEqual(profile["model"], "custom:vision")
        urlopen.assert_not_called()

    def test_ollama_http_error_keeps_the_model_failure_detail(self):
        http_error = app.urllib.error.HTTPError(
            "http://127.0.0.1:11434/api/generate",
            404,
            "Not Found",
            {},
            io.BytesIO(b'{"error":"model gemma3:4b not found"}'),
        )
        with patch.object(app.urllib.request, "urlopen", side_effect=http_error):
            with self.assertRaisesRegex(
                app.VisionModelError,
                "model gemma3:4b not found",
            ):
                app._request_ollama({}, 1)

    def test_analysis_prompt_contains_valid_json_example(self):
        example = app.BASE_PROMPT.split("回覆範例：", 1)[1].split("；", 1)[0]

        parsed = json.loads(example)

        self.assertIs(parsed["contains_donatable_food"], True)
        self.assertEqual(parsed["items"][0]["quantity"], 2)

    def test_invalid_ollama_json_is_classified_as_retryable(self):
        response = io.BytesIO(b'{"response":"{not valid json"}')
        with patch.object(app.urllib.request, "urlopen", return_value=response):
            with self.assertRaises(app.InvalidVisionResponseError):
                app._request_ollama({}, 1)

    def test_analysis_retries_once_when_model_returns_invalid_json(self):
        responses = [
            app.InvalidVisionResponseError("格式錯誤"),
            {
                "contains_donatable_food": True,
                "items": [{"name": "白米", "quantity": 2, "unit": "包"}],
                "confidence": 0.97,
            },
        ]
        with patch.object(app, "_request_ollama", side_effect=responses) as request:
            analysis = app.analyze_with_ollama(
                [b"prepared image"],
                0,
                [],
                vision_model="gemma4:test",
                timeout=20,
            )

        self.assertEqual(request.call_count, 2)
        first_payload = request.call_args_list[0].args[0]
        retry_payload = request.call_args_list[1].args[0]
        self.assertIs(first_payload["think"], False)
        self.assertIn("上一個回覆不是有效 JSON", retry_payload["prompt"])
        self.assertEqual(analysis["items"], [
            {"name": "白米", "quantity": 2, "unit": "包"},
        ])

    def test_analysis_retries_when_json_is_missing_required_fields(self):
        valid_response = {
            "contains_donatable_food": True,
            "items": [{"name": "白米", "quantity": 2, "unit": "包"}],
            "confidence": 0.97,
        }
        with patch.object(
            app,
            "_request_ollama",
            side_effect=[{"confidence": 0.97}, valid_response],
        ) as request:
            analysis = app.analyze_with_ollama(
                [b"prepared image"],
                0,
                [],
                vision_model="gemma4:test",
                timeout=20,
            )

        self.assertEqual(request.call_count, 2)
        self.assertEqual(analysis["items"][0]["name"], "白米")

    def test_package_count_disables_thinking_and_retries_bad_json(self):
        with patch.object(
            app,
            "_request_ollama",
            side_effect=[
                app.InvalidVisionResponseError("沒有有效 JSON"),
                {"count": 8},
            ],
        ) as request:
            count = app.count_item_packages(
                [b"prepared image"],
                "牛奶",
                vision_model="gemma4:test",
                timeout=20,
            )

        self.assertEqual(count, 8)
        self.assertEqual(request.call_count, 2)
        first_payload = request.call_args_list[0].args[0]
        retry_payload = request.call_args_list[1].args[0]
        self.assertIs(first_payload["think"], False)
        self.assertIn("previous response was invalid", retry_payload["prompt"])

    def test_strict_profile_keeps_third_audit_pass_on_disagreement(self):
        analyses = [
            result([{"name": "白米", "quantity": 1, "unit": "包"}], 0.96),
            result([{"name": "白米", "quantity": 2, "unit": "包"}], 0.96),
            result([{"name": "白米", "quantity": 1, "unit": "包"}], 0.96),
        ]
        with patch.object(app, "MAX_ANALYSIS_PASSES", 3), \
                patch.object(app, "OCR_ENABLED", False), \
                patch.object(app, "analyze_with_ollama", side_effect=analyses) as model:
            _, _, passes, _ = app.analyze_batch([{
                "original": b"original",
                "standard": b"standard",
                "enhanced": b"enhanced",
            }])

        self.assertEqual(passes, 3)
        self.assertEqual(model.call_count, 3)


    def test_refines_single_hint_ocr_package_text(self):
        items = [{"name": "鮮奶", "quantity": 2, "unit": "盒"}]
        hints = [{"text": "義美鮮奶", "confidence": 0.95, "image_index": 1}]

        refined = refine_item_names_from_ocr(items, hints)

        self.assertEqual(len(refined), 1)
        self.assertEqual(refined[0]["name"], "義美鮮奶")

    def test_normalizes_expanded_foodbank_aliases(self):
        normalized = normalize_analysis(result([
            {"name": "速食麵", "quantity": 5, "unit": "packet"},
            {"name": "鮪魚罐頭", "quantity": 3, "unit": "tin"},
            {"name": "沙拉油", "quantity": 2, "unit": "bottle"},
            {"name": "oatmeal", "quantity": 1, "unit": "case"},
        ]))

        self.assertEqual(normalized["items"], [
            {"name": "泡麵", "quantity": 5, "unit": "包"},
            {"name": "鮪魚罐頭", "quantity": 3, "unit": "罐"},
            {"name": "食用油", "quantity": 2, "unit": "瓶"},
            {"name": "燕麥片", "quantity": 1, "unit": "箱"},
        ])


if __name__ == "__main__":
    unittest.main()
