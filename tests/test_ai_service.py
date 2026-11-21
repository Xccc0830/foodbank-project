import unittest

from ai_service.analysis import (
    analyses_agree,
    normalize_analysis,
    reconcile_analyses,
    refine_item_names_from_ocr,
)


def result(items, confidence=0.9):
    return {"items": items, "confidence": confidence}


class ImageAnalysisTests(unittest.TestCase):
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


if __name__ == "__main__":
    unittest.main()
