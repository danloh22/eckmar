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


if __name__ == "__main__":
    unittest.main()
