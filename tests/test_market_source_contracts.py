"""Static regression contracts for marketplace source that cannot run without legacy PHP."""

from pathlib import Path
import re
import unittest


class OfferPersistenceSourceContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parents[1]
        cls.request_source = (
            cls.root / "app/Http/Requests/Product/NewOfferRequest.php"
        ).read_text(encoding="utf-8")
        cls.phpunit_source = (
            cls.root / "tests/Unit/ConvertedOfferPriceTest.php"
        ).read_text(encoding="utf-8")
        cls.controller_source = (
            cls.root / "app/Http/Controllers/VendorController.php"
        ).read_text(encoding="utf-8")

    def test_persisted_product_branch_uses_eloquent_exists_property(self):
        self.assertIn(
            "if ($editingProduct && $editingProduct->exists) {",
            self.request_source,
        )
        self.assertIsNone(
            re.search(r"\$editingProduct\s*->\s*exists\s*\(", self.request_source)
        )

    def test_phpunit_fixture_marks_product_as_persisted(self):
        self.assertIn("$product->exists = true;", self.phpunit_source)
        self.assertIsNone(
            re.search(r"shouldReceive\(['\"]exists['\"]\)", self.phpunit_source)
        )


    def test_offer_removal_uses_bound_product_model_state(self):
        start = self.controller_source.index("public function removeOffer")
        end = self.controller_source.index("public function addDeliveryShow", start)
        remove_offer_source = self.controller_source[start:end]

        self.assertIn("if ($product && $product->exists) {", remove_offer_source)
        self.assertIsNone(
            re.search(r"\$product\s*->\s*exists\s*\(", remove_offer_source)
        )

if __name__ == "__main__":
    unittest.main()
