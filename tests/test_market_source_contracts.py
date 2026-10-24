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


    def test_persisted_offer_removal_validates_target_and_remaining_tier(self):
        start = self.controller_source.index("public function removeOffer")
        end = self.controller_source.index("public function addDeliveryShow", start)
        remove_offer_source = self.controller_source[start:end]

        target = "$offersToRemove = $product->offers()->where('min_quantity', $quantity);"
        missing_guard = "if (!$offersToRemove->exists())"
        remaining_guard = (
            "if (!$product->offers()->where('min_quantity', '!=', $quantity)->exists())"
        )
        mutation = "$offersToRemove->update(['deleted' => 1]);"

        for contract in (target, missing_guard, remaining_guard, mutation):
            self.assertIn(contract, remove_offer_source)
        self.assertLess(remove_offer_source.index(target), remove_offer_source.index(missing_guard))
        self.assertLess(
            remove_offer_source.index(missing_guard),
            remove_offer_source.index(remaining_guard),
        )
        self.assertLess(
            remove_offer_source.index(remaining_guard),
            remove_offer_source.index(mutation),
        )
        self.assertIn("There is no offer for this quantity!", remove_offer_source)
        self.assertIn("You must have at least one offer!", remove_offer_source)

    def test_empty_checkout_is_rejected_before_transaction(self):
        source = (
            self.root / "app/Http/Requests/Cart/MakePurchasesRequest.php"
        ).read_text(encoding="utf-8")
        start = source.index("public function persist(")
        persist_source = source[start:]

        items = "$items = Cart::getCart()->items();"
        empty_guard = "if (empty($items))"
        transaction = "DB::transaction("
        message = "Add at least one product before opening checkout."

        for contract in (items, empty_guard, transaction, message):
            self.assertIn(contract, persist_source)
        self.assertLess(persist_source.index(items), persist_source.index(empty_guard))
        self.assertLess(
            persist_source.index(empty_guard),
            persist_source.index(transaction),
        )

    def test_profile_purchase_views_authorize_before_rendering(self):
        source = (
            self.root / "app/Http/Controllers/ProfileController.php"
        ).read_text(encoding="utf-8")
        contracts = (
            (
                "public function purchaseMessage(Purchase $purchase)",
                "public function purchase(Purchase $purchase)",
                "return view('profile.purchases.viewmessage'",
            ),
            (
                "public function purchase(Purchase $purchase)",
                "public function deliveredConfirm(Purchase $purchase)",
                "return view('profile.purchases.purchase'",
            ),
        )

        for start_marker, end_marker, view in contracts:
            with self.subTest(method=start_marker):
                start = source.index(start_marker)
                end = source.index(end_marker, start + len(start_marker))
                method_source = source[start:end]
                guard = "abort_unless($purchase->isAllowed(), 403);"

                self.assertIn(guard, method_source)
                self.assertIn(view, method_source)
                self.assertLess(method_source.index(guard), method_source.index(view))

    def test_profile_ticket_reads_and_replies_require_owner(self):
        source = (
            self.root / "app/Http/Controllers/ProfileController.php"
        ).read_text(encoding="utf-8")
        guard = (
            "abort_unless((string) $ticket->user_id === "
            "(string) auth()->id(), 403);"
        )

        tickets_start = source.index("public function tickets(Ticket $ticket = null)")
        tickets_end = source.index("public function newTicket(", tickets_start)
        tickets_source = source[tickets_start:tickets_end]
        self.assertIn(guard, tickets_source)
        self.assertIn("$ticket -> replies()", tickets_source)
        self.assertLess(tickets_source.index(guard), tickets_source.index("$ticket -> replies()"))

        reply_start = source.index("public function newTicketMessage(")
        reply_source = source[reply_start:]
        self.assertIn(guard, reply_source)
        self.assertIn("TicketReply::postReply($ticket", reply_source)
        self.assertLess(
            reply_source.index(guard),
            reply_source.index("TicketReply::postReply($ticket"),
        )

    def test_ticket_title_validation_matches_database_string_limit(self):
        request_source = (
            self.root / "app/Http/Requests/Profile/NewTicketRequest.php"
        ).read_text(encoding="utf-8")
        migration_source = (
            self.root
            / "database/migrations/2019_02_16_185709_create_tickets_table.php"
        ).read_text(encoding="utf-8")

        self.assertIn("'title' => 'required|string|max:255'", request_source)
        self.assertIn("$table -> string('title');", migration_source)

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

    def test_dispute_notification_routing_uses_present_scalar_identifiers(self):
        listeners = (
            "ProductDisputedNotification.php",
            "ProductDisputeNewMessageNotification.php",
        )

        for listener in listeners:
            with self.subTest(listener=listener):
                source = (
                    self.root / "app/Listeners/Purchase" / listener
                ).read_text(encoding="utf-8")

                self.assertEqual(3, source.count("samePresentUserIdentifier("))
                self.assertIn("is_scalar($leftId) && is_scalar($rightId)", source)
                self.assertIn("(string) $leftId !== ''", source)
                self.assertIn("(string) $rightId !== ''", source)
                self.assertIn("(string) $leftId === (string) $rightId", source)
                self.assertNotRegex(source, r"\$event->initiator\s*==\s*\$event->")

    def test_admin_dispute_message_notifies_both_participants(self):
        listener = (
            self.root
            / "app/Listeners/Purchase/ProductDisputeNewMessageNotification.php"
        ).read_text(encoding="utf-8")

        self.assertIn("$initiatedByAdmin = !$initiatedByVendor && !$initiatedByBuyer && $event->initiator->isAdmin()", listener)
        self.assertIn("$initiatedByVendor || $initiatedByAdmin", listener)
        self.assertIn("$initiatedByBuyer || $initiatedByAdmin", listener)
        self.assertIn("testAdministratorDisputeMessageNotifiesBothParticipants", (
            self.root / "tests/Unit/ProductDisputeNewMessageNotificationTest.php"
        ).read_text(encoding="utf-8"))

if __name__ == "__main__":
    unittest.main()
