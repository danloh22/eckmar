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

    def test_persisted_offer_rejects_duplicate_quantity_before_mutation(self):
        start = self.request_source.index(
            "if ($editingProduct && $editingProduct->exists) {"
        )
        end = self.request_source.index("// offer for new product", start)
        persisted_branch = self.request_source[start:end]

        duplicate_check = (
            "$editingProduct->offers()->where('min_quantity', $minQuantity)->exists()"
        )
        self.assertIn(duplicate_check, persisted_branch)
        self.assertLess(
            persisted_branch.index(duplicate_check),
            persisted_branch.index("$newOffer = new Offer();"),
        )
        self.assertIn(
            "There is already offer like that! Please remove old offer to add new one.",
            persisted_branch,
        )


    def test_featured_removal_request_uses_products_permission_gate(self):
        source = (
            self.root
            / "app/Http/Requests/Admin/RemoveProductFromFeaturedReuqest.php"
        ).read_text(encoding="utf-8")
        start = source.index("public function authorize()")
        end = source.index("public function rules()", start)
        authorize_source = source[start:end]

        self.assertIn("use Illuminate\\Support\\Facades\\Gate;", source)
        self.assertIn(
            "return Gate::allows('has-access', 'products');",
            authorize_source,
        )
        self.assertNotIn("isAdmin()", authorize_source)

if __name__ == "__main__":
    unittest.main()
